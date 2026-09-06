import {
  Injectable,
  UnauthorizedException,
  BadRequestException,
  NotFoundException,
} from '@nestjs/common';
import { JwtService } from '@nestjs/jwt';
import { ConfigService } from '@nestjs/config';
import * as bcrypt from 'bcrypt';
import { v4 as uuidv4 } from 'uuid';
import { UsersService } from '../users/users.service';
import { UserActionLogWriter } from '@app/common/audit/user-action-log.writer';
import { DatabaseService } from '@app/database';
import { EcommerceMailTemplate, MailClientService } from '@app/mail-client';
import { LoginDto } from './dto/login.dto';
import { ChangePasswordDto } from './dto/change-password.dto';
import { JwtPayload } from './strategies/jwt.strategy';

export interface AuthTokens {
  access_token: string;
  refresh_token: string;
  token_type: 'Bearer';
  expires_in: number;
}

/**
 * AuthService — Equivale a AuthService de Laravel + la lógica de
 * AuthController (login, refresh, logout, cambio de contraseña).
 *
 * Diferencias clave vs Sanctum:
 * - Tokens JWT stateless (acceso 1 h) + refresh token en DB (7 días)
 * - No usa cookies SPA; el cliente Angular debe guardar el access_token
 *   y enviar el refresh_token por body (no como bearer)
 */
@Injectable()
export class AuthService {
  constructor(
    private readonly usersService: UsersService,
    private readonly jwtService: JwtService,
    private readonly config: ConfigService,
    private readonly db: DatabaseService,
    private readonly actionLogWriter: UserActionLogWriter,
    private readonly mailClient: MailClientService,
  ) {}

  // ── Login ──────────────────────────────────────────────────────────────────

  async login(
    dto: LoginDto,
    ipAddress?: string | null,
  ): Promise<AuthTokens & { must_change_password: boolean }> {
    const user = await this.usersService.findByUsernameOrEmail(dto.username);

    if (!user) {
      // No revela si el usuario existe (SEC-013 equivalente)
      throw new UnauthorizedException('Credenciales incorrectas.');
    }

    if (!user.isEnabled) {
      throw new UnauthorizedException('Tu cuenta ha sido deshabilitada.');
    }

    const passwordValid = await bcrypt.compare(
      dto.password,
      this.normalizePasswordHash(user.passwordHash),
    );
    if (!passwordValid) {
      throw new UnauthorizedException('Credenciales incorrectas.');
    }

    // Obtener roles del usuario para incluirlos en el JWT payload
    const roles = user.userRoles.map((ur: { role: { name: string } }) => ur.role.name);
    const permissions = await this.usersService.getPermissionsForUser(user.id, roles);
    const tokens = await this.generateTokens(user, roles, permissions);

    void this.actionLogWriter.logSafely({
      action: 'auth.login',
      description: 'Inicio de sesión',
      metadata: { username: user.username },
      ipAddress: ipAddress ?? null,
      userId: user.id,
      tenantId: user.tenantId,
      warehouseId: user.warehouseId,
    });

    return {
      ...tokens,
      must_change_password: user.mustChangePassword,
    };
  }

  async issueTokensForUserId(userId: string): Promise<AuthTokens> {
    const user = await this.usersService.findById(userId);
    if (!user || !user.isEnabled) {
      throw new UnauthorizedException('Usuario no encontrado.');
    }

    const roles = user.userRoles.map((ur: { role: { name: string } }) => ur.role.name);
    const permissions = await this.usersService.getPermissionsForUser(user.id, roles);
    return this.generateTokens(user, roles, permissions);
  }

  // ── Refresh ────────────────────────────────────────────────────────────────

  async refresh(
    userId: string,
    tokenId: string,
  ): Promise<AuthTokens> {
    const user = await this.usersService.findById(userId);
    if (!user) throw new UnauthorizedException();

    // Rotar el refresh token: invalida el anterior, emite uno nuevo
    await this.db.refreshToken.delete({ where: { id: tokenId } });

    const roles = user.userRoles.map((ur: { role: { name: string } }) => ur.role.name);
    const permissions = await this.usersService.getPermissionsForUser(user.id, roles);
    return this.generateTokens(user, roles, permissions);
  }

  // ── Logout ────────────────────────────────────────────────────────────────

  async logout(userId: string, tokenId: string): Promise<void> {
    await this.db.refreshToken
      .deleteMany({ where: { userId, id: tokenId } })
      .catch(() => null);
  }

  async logoutAll(userId: string): Promise<void> {
    await this.db.refreshToken.deleteMany({ where: { userId } });
  }

  // ── Cambio de contraseña ──────────────────────────────────────────────────

  async changePassword(userId: string, dto: ChangePasswordDto): Promise<void> {
    const user = await this.usersService.findById(userId);
    if (!user) throw new NotFoundException('Usuario no encontrado.');

    const currentValid = await bcrypt.compare(
      dto.current_password,
      this.normalizePasswordHash(user.passwordHash),
    );
    if (!currentValid) {
      throw new BadRequestException('La contraseña actual es incorrecta.');
    }

    if (dto.current_password === dto.new_password) {
      throw new BadRequestException(
        'La nueva contraseña no puede ser igual a la actual.',
      );
    }

    const hash = await bcrypt.hash(dto.new_password, 12);
    await this.db.user.update({
      where: { id: userId },
      data: { passwordHash: hash, mustChangePassword: false },
    });

    // Invalidar todos los refresh tokens (force re-login)
    await this.logoutAll(userId);
  }

  // ── Forgot / Reset password ────────────────────────────────────────────────

  async forgotPassword(email: string): Promise<void> {
    const user = await this.usersService.findByEmail(email);
    // Siempre retorna 200 para no revelar si el email existe (SEC-013)
    if (!user) return;

    const token = uuidv4();
    const expiresAt = new Date(Date.now() + 60 * 60 * 1000); // 1 hora

    await this.db.passwordResetToken.upsert({
      where: { email },
      create: { email, token, expiresAt },
      update: { token, expiresAt },
    });

    const ecommerceCustomer = await this.db.ecommerceCustomer.findUnique({
      where: { email },
      select: { name: true },
    });
    const baseUrl = ecommerceCustomer
      ? this.config.get<string>(
          'ECOMMERCE_STORE_URL',
          this.config.get<string>('FRONTEND_URL', 'http://localhost:3001'),
        )
      : this.config.get<string>('FRONTEND_URL', 'http://localhost:4200');
    const resetUrl = `${baseUrl}/restablecer-contrasena?token=${token}`;

    void this.mailClient
      .sendEcommerceMail({
        template: EcommerceMailTemplate.CUSTOMER_PASSWORD_RESET,
        to: email,
        data: {
          customerName: ecommerceCustomer?.name ?? user.name,
          resetUrl,
          expiresInMinutes: 60,
        },
      })
      .catch(() => undefined);
  }

  async resetPassword(token: string, newPassword: string): Promise<void> {
    const record = await this.db.passwordResetToken.findUnique({
      where: { token },
    });

    if (!record || record.expiresAt < new Date()) {
      throw new BadRequestException('El token es inválido o ha expirado.');
    }

    const user = await this.usersService.findByEmail(record.email);
    if (!user) throw new NotFoundException('Usuario no encontrado.');

    const hash = await bcrypt.hash(newPassword, 12);
    await this.db.user.update({
      where: { id: user.id },
      data: { passwordHash: hash, mustChangePassword: false },
    });

    await this.db.passwordResetToken.delete({ where: { token } });
    await this.logoutAll(user.id);
  }

  // ── Validar refresh token en DB ───────────────────────────────────────────

  async validateRefreshToken(userId: string, tokenId: string): Promise<boolean> {
    const token = await this.db.refreshToken.findFirst({
      where: { id: tokenId, userId, expiresAt: { gt: new Date() } },
    });
    return !!token;
  }

  // ── Helper: generar par de tokens ─────────────────────────────────────────

  private async generateTokens(
    user: Awaited<ReturnType<UsersService['findById']>>,
    roles: string[],
    permissions: string[],
  ): Promise<AuthTokens> {
    if (!user) throw new UnauthorizedException();

    const accessPayload: JwtPayload = {
      sub: user.id,
      username: user.username,
      tenantId: user.tenantId,
      warehouseId: user.warehouseId ?? '',
      roles,
      permissions,
    };

    const accessExpiresIn = this.config.get<string>('JWT_EXPIRES_IN', '15m');
    const refreshExpiresIn = this.config.get<string>('JWT_REFRESH_EXPIRES_IN', '7d');

    const accessToken = this.jwtService.sign(accessPayload, {
      expiresIn: accessExpiresIn,
    });

    // El refresh token lleva solo sub + jti (no roles, no warehouse)
    const tokenId = uuidv4();
    const refreshToken = this.jwtService.sign(
      { sub: user.id, jti: tokenId },
      {
        secret: this.config.getOrThrow<string>('JWT_REFRESH_SECRET'),
        expiresIn: refreshExpiresIn,
      },
    );

    const expiresInMs = this.parseDuration(refreshExpiresIn);
    await this.db.refreshToken.create({
      data: {
        id: tokenId,
        userId: user.id,
        expiresAt: new Date(Date.now() + expiresInMs),
      },
    });

    return {
      access_token: accessToken,
      refresh_token: refreshToken,
      token_type: 'Bearer',
      expires_in: this.parseDuration(accessExpiresIn) / 1000,
    };
  }

  private parseDuration(duration: string): number {
    const match = duration.match(/^(\d+)([smhd])$/);
    if (!match) return 900_000;
    const value = parseInt(match[1]);
    const unit = match[2];
    const multipliers: Record<string, number> = {
      s: 1000,
      m: 60_000,
      h: 3_600_000,
      d: 86_400_000,
    };
    return value * (multipliers[unit] ?? 1000);
  }

  /** Laravel/PHP bcrypt usa prefijo $2y$; node-bcrypt solo compara $2a$/$2b$. */
  private normalizePasswordHash(hash: string): string {
    return hash.replace(/^\$2y\$/, '$2b$');
  }
}

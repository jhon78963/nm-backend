import { Injectable, UnauthorizedException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { UsersService } from '../../users/users.service';

export interface JwtPayload {
  sub: string;         // user UUID
  username: string;
  tenantId: string;
  warehouseId: string;
  roles: string[];
  permissions: string[];
  iat?: number;
  exp?: number;
}

/**
 * Estrategia JWT de acceso (token de corta duración, 1 h por defecto).
 * Equivale a la verificación de `auth:sanctum` + `access-api` ability de Sanctum.
 */
@Injectable()
export class JwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor(
    config: ConfigService,
    private readonly usersService: UsersService,
  ) {
    super({
      jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(),
      ignoreExpiration: false,
      secretOrKey: config.getOrThrow<string>('JWT_SECRET'),
    });
  }

  async validate(payload: JwtPayload) {
    const user = await this.usersService.findById(payload.sub);
    if (!user || user.isDeleted) {
      throw new UnauthorizedException('Token inválido.');
    }
    // `isEnabled` equivale al middleware EnsureUserIsEnabled de Laravel
    if (!user.isEnabled) {
      throw new UnauthorizedException('Tu cuenta ha sido deshabilitada.');
    }
    return {
      id: user.id,
      username: user.username,
      tenantId: user.tenantId,
      warehouseId: payload.warehouseId,
      roles: payload.roles,
      permissions: payload.permissions ?? [],
      mustChangePassword: user.mustChangePassword,
    };
  }
}

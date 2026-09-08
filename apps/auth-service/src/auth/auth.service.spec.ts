import { Test, TestingModule } from '@nestjs/testing';
import { JwtService } from '@nestjs/jwt';
import { ConfigService } from '@nestjs/config';
import { UnauthorizedException, BadRequestException } from '@nestjs/common';
import * as bcrypt from 'bcrypt';
import { AuthService } from './auth.service';
import { UsersService } from '../users/users.service';
import { DatabaseService } from '@app/database';
import { UserActionLogWriter } from '@app/common/audit/user-action-log.writer';
import { MailClientService } from '@app/mail-client';
import { faker } from '@faker-js/faker';

// ─── Factories ────────────────────────────────────────────────────────────────

function makeUser(overrides: Record<string, unknown> = {}) {
  return {
    id: faker.string.uuid(),
    username: faker.internet.username(),
    email: faker.internet.email(),
    passwordHash: bcrypt.hashSync('Password1!', 10),
    name: faker.person.firstName(),
    surname: faker.person.lastName(),
    tenantId: faker.string.uuid(),
    warehouseId: faker.string.uuid(),
    isEnabled: true,
    isDeleted: false,
    mustChangePassword: false,
    userRoles: [{ role: { name: 'Vendedora' } }],
    ...overrides,
  };
}

// ─── Mocks ────────────────────────────────────────────────────────────────────

const mockUsersService = {
  findByUsernameOrEmail: jest.fn(),
  findById: jest.fn(),
  findByEmail: jest.fn(),
  getPermissionsForUser: jest.fn().mockResolvedValue([]),
};

const mockActionLogWriter = {
  logSafely: jest.fn().mockResolvedValue(undefined),
};

const mockMailClient = {
  sendEcommerceMail: jest.fn().mockResolvedValue(undefined),
};

const mockJwtService = {
  sign: jest.fn().mockReturnValue('mocked.jwt.token'),
  verify: jest.fn(),
};

const mockConfigService = {
  get: jest.fn((key: string, fallback?: string) => {
    const values: Record<string, string> = {
      JWT_EXPIRES_IN: '15m',
      JWT_REFRESH_EXPIRES_IN: '7d',
      JWT_REFRESH_SECRET: 'test_refresh_secret_32chars_minimum',
    };
    return values[key] ?? fallback;
  }),
  getOrThrow: jest.fn((key: string) => {
    const values: Record<string, string> = {
      JWT_SECRET: 'test_jwt_secret_32chars_minimum!!',
      JWT_REFRESH_SECRET: 'test_refresh_secret_32chars_minimum',
    };
    if (!values[key]) throw new Error(`Missing env: ${key}`);
    return values[key];
  }),
};

const mockDb = {
  refreshToken: {
    create: jest.fn().mockResolvedValue({ id: 'token-uuid' }),
    delete: jest.fn().mockResolvedValue({}),
    deleteMany: jest.fn().mockResolvedValue({ count: 1 }),
    findFirst: jest.fn(),
  },
  user: {
    update: jest.fn(),
  },
  passwordResetToken: {
    upsert: jest.fn(),
    findUnique: jest.fn(),
    delete: jest.fn(),
  },
  ecommerceCustomer: {
    findUnique: jest.fn().mockResolvedValue(null),
  },
};

// ═══════════════════════════════════════════════════════════════════════════════
// SUITE: AuthService
// ═══════════════════════════════════════════════════════════════════════════════

describe('AuthService', () => {
  let service: AuthService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        AuthService,
        { provide: UsersService, useValue: mockUsersService },
        { provide: JwtService, useValue: mockJwtService },
        { provide: ConfigService, useValue: mockConfigService },
        { provide: DatabaseService, useValue: mockDb },
        { provide: UserActionLogWriter, useValue: mockActionLogWriter },
        { provide: MailClientService, useValue: mockMailClient },
      ],
    }).compile();

    service = module.get<AuthService>(AuthService);
    jest.clearAllMocks();
    // Restaurar mock de sign para devolver tokens distintos por llamada
    mockJwtService.sign
      .mockReturnValueOnce('access.token.mock')
      .mockReturnValueOnce('refresh.token.mock');
  });

  // ── login ──────────────────────────────────────────────────────────────────

  describe('login()', () => {
    it('retorna tokens cuando las credenciales son correctas', async () => {
      // Equivale a: AuthSecurityTest::test_login_successful
      const user = makeUser();
      mockUsersService.findByUsernameOrEmail.mockResolvedValue(user);

      const result = await service.login({
        username: user.username,
        password: 'Password1!',
      });

      expect(result).toMatchObject({
        access_token: expect.any(String),
        refresh_token: expect.any(String),
        token_type: 'Bearer',
        expires_in: expect.any(Number),
        must_change_password: false,
      });
      expect(mockDb.refreshToken.create).toHaveBeenCalledTimes(1);
    });

    it('lanza UnauthorizedException cuando el usuario no existe (SEC-013: no revela si existe)', async () => {
      // Equivale a: PasswordSecurityTest::test_login_does_not_reveal_user_existence
      mockUsersService.findByUsernameOrEmail.mockResolvedValue(null);

      await expect(
        service.login({ username: 'noexiste@test.com', password: 'Password1!' }),
      ).rejects.toThrow(UnauthorizedException);
    });

    it('lanza UnauthorizedException cuando la contraseña es incorrecta con el mismo mensaje que usuario inexistente', async () => {
      // SEC-013: mensaje genérico independientemente del motivo del fallo
      const user = makeUser();
      mockUsersService.findByUsernameOrEmail.mockResolvedValue(user);

      const error = await service
        .login({ username: user.username, password: 'WrongPassword1!' })
        .catch((e: UnauthorizedException) => e);

      expect(error).toBeInstanceOf(UnauthorizedException);
      expect((error as UnauthorizedException).message).toBe('Credenciales incorrectas.');
    });

    it('lanza UnauthorizedException cuando la cuenta está deshabilitada', async () => {
      // Equivale a: EnsureUserIsEnabled middleware test
      const user = makeUser({ isEnabled: false });
      mockUsersService.findByUsernameOrEmail.mockResolvedValue(user);

      await expect(
        service.login({ username: user.username, password: 'Password1!' }),
      ).rejects.toThrow(UnauthorizedException);
    });

    it('indica must_change_password: true cuando el usuario debe cambiar contraseña', async () => {
      const user = makeUser({ mustChangePassword: true });
      mockUsersService.findByUsernameOrEmail.mockResolvedValue(user);

      const result = await service.login({
        username: user.username,
        password: 'Password1!',
      });

      expect(result.must_change_password).toBe(true);
    });

    it('incluye roles del usuario en el JWT payload', async () => {
      const user = makeUser({
        userRoles: [
          { role: { name: 'Vendedora' } },
          { role: { name: 'Admin' } },
        ],
      });
      mockUsersService.findByUsernameOrEmail.mockResolvedValue(user);

      await service.login({ username: user.username, password: 'Password1!' });

      expect(mockJwtService.sign).toHaveBeenCalledWith(
        expect.objectContaining({ roles: ['Vendedora', 'Admin'] }),
        expect.any(Object),
      );
    });
  });

  // ── refresh ────────────────────────────────────────────────────────────────

  describe('refresh()', () => {
    it('rota el refresh token: elimina el anterior y crea uno nuevo', async () => {
      const user = makeUser();
      const tokenId = faker.string.uuid();
      mockUsersService.findById.mockResolvedValue(user);
      mockDb.refreshToken.delete.mockResolvedValue({});
      mockDb.refreshToken.create.mockResolvedValue({ id: 'new-token-id' });

      const result = await service.refresh(user.id, tokenId);

      expect(mockDb.refreshToken.delete).toHaveBeenCalledWith({
        where: { id: tokenId },
      });
      expect(mockDb.refreshToken.create).toHaveBeenCalledTimes(1);
      expect(result).toHaveProperty('access_token');
      expect(result).toHaveProperty('refresh_token');
    });

    it('lanza UnauthorizedException si el usuario no existe', async () => {
      mockUsersService.findById.mockResolvedValue(null);

      await expect(service.refresh('invalid-id', 'token-id')).rejects.toThrow(
        UnauthorizedException,
      );
    });
  });

  // ── logout ─────────────────────────────────────────────────────────────────

  describe('logout()', () => {
    it('elimina el refresh token de la DB', async () => {
      const userId = faker.string.uuid();
      const tokenId = faker.string.uuid();

      await service.logout(userId, tokenId);

      expect(mockDb.refreshToken.deleteMany).toHaveBeenCalledWith({
        where: { userId, id: tokenId },
      });
    });
  });

  // ── changePassword ────────────────────────────────────────────────────────

  describe('changePassword()', () => {
    it('actualiza el hash de contraseña y invalida todos los refresh tokens', async () => {
      const user = makeUser();
      mockUsersService.findById.mockResolvedValue(user);
      mockDb.user.update.mockResolvedValue(user);

      await service.changePassword(user.id, {
        current_password: 'Password1!',
        new_password: 'NewPassword2@',
      });

      expect(mockDb.user.update).toHaveBeenCalledWith(
        expect.objectContaining({
          where: { id: user.id },
          data: expect.objectContaining({ mustChangePassword: false }),
        }),
      );
      expect(mockDb.refreshToken.deleteMany).toHaveBeenCalledWith({
        where: { userId: user.id },
      });
    });

    it('lanza BadRequestException si la contraseña actual es incorrecta', async () => {
      const user = makeUser();
      mockUsersService.findById.mockResolvedValue(user);

      await expect(
        service.changePassword(user.id, {
          current_password: 'WrongPassword!',
          new_password: 'NewPassword2@',
        }),
      ).rejects.toThrow(BadRequestException);
    });

    it('lanza BadRequestException si la nueva contraseña es igual a la actual (SEC-011)', async () => {
      const user = makeUser();
      mockUsersService.findById.mockResolvedValue(user);

      await expect(
        service.changePassword(user.id, {
          current_password: 'Password1!',
          new_password: 'Password1!',
        }),
      ).rejects.toThrow(BadRequestException);
    });
  });

  // ── forgotPassword ────────────────────────────────────────────────────────

  describe('forgotPassword()', () => {
    it('no lanza error si el email no existe (SEC-013: no enumeration)', async () => {
      mockUsersService.findByEmail.mockResolvedValue(null);

      await expect(
        service.forgotPassword('noexiste@test.com'),
      ).resolves.not.toThrow();
    });

    it('crea un token de reset cuando el email existe', async () => {
      const user = makeUser();
      mockUsersService.findByEmail.mockResolvedValue(user);
      mockDb.passwordResetToken.upsert.mockResolvedValue({});

      await service.forgotPassword(user.email);

      expect(mockDb.passwordResetToken.upsert).toHaveBeenCalledWith(
        expect.objectContaining({ where: { email: user.email } }),
      );
    });
  });

  // ── validateRefreshToken ──────────────────────────────────────────────────

  describe('validateRefreshToken()', () => {
    it('retorna true cuando el token existe en DB y no ha expirado', async () => {
      mockDb.refreshToken.findFirst.mockResolvedValue({ id: 'token-id' });

      const result = await service.validateRefreshToken('user-id', 'token-id');

      expect(result).toBe(true);
    });

    it('retorna false cuando el token no existe (logout previo)', async () => {
      mockDb.refreshToken.findFirst.mockResolvedValue(null);

      const result = await service.validateRefreshToken('user-id', 'invalid-token');

      expect(result).toBe(false);
    });
  });
});

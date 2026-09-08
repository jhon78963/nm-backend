import { Test, TestingModule } from '@nestjs/testing';
import { UnauthorizedException, BadRequestException } from '@nestjs/common';
import { AuthController } from './auth.controller';
import { AuthService } from './auth.service';
import { CustomerAuthService } from './customer-auth.service';
import { UsersService } from '../users/users.service';
import { RecaptchaService } from '@app/common/recaptcha/recaptcha.service';
import { faker } from '@faker-js/faker';

// ─── Mocks ────────────────────────────────────────────────────────────────────

const mockAuthService = {
  login: jest.fn(),
  refresh: jest.fn(),
  logout: jest.fn(),
  logoutAll: jest.fn(),
  changePassword: jest.fn(),
  forgotPassword: jest.fn(),
  resetPassword: jest.fn(),
};

const mockCustomerAuthService = {
  login: jest.fn(),
  register: jest.fn(),
  refresh: jest.fn(),
  logout: jest.fn(),
  getProfile: jest.fn(),
  updateProfile: jest.fn(),
  googleLogin: jest.fn(),
};

const mockRecaptchaService = {
  verify: jest.fn().mockResolvedValue(undefined),
};

const mockUsersService = {
  findByIdWithProfile: jest.fn(),
  updateProfile: jest.fn(),
};

const mockReq = { ip: '127.0.0.1' };

function makeAuthUser() {
  return {
    id: faker.string.uuid(),
    username: faker.internet.username(),
    tenantId: faker.string.uuid(),
    warehouseId: faker.string.uuid(),
    roles: ['Vendedora'],
    permissions: [] as string[],
    mustChangePassword: false,
  };
}

// ═══════════════════════════════════════════════════════════════════════════════
// SUITE: AuthController
// ═══════════════════════════════════════════════════════════════════════════════

describe('AuthController', () => {
  let controller: AuthController;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [AuthController],
      providers: [
        { provide: AuthService, useValue: mockAuthService },
        { provide: CustomerAuthService, useValue: mockCustomerAuthService },
        { provide: UsersService, useValue: mockUsersService },
        { provide: RecaptchaService, useValue: mockRecaptchaService },
      ],
    }).compile();

    controller = module.get<AuthController>(AuthController);
    jest.clearAllMocks();
  });

  // ── POST /auth/login ───────────────────────────────────────────────────────

  describe('login()', () => {
    it('delega al AuthService y retorna los tokens', async () => {
      const tokens = {
        access_token: 'access.jwt',
        refresh_token: 'refresh.jwt',
        token_type: 'Bearer' as const,
        expires_in: 900,
        must_change_password: false,
      };
      mockAuthService.login.mockResolvedValue(tokens);

      const result = await controller.login(
        {
          username: 'jperez',
          password: 'Password1!',
        },
        mockReq,
      );

      expect(mockAuthService.login).toHaveBeenCalledWith(
        {
          username: 'jperez',
          password: 'Password1!',
        },
        mockReq.ip,
      );
      expect(result).toEqual(tokens);
    });

    it('propaga UnauthorizedException del servicio (credenciales incorrectas)', async () => {
      // Equivale a AuthSecurityTest::test_login_returns_401_with_invalid_credentials
      mockAuthService.login.mockRejectedValue(
        new UnauthorizedException('Credenciales incorrectas.'),
      );

      await expect(
        controller.login(
          { username: 'bad@user.com', password: 'wrong' },
          mockReq,
        ),
      ).rejects.toThrow(UnauthorizedException);
    });
  });

  // ── GET /auth/me ──────────────────────────────────────────────────────────

  describe('getMe()', () => {
    it('retorna el perfil del usuario autenticado', async () => {
      const user = makeAuthUser();
      const profile = { ...user, email: faker.internet.email(), roles: ['Vendedora'] };
      mockUsersService.findByIdWithProfile.mockResolvedValue(profile);

      const result = await controller.getMe(user);

      expect(mockUsersService.findByIdWithProfile).toHaveBeenCalledWith(user.id);
      expect(result).toEqual(profile);
    });
  });

  // ── PATCH /auth/change-password ────────────────────────────────────────────

  describe('changePassword()', () => {
    it('llama a authService.changePassword con el userId correcto', async () => {
      const user = makeAuthUser();
      mockAuthService.changePassword.mockResolvedValue(undefined);

      await controller.changePassword(user, {
        current_password: 'OldPass1!',
        new_password: 'NewPass2@',
      });

      expect(mockAuthService.changePassword).toHaveBeenCalledWith(user.id, {
        current_password: 'OldPass1!',
        new_password: 'NewPass2@',
      });
    });

    it('propaga BadRequestException si la contraseña actual es incorrecta', async () => {
      const user = makeAuthUser();
      mockAuthService.changePassword.mockRejectedValue(
        new BadRequestException('La contraseña actual es incorrecta.'),
      );

      await expect(
        controller.changePassword(user, {
          current_password: 'wrong',
          new_password: 'NewPass2@',
        }),
      ).rejects.toThrow(BadRequestException);
    });
  });

  // ── POST /auth/forgot-password ────────────────────────────────────────────

  describe('forgotPassword()', () => {
    it('siempre retorna mensaje genérico independientemente del resultado (SEC-013)', async () => {
      // Equivale a: PasswordSecurityTest::test_forgot_password_does_not_reveal_user_existence
      mockAuthService.forgotPassword.mockResolvedValue(undefined);

      const result = await controller.forgotPassword({
        email: 'noexiste@company.com',
      });

      expect(result).toMatchObject({
        message: expect.stringContaining('Si el correo existe'),
      });
    });
  });

  // ── DELETE /auth/logout ───────────────────────────────────────────────────

  describe('logout()', () => {
    it('llama a logoutAll con el userId del token', async () => {
      const user = makeAuthUser();
      mockAuthService.logoutAll.mockResolvedValue(undefined);

      const mockReq = { user } as unknown as Parameters<typeof controller.logout>[0];
      await controller.logout(mockReq);

      expect(mockAuthService.logoutAll).toHaveBeenCalledWith(user.id);
    });
  });
});

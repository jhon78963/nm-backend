import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { UsersService } from './users.service';
import { DatabaseService } from '@app/database';
import { faker } from '@faker-js/faker';

// ─── Factory ──────────────────────────────────────────────────────────────────

function makeDbUser() {
  return {
    id: faker.string.uuid(),
    username: faker.internet.username(),
    email: faker.internet.email().toLowerCase(),
    passwordHash: '$2b$12$hashedpassword',
    name: faker.person.firstName(),
    surname: faker.person.lastName(),
    phone: faker.phone.number(),
    profilePicture: null,
    mustChangePassword: false,
    isEnabled: true,
    tenantId: faker.string.uuid(),
    warehouseId: faker.string.uuid(),
    isDeleted: false,
    createdAt: new Date(),
    userRoles: [{ role: { id: faker.string.uuid(), name: 'Vendedora' } }],
    warehouse: { id: faker.string.uuid(), name: 'Almacén Principal' },
    tenant: { id: faker.string.uuid(), name: 'Novedades Maritex' },
  };
}

// ─── Mock Prisma ──────────────────────────────────────────────────────────────

const mockDb = {
  user: {
    findFirst: jest.fn(),
    update: jest.fn(),
  },
  rolePermission: {
    findMany: jest.fn().mockResolvedValue([]),
  },
  permission: {
    findMany: jest.fn().mockResolvedValue([]),
  },
};

// ═══════════════════════════════════════════════════════════════════════════════
// SUITE: UsersService
// ═══════════════════════════════════════════════════════════════════════════════

describe('UsersService', () => {
  let service: UsersService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        UsersService,
        { provide: DatabaseService, useValue: mockDb },
      ],
    }).compile();

    service = module.get<UsersService>(UsersService);
    jest.clearAllMocks();
  });

  // ── findByUsernameOrEmail ─────────────────────────────────────────────────

  describe('findByUsernameOrEmail()', () => {
    it('encuentra usuario por username', async () => {
      const user = makeDbUser();
      mockDb.user.findFirst.mockResolvedValue(user);

      const result = await service.findByUsernameOrEmail(user.username);

      expect(mockDb.user.findFirst).toHaveBeenCalledWith(
        expect.objectContaining({
          where: expect.objectContaining({
            isDeleted: false,
            OR: [
              { username: user.username },
              { email: user.username },
            ],
          }),
        }),
      );
      expect(result?.id).toBe(user.id);
    });

    it('encuentra usuario por email', async () => {
      const user = makeDbUser();
      mockDb.user.findFirst.mockResolvedValue(user);

      const result = await service.findByUsernameOrEmail(user.email);

      expect(result?.email).toBe(user.email);
    });

    it('retorna null si el usuario no existe o está eliminado', async () => {
      // Equivale a UserAdministrationScopeTest: usuarios soft-deleted no son encontrados
      mockDb.user.findFirst.mockResolvedValue(null);

      const result = await service.findByUsernameOrEmail('ghost@user.com');

      expect(result).toBeNull();
    });
  });

  // ── findById ──────────────────────────────────────────────────────────────

  describe('findById()', () => {
    it('retorna el usuario por ID con sus roles', async () => {
      const user = makeDbUser();
      mockDb.user.findFirst.mockResolvedValue(user);

      const result = await service.findById(user.id);

      expect(result?.id).toBe(user.id);
      expect(result?.userRoles).toHaveLength(1);
    });

    it('retorna null para un UUID inexistente', async () => {
      mockDb.user.findFirst.mockResolvedValue(null);

      const result = await service.findById(faker.string.uuid());

      expect(result).toBeNull();
    });
  });

  // ── findByIdWithProfile ───────────────────────────────────────────────────

  describe('findByIdWithProfile()', () => {
    it('retorna el perfil con roles aplanados (sin passwordHash)', async () => {
      const user = makeDbUser();
      mockDb.user.findFirst.mockResolvedValue(user);

      const result = await service.findByIdWithProfile(user.id);

      expect(result).toHaveProperty('roles');
      expect(result.roles).toContain('Vendedora');
      // El passwordHash no debe estar presente
      expect(result).not.toHaveProperty('passwordHash');
    });

    it('lanza NotFoundException si el usuario no existe', async () => {
      mockDb.user.findFirst.mockResolvedValue(null);

      await expect(
        service.findByIdWithProfile(faker.string.uuid()),
      ).rejects.toThrow(NotFoundException);
    });
  });

  // ── updateProfile ─────────────────────────────────────────────────────────

  describe('updateProfile()', () => {
    it('actualiza solo campos permitidos (whitelist de campos)', async () => {
      const user = makeDbUser();
      const updatedUser = { ...user, name: 'Nuevo Nombre' };
      mockDb.user.update.mockResolvedValue(updatedUser);

      await service.updateProfile(user.id, {
        name: 'Nuevo Nombre',
        passwordHash: 'SHOULD_BE_IGNORED',  // campo no permitido
        isDeleted: true,                      // campo no permitido
      });

      expect(mockDb.user.update).toHaveBeenCalledWith({
        where: { id: user.id },
        data: { name: 'Nuevo Nombre' }, // passwordHash e isDeleted filtrados
      });
    });
  });
});

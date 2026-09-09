import {
  BadRequestException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import * as bcrypt from 'bcryptjs';
import { DatabaseService } from '@app/database';
import {
  paginatedResponse,
  parsePagination,
} from '@app/common/utils/pagination.util';
import type { AuthenticatedUser } from '@app/common/types/authenticated-user.type';

import {
  TENANT_ADMIN_PERMISSIONS,
  SUPER_ADMIN_ROLE,
  ADMIN_ROLE,
} from '@app/common/auth/tenant-admin-permissions';

type UserWithRelations = {
  id: string;
  username: string;
  email: string;
  name: string;
  surname: string;
  profilePicture: string | null;
  mustChangePassword: boolean;
  tenantId: string;
  warehouseId: string | null;
  isEnabled: boolean;
  isDeleted: boolean;
  userRoles: Array<{ role: { id: string; name: string } }>;
  tenant?: { id: string; name: string } | null;
  warehouse?: { id: string; name: string } | null;
};

@Injectable()
export class UsersService {
  constructor(private readonly db: DatabaseService) {}

  async findByUsernameOrEmail(usernameOrEmail: string) {
    return this.db.user.findFirst({
      where: {
        isDeleted: false,
        OR: [
          { username: usernameOrEmail },
          { email: usernameOrEmail },
        ],
      },
      include: {
        userRoles: {
          include: { role: { select: { name: true } } },
        },
      },
    });
  }

  async findById(id: string) {
    return this.db.user.findFirst({
      where: { id, isDeleted: false },
      include: {
        userRoles: {
          include: { role: { select: { name: true } } },
        },
      },
    });
  }

  async findByEmail(email: string) {
    return this.db.user.findFirst({
      where: { email, isDeleted: false },
    });
  }

  async findByIdWithProfile(id: string) {
    const user = await this.loadUserWithRelations(id);
    const roles = user.userRoles.map((ur) => ur.role.name);
    const permissions = await this.getPermissionNamesForUser(id, roles);
    return this.mapUserProfile(user, permissions);
  }

  async getAll(
    query: Record<string, string | undefined>,
    actor: AuthenticatedUser,
  ) {
    const { page, limit, search } = parsePagination(query);
    const tenantFilter = query.tenant_id ?? query.tenantId;
    const warehouseFilter = query.warehouse_id ?? query.warehouseId;
    const actorIsSuperAdmin = actor.roles.includes(SUPER_ADMIN_ROLE);

    const where: Record<string, unknown> = {};

    if (!actorIsSuperAdmin) {
      where.tenantId = actor.tenantId;
      where.NOT = {
        userRoles: {
          some: { role: { name: SUPER_ADMIN_ROLE } },
        },
      };
    } else if (tenantFilter) {
      where.tenantId = tenantFilter;
    }

    if (warehouseFilter) {
      where.warehouseId = warehouseFilter;
    }

    if (search) {
      where.OR = [
        { username: { contains: search, mode: 'insensitive' } },
        { email: { contains: search, mode: 'insensitive' } },
        { name: { contains: search, mode: 'insensitive' } },
        { surname: { contains: search, mode: 'insensitive' } },
      ];
    }

    const [rows, total] = await this.db.$transaction([
      this.db.user.findMany({
        where,
        skip: (page - 1) * limit,
        take: limit,
        orderBy: [{ isDeleted: 'asc' }, { username: 'asc' }],
        include: {
          userRoles: { include: { role: { select: { id: true, name: true } } } },
          tenant: { select: { id: true, name: true } },
          warehouse: { select: { id: true, name: true } },
        },
      }),
      this.db.user.count({ where }),
    ]);

    return paginatedResponse(rows.map((row) => this.mapUserRow(row)), total, limit);
  }

  async create(data: Record<string, unknown>, actor: AuthenticatedUser) {
    const username = String(data.username ?? '').trim();
    const email = String(data.email ?? '').trim();
    const password = String(data.password ?? '');
    const roleNames = (data.roleNames as string[] | undefined) ?? [];
    const tenantId = (data.tenantId as string | null | undefined) ?? actor.tenantId;
    const warehouseId = data.warehouseId as string | null | undefined;

    if (!username || !email || !password) {
      throw new BadRequestException('Usuario, email y contraseña son requeridos.');
    }

    const hash = await bcrypt.hash(password, 12);
    const user = await this.db.user.create({
      data: {
        username,
        email,
        name: String(data.name ?? ''),
        surname: String(data.surname ?? ''),
        profilePicture: data.profilePicture as string | undefined,
        passwordHash: hash,
        tenantId: tenantId ?? actor.tenantId,
        warehouseId: warehouseId ?? null,
      },
    });

    await this.syncUserRoles(user.id, roleNames, user.tenantId);
    return { message: 'User created successfully.' };
  }

  async update(id: string, data: Record<string, unknown>, actor: AuthenticatedUser) {
    const user = await this.assertActorCanAccessUser(id, actor);
    const roleNames = data.roleNames as string[] | undefined;
    const tenantId = data.tenantId as string | null | undefined;
    const warehouseId = data.warehouseId as string | null | undefined;

    const updateData: Record<string, unknown> = {};
    if (data.name != null) updateData.name = String(data.name);
    if (data.surname != null) updateData.surname = String(data.surname);
    if (data.email != null) updateData.email = String(data.email);
    if (data.username != null) updateData.username = String(data.username);
    if (data.profilePicture != null) {
      updateData.profilePicture = String(data.profilePicture);
    }
    if (tenantId !== undefined && tenantId !== null) {
      updateData.tenantId = tenantId;
    }
    if (warehouseId !== undefined) {
      updateData.warehouseId = warehouseId;
    }

    await this.db.user.update({
      where: { id },
      data: updateData,
    });

    if (roleNames) {
      await this.syncUserRoles(id, roleNames, tenantId ?? user.tenantId);
    }

    return { message: 'User updated successfully.' };
  }

  async resetPassword(
    id: string,
    data: Record<string, unknown>,
    actor: AuthenticatedUser,
  ) {
    await this.assertActorCanAccessUser(id, actor);
    const password = String(data.password ?? '');
    if (password.length < 8) {
      throw new BadRequestException('La contraseña debe tener al menos 8 caracteres.');
    }

    const hash = await bcrypt.hash(password, 12);
    await this.db.user.update({
      where: { id },
      data: { passwordHash: hash, mustChangePassword: true },
    });
    await this.db.refreshToken.deleteMany({ where: { userId: id } });
    return { message: 'Password reset successfully.' };
  }

  async softDelete(id: string, actor: AuthenticatedUser) {
    const user = await this.assertActorCanAccessUser(id, actor);
    if (user.userRoles.some((ur) => ur.role.name === SUPER_ADMIN_ROLE)) {
      throw new ForbiddenException(
        'Los usuarios Super Admin no pueden deshabilitarse desde el sistema.',
      );
    }
    if (id === actor.id) {
      throw new BadRequestException('No puedes deshabilitar tu propia cuenta.');
    }

    await this.db.user.update({
      where: { id },
      data: { isDeleted: true, deletionTime: new Date(), isEnabled: false },
    });
    await this.db.refreshToken.deleteMany({ where: { userId: id } });
    return { message: 'Usuario deshabilitado correctamente.' };
  }

  async updateProfile(id: string, data: Record<string, unknown>) {
    const allowed = ['name', 'surname', 'phone', 'profilePicture'];
    const filtered = Object.fromEntries(
      Object.entries(data).filter(([k]) => allowed.includes(k)),
    );
    return this.db.user.update({ where: { id }, data: filtered });
  }

  private async loadUserWithRelations(id: string): Promise<UserWithRelations> {
    const user = await this.db.user.findFirst({
      where: { id, isDeleted: false },
      include: {
        userRoles: { include: { role: { select: { id: true, name: true } } } },
        tenant: { select: { id: true, name: true } },
        warehouse: { select: { id: true, name: true } },
      },
    });
    if (!user) throw new NotFoundException('Usuario no encontrado.');
    return user as UserWithRelations;
  }

  private mapUserRow(user: UserWithRelations) {
    const roles = user.userRoles.map((ur) => ur.role.name);
    return {
      id: user.id,
      username: user.username,
      email: user.email,
      name: user.name,
      surname: user.surname,
      profilePicture: user.profilePicture ?? null,
      roles,
      role: roles[0] ?? null,
      tenantId: user.tenantId,
      tenantName: user.tenant?.name ?? null,
      warehouseId: user.warehouseId,
      warehouseName: user.warehouse?.name ?? null,
      isEnabled: user.isEnabled && !user.isDeleted,
    };
  }

  private mapUserProfile(user: UserWithRelations, permissions: string[]) {
    const base = this.mapUserRow(user);
    return {
      ...base,
      permissions,
      mustChangePassword: user.mustChangePassword,
    };
  }

  async getPermissionsForUser(userId: string, roleNames: string[]): Promise<string[]> {
    return this.getPermissionNamesForUser(userId, roleNames);
  }

  async assignRolesByName(
    userId: string,
    roleNames: string[],
    tenantId: string,
  ): Promise<void> {
    await this.syncUserRoles(userId, roleNames, tenantId);
  }

  private async getPermissionNamesForUser(
    userId: string,
    roleNames: string[],
  ): Promise<string[]> {
    const rows = await this.db.rolePermission.findMany({
      where: {
        role: { userRoles: { some: { userId } } },
      },
      include: { permission: { select: { name: true } } },
    });

    const names = new Set(rows.map((row) => row.permission.name));

    if (roleNames.includes(SUPER_ADMIN_ROLE)) {
      const all = await this.db.permission.findMany({
        select: { name: true },
      });
      for (const permission of all) {
        names.add(permission.name);
      }
    } else if (roleNames.includes(ADMIN_ROLE)) {
      for (const permission of TENANT_ADMIN_PERMISSIONS) {
        names.add(permission);
      }
    }

    return [...names].sort();
  }

  private async syncUserRoles(
    userId: string,
    roleNames: string[],
    tenantId: string,
  ) {
    const filtered = roleNames.filter((name) => name !== SUPER_ADMIN_ROLE);
    const roles = await this.db.role.findMany({
      where: {
        name: { in: filtered },
        OR: [{ tenantId }, { tenantId: null }],
      },
    });

    await this.db.userRole.deleteMany({ where: { userId } });
    if (roles.length > 0) {
      await this.db.userRole.createMany({
        data: roles.map((role) => ({ userId, roleId: role.id })),
        skipDuplicates: true,
      });
    }
  }

  private async assertActorCanAccessUser(
    userId: string,
    actor: AuthenticatedUser,
  ): Promise<UserWithRelations> {
    const user = await this.loadUserWithRelations(userId);
    if (actor.roles.includes(SUPER_ADMIN_ROLE)) {
      return user;
    }
    if (user.userRoles.some((ur) => ur.role.name === SUPER_ADMIN_ROLE)) {
      throw new ForbiddenException(
        'No tiene permiso para gestionar usuarios con rol Super Admin.',
      );
    }
    if (user.tenantId !== actor.tenantId) {
      throw new ForbiddenException(
        'No tiene permiso para gestionar usuarios de otro tenant.',
      );
    }
    return user;
  }
}

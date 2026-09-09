import { BadRequestException, Injectable, NotFoundException } from '@nestjs/common';
import { DatabaseService } from '@app/database';
import {
  paginatedResponse,
  parsePagination,
} from '@app/common/utils/pagination.util';
import type { AuthenticatedUser } from '@app/common/types/authenticated-user.type';

const SUPER_ADMIN_ROLE = 'Super Admin';

@Injectable()
export class WarehousesService {
  constructor(private readonly db: DatabaseService) {}

  async getAll(
    query: Record<string, string | undefined>,
    actor: AuthenticatedUser,
  ) {
    const { page, limit, search } = parsePagination(query);
    const tenantFilter = query.tenant_id ?? query.tenantId;

    const where: Record<string, unknown> = { isDeleted: false };
    if (!actor.roles.includes(SUPER_ADMIN_ROLE)) {
      where.tenantId = actor.tenantId;
    } else if (tenantFilter) {
      where.tenantId = tenantFilter;
    }
    if (search) {
      where.name = { contains: search, mode: 'insensitive' };
    }

    const [rows, total] = await this.db.$transaction([
      this.db.warehouse.findMany({
        where,
        skip: (page - 1) * limit,
        take: limit,
        orderBy: { name: 'asc' },
        include: { tenant: { include: { setting: true } } },
      }),
      this.db.warehouse.count({ where }),
    ]);

    return paginatedResponse(
      rows.map((w) => this.mapWarehouse(w)),
      total,
      limit,
    );
  }

  async getOne(id: string) {
    const warehouse = await this.db.warehouse.findFirst({
      where: { id, isDeleted: false },
      include: { tenant: { include: { setting: true } } },
    });
    if (!warehouse) throw new NotFoundException('Almacén no encontrado.');
    return this.mapWarehouse(warehouse);
  }

  async create(data: Record<string, unknown>, actor: AuthenticatedUser) {
    const name = String(data.name ?? '').trim();
    const tenantId = actor.roles.includes(SUPER_ADMIN_ROLE)
      ? String(data.tenantId ?? actor.tenantId)
      : actor.tenantId;

    await this.db.warehouse.create({
      data: {
        name,
        tenantId,
        baseCash: this.resolveBaseCash(data.baseCash),
        electronicInvoicingEnabled: Boolean(data.electronicInvoicingEnabled ?? false),
      },
    });
    return { message: 'Warehouse created successfully.' };
  }

  async update(id: string, data: Record<string, unknown>) {
    const warehouse = await this.db.warehouse.findFirst({
      where: { id, isDeleted: false },
      include: { tenant: { include: { setting: true } } },
    });
    if (!warehouse) throw new NotFoundException('Almacén no encontrado.');

    const tenantEnabled = warehouse.tenant.setting?.electronicInvoicingEnabled ?? false;
    const wantsWarehouseFlag = data.electronicInvoicingEnabled != null
      ? Boolean(data.electronicInvoicingEnabled)
      : undefined;

    if (wantsWarehouseFlag && !tenantEnabled) {
      throw new BadRequestException(
        'La facturación electrónica no está habilitada para el cliente de esta tienda.',
      );
    }

    await this.db.warehouse.update({
      where: { id },
      data: {
        ...(data.name != null && { name: String(data.name) }),
        ...(data.baseCash != null && { baseCash: this.resolveBaseCash(data.baseCash) }),
        ...(wantsWarehouseFlag != null && {
          electronicInvoicingEnabled: wantsWarehouseFlag,
        }),
      },
    });
    return { message: 'Warehouse updated successfully.' };
  }

  async delete(id: string) {
    await this.db.warehouse.update({
      where: { id },
      data: { isDeleted: true },
    });
    return { message: 'Warehouse deleted successfully.' };
  }

  private mapWarehouse(warehouse: {
    id: string;
    name: string;
    tenantId: string;
    baseCash?: { toString(): string } | number | null;
    electronicInvoicingEnabled: boolean;
    tenant?: {
      setting?: { electronicInvoicingEnabled: boolean } | null;
    } | null;
  }) {
    return {
      id: warehouse.id,
      name: warehouse.name,
      tenantId: warehouse.tenantId,
      baseCash: Number(warehouse.baseCash ?? 100),
      electronicInvoicingEnabled: warehouse.electronicInvoicingEnabled,
      tenantElectronicInvoicingEnabled:
        warehouse.tenant?.setting?.electronicInvoicingEnabled ?? false,
    };
  }

  private resolveBaseCash(value: unknown): number {
    const parsed = Number(value);
    if (!Number.isFinite(parsed) || parsed < 0) {
      return 100;
    }
    return parsed;
  }
}

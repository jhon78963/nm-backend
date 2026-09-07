import { Injectable, NotFoundException, ConflictException } from '@nestjs/common';
import { DatabaseService } from '@app/database';
import { CreateColorDto } from './dto/create-color.dto';
import {
  getAvailableQuantity,
} from '@app/common/utils/product-inventory.util';
import {
  paginatedResponse,
  parsePagination,
} from '@app/common/utils/pagination.util';

type CatalogColorRow = {
  id: string;
  productSizeId: string | null;
  description: string;
  hash: string | null;
  isExists: boolean;
  stock: number | null;
  reservedQuantity: number | null;
  availableQuantity: number | null;
};

/**
 * ColorsService — Equivale a ColorService de Laravel.
 * Los colores son globales (no scoped por warehouse).
 */
@Injectable()
export class ColorsService {
  constructor(private readonly db: DatabaseService) {}

  async findAll(search?: string) {
    return this.db.color.findMany({
      where: {
        isDeleted: false,
        ...(search && { description: { contains: search, mode: 'insensitive' as const } }),
      },
      orderBy: { description: 'asc' },
    });
  }

  async findAllPaginated(query: Record<string, string | undefined>) {
    const { page, limit, search } = parsePagination(query);
    const where = {
      isDeleted: false,
      ...(search && { description: { contains: search, mode: 'insensitive' as const } }),
    };

    const [rows, total] = await this.db.$transaction([
      this.db.color.findMany({
        where,
        skip: (page - 1) * limit,
        take: limit,
        orderBy: { description: 'asc' },
      }),
      this.db.color.count({ where }),
    ]);

    return paginatedResponse(rows, total, limit);
  }

  async findById(id: string) {
    const color = await this.db.color.findFirst({ where: { id, isDeleted: false } });
    if (!color) throw new NotFoundException('Color no encontrado.');
    return color;
  }

  async create(dto: CreateColorDto) {
    const exists = await this.db.color.findFirst({
      where: { description: { equals: dto.description, mode: 'insensitive' }, isDeleted: false },
    });
    if (exists) throw new ConflictException('Ya existe un color con ese nombre.');
    return this.db.color.create({ data: dto });
  }

  async update(id: string, dto: Partial<CreateColorDto>) {
    await this.findById(id);
    return this.db.color.update({ where: { id }, data: dto });
  }

  async remove(id: string) {
    await this.findById(id);
    await this.db.color.update({
      where: { id },
      data: { isDeleted: true, deletionTime: new Date() },
    });
  }

  /** Equivale a ColorController@getAllSelected: colores usados en un warehouse */
  async findUsedInWarehouse(warehouseId: string) {
    return this.db.color.findMany({
      where: {
        isDeleted: false,
        productSizeColors: {
          some: {
            productSize: {
              product: { warehouseId, isDeleted: false },
            },
          },
        },
      },
      orderBy: { description: 'asc' },
    });
  }

  /** Catálogo completo de colores con isExists/stock/productSizeId para una talla del producto. */
  async findUsedInProduct(productId: string, sizeId?: string): Promise<CatalogColorRow[]> {
    const productSize = sizeId
      ? await this.db.productSize.findFirst({
          where: { productId, sizeId, isDeleted: false },
          include: { product: { select: { warehouseId: true } } },
        })
      : null;

    const productSizeId = productSize?.id ?? null;
    const attachedColorIds = new Set<string>();
    const qtyByColorId = new Map<string, number>();
    const reservedByColorId = new Map<string, number>();
    const availableByColorId = new Map<string, number>();

    if (productSize && productSize.product.warehouseId) {
      const pivots = await this.db.productSizeColor.findMany({
        where: { productSizeId: productSize.id },
        select: { colorId: true },
      });
      for (const pivot of pivots) {
        attachedColorIds.add(pivot.colorId);
      }

      const balances = await this.db.inventoryBalance.findMany({
        where: {
          warehouseId: productSize.product.warehouseId,
          productSizeId: productSize.id,
        },
        select: { colorId: true, quantity: true, reservedQuantity: true },
      });
      for (const balance of balances) {
        qtyByColorId.set(
          balance.colorId,
          (qtyByColorId.get(balance.colorId) ?? 0) + balance.quantity,
        );
        reservedByColorId.set(
          balance.colorId,
          (reservedByColorId.get(balance.colorId) ?? 0) + (balance.reservedQuantity ?? 0),
        );
        availableByColorId.set(
          balance.colorId,
          (availableByColorId.get(balance.colorId) ?? 0) +
            getAvailableQuantity(balance),
        );
      }
    }

    const allColors = await this.db.color.findMany({
      where: { isDeleted: false },
      orderBy: { description: 'asc' },
    });

    return allColors
      .map((color) => {
        const isExists = attachedColorIds.has(color.id);
        return {
          id: color.id,
          productSizeId,
          description: color.description,
          hash: color.hash,
          isExists,
          stock: isExists ? (qtyByColorId.get(color.id) ?? 0) : null,
          reservedQuantity: isExists ? (reservedByColorId.get(color.id) ?? 0) : null,
          availableQuantity: isExists ? (availableByColorId.get(color.id) ?? 0) : null,
        };
      })
      .sort((a, b) => {
        const priority = (row: CatalogColorRow) => {
          if (!row.isExists) return 2;
          return (row.stock ?? 0) > 0 ? 0 : 1;
        };
        const diff = priority(a) - priority(b);
        if (diff !== 0) return diff;
        return a.description.localeCompare(b.description, 'es');
      });
  }
}

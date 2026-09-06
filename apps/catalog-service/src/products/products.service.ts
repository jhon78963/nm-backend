import {
  Injectable,
  NotFoundException,
  ConflictException,
} from '@nestjs/common';
import { DatabaseService } from '@app/database';
import { CreateProductDto, UpdateProductDto } from './dto/create-product.dto';
import { mapProductCreateInput, mapProductInput } from './product.mapper';
import { reconcileMasterStock, readMasterStockForProductSize } from '@app/common/utils/product-inventory.util';
import { ProductFiltersDto } from './dto/product-filters.dto';
import { AddProductSizeDto, UpdateProductSizeDto } from './dto/add-product-size.dto';
import { AddSizeColorDto } from './dto/add-size-color.dto';
import { ProductHistoryService } from '../product-history/product-history.service';

/**
 * ProductsService — Equivale a la combinación de:
 *   ProductService + ProductSizeService + ProductSizeColorService
 *
 * Mantiene el mismo contrato de datos que el Laravel original:
 * - Transacciones atómicas con Prisma ($transaction)
 * - Soft-delete (isDeleted + deletionTime)
 * - Registro en ProductHistory en cada mutación de tallas/colores
 * - Scoping por warehouseId del JWT (no se cruzan datos entre almacenes)
 */
@Injectable()
export class ProductsService {
  constructor(
    private readonly db: DatabaseService,
    private readonly history: ProductHistoryService,
  ) {}

  // ── CRUD de productos ──────────────────────────────────────────────────────

  async create(dto: CreateProductDto, createdById: string) {
    return this.db.$transaction(async (tx) => {
      const product = await tx.product.create({
        data: {
          ...mapProductCreateInput(dto),
          createdById,
        },
      });

      if (dto.sizes?.length) {
        for (const sizeDto of dto.sizes) {
          const ps = await tx.productSize.create({
            data: {
              productId: product.id,
              sizeId: sizeDto.sizeId,
              barcode: sizeDto.barcode,
              purchasePrice: sizeDto.purchasePrice,
              salePrice: sizeDto.salePrice,
              minSalePrice: sizeDto.minSalePrice,
            },
          });
          if (sizeDto.colorIds?.length) {
            await tx.productSizeColor.createMany({
              data: sizeDto.colorIds.map((cId) => ({
                productSizeId: ps.id,
                colorId: cId,
              })),
            });
          }
        }
      }

      await this.history.record(tx, {
        productId: product.id,
        eventType: 'CREATED',
        newValues: product,
        createdById,
      });

      return {
        message: 'Producto creado correctamente.',
        productId: product.id,
      };
    });
  }

  async findAll(filters: ProductFiltersDto, warehouseId: string) {
    const { search, genderId, vendorId, colorId, sizeId, hasImages, page = 1, perPage = 20 } = filters;

    const where = {
      isDeleted: false,
      warehouseId,
      ...(search && {
        OR: [
          { name: { contains: search, mode: 'insensitive' as const } },
          { barcode: { contains: search } },
          {
            productSizes: {
              some: {
                isDeleted: false,
                barcode: { contains: search },
              },
            },
          },
        ],
      }),
      ...(genderId && { genderId }),
      ...(vendorId && { vendorId }),
      ...(sizeId && { productSizes: { some: { sizeId } } }),
      ...(colorId && {
        productSizes: { some: { productSizeColors: { some: { colorId } } } },
      }),
      ...(hasImages === true && { media: { some: {} } }),
      ...(hasImages === false && { media: { none: {} } }),
    };

    const [data, total] = await this.db.$transaction([
      this.db.product.findMany({
        where,
        skip: (page - 1) * perPage,
        take: perPage,
        orderBy: { name: 'asc' },
        include: {
          gender: { select: { id: true, name: true } },
          vendor: { select: { id: true, name: true } },
          productSizes: {
            include: {
              size: { select: { id: true, description: true } },
              productSizeColors: {
                include: { color: { select: { id: true, description: true, hash: true } } },
              },
              inventoryBalances: {
                where: { warehouseId },
                select: { quantity: true, colorId: true },
              },
            },
          },
        },
      }),
      this.db.product.count({ where }),
    ]);

    return {
      data,
      meta: { total, page, perPage, lastPage: Math.ceil(total / perPage) },
    };
  }

  async findById(id: string, warehouseId?: string) {
    const product = await this.db.product.findFirst({
      where: { id, isDeleted: false },
      include: {
        gender: true,
        vendor: { select: { id: true, name: true } },
        productSizes: {
          include: {
            size: true,
            productSizeColors: { include: { color: true } },
            inventoryBalances: {
              ...(warehouseId ? { where: { warehouseId } } : {}),
              select: { quantity: true, colorId: true },
            },
          },
        },
        media: {
          orderBy: [{ isCover: 'desc' }, { sortOrder: 'asc' }],
        },
      },
    });
    if (!product) throw new NotFoundException('Producto no encontrado.');
    return product;
  }

  async getHistory(id: string, warehouseId?: string) {
    return this.history.getFormattedHistory(id, warehouseId);
  }

  async update(id: string, dto: UpdateProductDto, updatedById: string) {
    const product = await this.findById(id);
    const updated = await this.db.product.update({
      where: { id },
      data: {
        ...mapProductInput(dto),
        updatedById,
      },
    });
    await this.history.record(this.db, {
      productId: id,
      eventType: 'UPDATED',
      oldValues: product,
      newValues: updated,
      createdById: updatedById,
    });
    return {
      message: 'Producto actualizado correctamente.',
      productId: id,
    };
  }

  async remove(id: string, deletedById: string) {
    await this.findById(id);
    await this.db.product.update({
      where: { id },
      data: { isDeleted: true, deletionTime: new Date(), deletedById },
    });
  }

  // ── Tallas (ProductSize) ──────────────────────────────────────────────────

  async addSize(productId: string, dto: AddProductSizeDto, createdById: string) {
    const product = await this.findById(productId);
    const existing = await this.db.productSize.findFirst({
      where: { productId, sizeId: dto.sizeId, isDeleted: false },
    });
    if (existing) throw new ConflictException('Esta talla ya existe en el producto.');

    return this.db.$transaction(async (tx) => {
      const ps = await tx.productSize.create({
        data: {
          productId,
          sizeId: dto.sizeId,
          barcode: dto.barcode?.trim() || null,
          purchasePrice: dto.purchasePrice ?? 0,
          salePrice: dto.salePrice ?? 0,
          minSalePrice: dto.minSalePrice,
        },
        include: { size: true },
      });

      if (dto.stock !== undefined && dto.stock !== null) {
        await reconcileMasterStock(tx, product.warehouseId, ps.id, dto.stock);
      }

      await this.history.record(tx, {
        productId,
        eventType: 'SIZE_ADDED',
        newValues: {
          ...ps,
          stock: dto.stock !== undefined && dto.stock !== null
            ? Math.max(0, Math.trunc(dto.stock))
            : 0,
        },
        createdById,
      });

      return { message: 'Talla agregada correctamente.' };
    });
  }

  async updateSize(
    productId: string,
    sizeId: string,
    dto: UpdateProductSizeDto,
    updatedById: string,
  ) {
    const product = await this.findById(productId);
    const ps = await this.db.productSize.findFirst({
      where: { productId, sizeId, isDeleted: false },
    });
    if (!ps) throw new NotFoundException('Talla no encontrada en este producto.');

    const oldValues = {
      barcode: ps.barcode,
      purchasePrice: ps.purchasePrice,
      salePrice: ps.salePrice,
      minSalePrice: ps.minSalePrice,
    };

    const updateData: Record<string, unknown> = {};
    if (dto.barcode !== undefined) updateData.barcode = dto.barcode?.trim() || null;
    if (dto.purchasePrice !== undefined) updateData.purchasePrice = dto.purchasePrice;
    if (dto.salePrice !== undefined) updateData.salePrice = dto.salePrice;
    if (dto.minSalePrice !== undefined) updateData.minSalePrice = dto.minSalePrice;

    return this.db.$transaction(async (tx) => {
      const oldStock = await readMasterStockForProductSize(
        tx,
        product.warehouseId,
        ps.id,
      );

      const updated = await tx.productSize.update({
        where: { id: ps.id },
        data: updateData,
        include: { size: true },
      });

      let newStock = oldStock;
      if (dto.stock !== undefined && dto.stock !== null) {
        newStock = Math.max(0, Math.trunc(dto.stock));
        await reconcileMasterStock(tx, product.warehouseId, ps.id, newStock);
      }

      const nextValues = {
        barcode: updated.barcode,
        purchasePrice: updated.purchasePrice,
        salePrice: updated.salePrice,
        minSalePrice: updated.minSalePrice,
        stock: newStock,
        size: updated.size,
      };
      const previousValues = {
        ...oldValues,
        stock: oldStock,
        size: updated.size,
      };

      const pricesChanged =
        previousValues.barcode !== nextValues.barcode ||
        String(previousValues.purchasePrice) !== String(nextValues.purchasePrice) ||
        String(previousValues.salePrice) !== String(nextValues.salePrice) ||
        String(previousValues.minSalePrice ?? '') !== String(nextValues.minSalePrice ?? '');
      const stockChanged = oldStock !== newStock;

      if (pricesChanged || stockChanged) {
        await this.history.record(tx, {
          productId,
          eventType:
            stockChanged && !pricesChanged ? 'SIZE_STOCK_UPDATED' : 'SIZE_PRICE_UPDATED',
          oldValues: previousValues,
          newValues: nextValues,
          createdById: updatedById,
        });
      }

      return { message: 'Talla actualizada correctamente.' };
    });
  }

  async removeSize(productId: string, sizeId: string, deletedById: string) {
    const ps = await this.db.productSize.findFirst({
      where: { productId, sizeId, isDeleted: false },
    });
    if (!ps) throw new NotFoundException('Talla no encontrada.');
    await this.db.productSize.update({
      where: { id: ps.id },
      data: { isDeleted: true, deletionTime: new Date() },
    });
    await this.history.record(this.db, {
      productId,
      eventType: 'SIZE_REMOVED',
      oldValues: ps,
      createdById: deletedById,
    });
  }

  // ── Colores por talla (ProductSizeColor) ──────────────────────────────────

  async addColor(
    productId: string,
    sizeId: string,
    dto: AddSizeColorDto,
    createdById: string,
  ) {
    const ps = await this.db.productSize.findFirst({
      where: { productId, sizeId, isDeleted: false },
    });
    if (!ps) throw new NotFoundException('Talla no encontrada.');

    const existing = await this.db.productSizeColor.findFirst({
      where: { productSizeId: ps.id, colorId: dto.colorId },
    });
    if (existing) throw new ConflictException('Este color ya existe en la talla.');

    return this.db.productSizeColor.create({
      data: { productSizeId: ps.id, colorId: dto.colorId },
      include: { color: true },
    });
  }

  async removeColor(
    productId: string,
    sizeId: string,
    colorId: string,
    deletedById: string,
  ) {
    const ps = await this.db.productSize.findFirst({
      where: { productId, sizeId, isDeleted: false },
    });
    if (!ps) throw new NotFoundException('Talla no encontrada.');

    const psc = await this.db.productSizeColor.findFirst({
      where: { productSizeId: ps.id, colorId },
    });
    if (!psc) throw new NotFoundException('Color no encontrado en esta talla.');

    await this.db.productSizeColor.delete({ where: { id: psc.id } });
    await this.history.record(this.db, {
      productId,
      eventType: 'COLOR_REMOVED',
      oldValues: { colorId },
      createdById: deletedById,
    });
  }

  // ── Búsqueda para POS (equivale a PosController@searchProduct) ────────────

  async searchForPos(query: string, warehouseId: string) {
    // Busca por nombre, barcode de producto o barcode de talla
    return this.db.productSize.findMany({
      where: {
        isDeleted: false,
        product: { isDeleted: false, warehouseId },
        OR: [
          { product: { name: { contains: query, mode: 'insensitive' } } },
          { product: { barcode: { contains: query } } },
          { barcode: { contains: query } },
        ],
      },
      take: 20,
      include: {
        product: { select: { id: true, name: true } },
        size: { select: { id: true, description: true } },
        productSizeColors: { include: { color: true } },
        inventoryBalances: { select: { quantity: true, colorId: true } },
      },
    });
  }
}

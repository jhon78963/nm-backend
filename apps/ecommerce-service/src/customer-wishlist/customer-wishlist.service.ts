import {
  BadRequestException,
  Injectable,
} from '@nestjs/common';
import { DatabaseService } from '@app/database';

import { ReplaceWishlistDto, UpsertWishlistItemDto } from './dto/replace-wishlist.dto';

export interface CustomerWishlistLine {
  productId: string;
  name: string;
  price: number;
  imageUrl?: string;
  productSizeId?: string;
  colorId?: string;
  variation?: string;
  addedAt: string;
}

interface ResolvedWishlistItem {
  productId: string;
  productSizeId?: string;
  colorId?: string;
  nameSnapshot: string;
  variationLabel?: string;
  imageUrl?: string;
  unitPrice: number;
  addedAt: Date;
}

@Injectable()
export class CustomerWishlistService {
  constructor(private readonly db: DatabaseService) {}

  async getWishlist(
    customerId: string,
    warehouseId: string,
  ): Promise<{ items: CustomerWishlistLine[] }> {
    const wishlist = await this.db.ecommerceWishlist.findUnique({
      where: { customerId },
      include: { items: { orderBy: { addedAt: 'desc' } } },
    });

    if (!wishlist || wishlist.warehouseId !== warehouseId || wishlist.items.length === 0) {
      return { items: [] };
    }

    const items = await this.enrichStoredItems(wishlist.items, warehouseId);
    return { items };
  }

  async replaceWishlist(
    customerId: string,
    dto: ReplaceWishlistDto,
  ): Promise<{ items: CustomerWishlistLine[] }> {
    const mergedItems = this.mergeIncomingItems(dto.items);
    const resolvedItems: ResolvedWishlistItem[] = [];

    for (const item of mergedItems) {
      resolvedItems.push(await this.resolveWishlistItem(item, dto.warehouseId));
    }

    const wishlist = await this.db.$transaction(async (tx) => {
      const existing = await tx.ecommerceWishlist.findUnique({ where: { customerId } });
      const wishlistRecord = existing
        ? await tx.ecommerceWishlist.update({
            where: { id: existing.id },
            data: { warehouseId: dto.warehouseId },
          })
        : await tx.ecommerceWishlist.create({
            data: { customerId, warehouseId: dto.warehouseId },
          });

      await tx.ecommerceWishlistItem.deleteMany({ where: { wishlistId: wishlistRecord.id } });

      if (resolvedItems.length > 0) {
        await tx.ecommerceWishlistItem.createMany({
          data: resolvedItems.map((item) => ({
            wishlistId: wishlistRecord.id,
            productId: item.productId,
            productSizeId: item.productSizeId,
            colorId: item.colorId,
            nameSnapshot: item.nameSnapshot,
            variationLabel: item.variationLabel,
            imageUrl: item.imageUrl,
            unitPrice: item.unitPrice,
            addedAt: item.addedAt,
          })),
        });
      }

      return wishlistRecord;
    });

    const stored = await this.db.ecommerceWishlistItem.findMany({
      where: { wishlistId: wishlist.id },
      orderBy: { addedAt: 'desc' },
    });

    const items = await this.enrichStoredItems(stored, dto.warehouseId);
    return { items };
  }

  async clearWishlist(customerId: string): Promise<void> {
    const wishlist = await this.db.ecommerceWishlist.findUnique({ where: { customerId } });
    if (!wishlist) {
      return;
    }

    await this.db.ecommerceWishlistItem.deleteMany({ where: { wishlistId: wishlist.id } });
  }

  private mergeIncomingItems(items: UpsertWishlistItemDto[]): UpsertWishlistItemDto[] {
    const merged = new Map<string, UpsertWishlistItemDto>();

    for (const item of items) {
      const existing = merged.get(item.productId);
      if (!existing) {
        merged.set(item.productId, { ...item });
        continue;
      }

      merged.set(item.productId, {
        ...existing,
        ...item,
        addedAt: this.earliestAddedAt(existing.addedAt, item.addedAt),
      });
    }

    return [...merged.values()];
  }

  private earliestAddedAt(first?: string, second?: string): string | undefined {
    if (!first) return second;
    if (!second) return first;
    return new Date(first).getTime() <= new Date(second).getTime() ? first : second;
  }

  private async resolveWishlistItem(
    item: UpsertWishlistItemDto,
    warehouseId: string,
  ): Promise<ResolvedWishlistItem> {
    const product = await this.db.product.findFirst({
      where: {
        id: item.productId,
        warehouseId,
        isDeleted: false,
      },
      include: {
        productSizes: {
          where: { isDeleted: false },
          select: {
            id: true,
            salePrice: true,
            productSizeColors: { select: { colorId: true } },
          },
        },
      },
    });

    if (!product) {
      throw new BadRequestException(`Producto no válido: ${item.name}`);
    }

    let productSizeId = item.productSizeId;
    let colorId = item.colorId;
    let unitPrice = this.resolveProductPrice(product, item.unitPrice);

    if (productSizeId) {
      const productSize = product.productSizes.find((size) => size.id === productSizeId);
      if (!productSize) {
        throw new BadRequestException(`Variante no válida para: ${item.name}`);
      }

      colorId = await this.resolveColorId(
        productSizeId,
        colorId,
        productSize.productSizeColors.map((link: { colorId: string }) => link.colorId),
      );

      const offerPrice =
        product.offerPrice != null ? Number(product.offerPrice) : null;
      unitPrice =
        offerPrice != null && offerPrice > 0 ? offerPrice : Number(productSize.salePrice);
    }

    return {
      productId: item.productId,
      productSizeId,
      colorId,
      nameSnapshot: item.name || product.name,
      variationLabel: item.variation,
      imageUrl: item.imageUrl,
      unitPrice,
      addedAt: item.addedAt ? new Date(item.addedAt) : new Date(),
    };
  }

  private resolveProductPrice(
    product: {
      offerPrice: unknown;
      productSizes: Array<{ salePrice: unknown }>;
    },
    fallbackPrice: number,
  ): number {
    const offerPrice = product.offerPrice != null ? Number(product.offerPrice) : null;
    if (offerPrice != null && offerPrice > 0) {
      return offerPrice;
    }

    if (product.productSizes.length === 0) {
      return fallbackPrice;
    }

    return Math.min(...product.productSizes.map((size) => Number(size.salePrice)));
  }

  private async resolveColorId(
    productSizeId: string,
    requestedColorId: string | undefined,
    linkedColorIds: string[],
  ): Promise<string | undefined> {
    if (linkedColorIds.length === 0) {
      return requestedColorId;
    }

    if (requestedColorId) {
      if (!linkedColorIds.includes(requestedColorId)) {
        throw new BadRequestException('Color no válido para la variante seleccionada.');
      }

      return requestedColorId;
    }

    if (linkedColorIds.length === 1) {
      return linkedColorIds[0];
    }

    return undefined;
  }

  private async enrichStoredItems(
    items: Array<{
      productId: string;
      productSizeId: string | null;
      colorId: string | null;
      quantity?: number;
      nameSnapshot: string;
      variationLabel: string | null;
      imageUrl: string | null;
      unitPrice: unknown;
      addedAt: Date;
    }>,
    warehouseId: string,
  ): Promise<CustomerWishlistLine[]> {
    const enriched: CustomerWishlistLine[] = [];

    for (const item of items) {
      const product = await this.db.product.findFirst({
        where: {
          id: item.productId,
          warehouseId,
          isDeleted: false,
        },
        include: {
          productSizes: {
            where: { isDeleted: false },
            select: { id: true, salePrice: true },
          },
        },
      });

      if (!product) {
        continue;
      }

      let unitPrice = this.resolveProductPrice(product, Number(item.unitPrice));

      if (item.productSizeId) {
        const productSize = product.productSizes.find((size) => size.id === item.productSizeId);
        if (productSize) {
          const offerPrice = product.offerPrice != null ? Number(product.offerPrice) : null;
          unitPrice =
            offerPrice != null && offerPrice > 0 ? offerPrice : Number(productSize.salePrice);
        }
      }

      enriched.push({
        productId: item.productId,
        name: item.nameSnapshot || product.name,
        price: unitPrice,
        imageUrl: item.imageUrl ?? undefined,
        productSizeId: item.productSizeId ?? undefined,
        colorId: item.colorId ?? undefined,
        variation: item.variationLabel ?? undefined,
        addedAt: item.addedAt.toISOString(),
      });
    }

    return enriched;
  }
}

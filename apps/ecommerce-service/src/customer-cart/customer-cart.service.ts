import {
  BadRequestException,
  Injectable,
} from '@nestjs/common';
import { DatabaseService } from '@app/database';

import { ReplaceCartDto, UpsertCartItemDto } from './dto/replace-cart.dto';

export interface CustomerCartLine {
  id: string;
  productId: string;
  productSizeId: string;
  colorId?: string;
  name: string;
  imageUrl?: string;
  quantity: number;
  price: number;
  variation?: string;
}

interface ResolvedCartItem {
  productId: string;
  productSizeId: string;
  colorId: string;
  nameSnapshot: string;
  variationLabel?: string;
  imageUrl?: string;
  quantity: number;
  unitPrice: number;
}

@Injectable()
export class CustomerCartService {
  constructor(private readonly db: DatabaseService) {}

  async getCart(customerId: string, warehouseId: string): Promise<{ items: CustomerCartLine[] }> {
    const cart = await this.db.ecommerceCart.findUnique({
      where: { customerId },
      include: { items: { orderBy: { createdAt: 'asc' } } },
    });

    if (!cart || cart.warehouseId !== warehouseId || cart.items.length === 0) {
      return { items: [] };
    }

    const items = await this.enrichStoredItems(cart.items, warehouseId);
    return { items };
  }

  async replaceCart(
    customerId: string,
    dto: ReplaceCartDto,
  ): Promise<{ items: CustomerCartLine[] }> {
    const mergedItems = this.mergeIncomingItems(dto.items);
    const resolvedItems: ResolvedCartItem[] = [];

    for (const item of mergedItems) {
      resolvedItems.push(await this.resolveCartItem(item, dto.warehouseId));
    }

    const cart = await this.db.$transaction(async (tx) => {
      const existing = await tx.ecommerceCart.findUnique({ where: { customerId } });
      const cartRecord = existing
        ? await tx.ecommerceCart.update({
            where: { id: existing.id },
            data: { warehouseId: dto.warehouseId },
          })
        : await tx.ecommerceCart.create({
            data: { customerId, warehouseId: dto.warehouseId },
          });

      await tx.ecommerceCartItem.deleteMany({ where: { cartId: cartRecord.id } });

      if (resolvedItems.length > 0) {
        await tx.ecommerceCartItem.createMany({
          data: resolvedItems.map((item) => ({
            cartId: cartRecord.id,
            productId: item.productId,
            productSizeId: item.productSizeId,
            colorId: item.colorId,
            quantity: item.quantity,
            nameSnapshot: item.nameSnapshot,
            variationLabel: item.variationLabel,
            imageUrl: item.imageUrl,
            unitPrice: item.unitPrice,
          })),
        });
      }

      return cartRecord;
    });

    const stored = await this.db.ecommerceCartItem.findMany({
      where: { cartId: cart.id },
      orderBy: { createdAt: 'asc' },
    });

    const items = await this.enrichStoredItems(stored, dto.warehouseId);
    return { items };
  }

  async clearCart(customerId: string): Promise<void> {
    const cart = await this.db.ecommerceCart.findUnique({ where: { customerId } });
    if (!cart) {
      return;
    }

    await this.db.ecommerceCartItem.deleteMany({ where: { cartId: cart.id } });
  }

  private mergeIncomingItems(items: UpsertCartItemDto[]): UpsertCartItemDto[] {
    const merged = new Map<string, UpsertCartItemDto>();

    for (const item of items) {
      const key = `${item.productId}:${item.productSizeId}:${item.colorId ?? ''}`;
      const existing = merged.get(key);

      if (existing) {
        merged.set(key, {
          ...existing,
          quantity: existing.quantity + item.quantity,
          name: item.name || existing.name,
          variation: item.variation ?? existing.variation,
          imageUrl: item.imageUrl ?? existing.imageUrl,
          unitPrice: item.unitPrice,
        });
        continue;
      }

      merged.set(key, { ...item });
    }

    return [...merged.values()];
  }

  private async resolveCartItem(
    item: UpsertCartItemDto,
    warehouseId: string,
  ): Promise<ResolvedCartItem> {
    const productSize = await this.db.productSize.findFirst({
      where: {
        id: item.productSizeId,
        productId: item.productId,
        isDeleted: false,
        product: {
          id: item.productId,
          warehouseId,
          isDeleted: false,
        },
      },
      include: {
        product: { select: { name: true, offerPrice: true } },
        productSizeColors: { select: { colorId: true } },
      },
    });

    if (!productSize) {
      throw new BadRequestException(`Producto o variante no válida: ${item.name}`);
    }

    const colorId = await this.resolveColorId(
      item.productSizeId,
      item.colorId,
      productSize.productSizeColors.map((link: { colorId: string }) => link.colorId),
    );

    const offerPrice =
      productSize.product.offerPrice != null
        ? Number(productSize.product.offerPrice)
        : null;
    const serverPrice =
      offerPrice != null && offerPrice > 0 ? offerPrice : Number(productSize.salePrice);

    return {
      productId: item.productId,
      productSizeId: item.productSizeId,
      colorId,
      nameSnapshot: item.name || productSize.product.name,
      variationLabel: item.variation,
      imageUrl: item.imageUrl,
      quantity: item.quantity,
      unitPrice: serverPrice,
    };
  }

  private async resolveColorId(
    productSizeId: string,
    requestedColorId: string | undefined,
    linkedColorIds: string[],
  ): Promise<string> {
    if (requestedColorId) {
      if (!linkedColorIds.includes(requestedColorId)) {
        throw new BadRequestException('Color no válido para la variante seleccionada.');
      }

      return requestedColorId;
    }

    if (linkedColorIds.length === 1) {
      return linkedColorIds[0];
    }

    if (linkedColorIds.length === 0) {
      const fallback = await this.db.color.findFirst({
        where: { description: 'Sin color', isDeleted: false },
        select: { id: true },
      });

      if (!fallback) {
        throw new BadRequestException('No se pudo determinar el color del producto.');
      }

      return fallback.id;
    }

    throw new BadRequestException('Debe seleccionar un color para completar el carrito.');
  }

  private async enrichStoredItems(
    items: Array<{
      id: string;
      productId: string;
      productSizeId: string;
      colorId: string | null;
      quantity: number;
      nameSnapshot: string;
      variationLabel: string | null;
      imageUrl: string | null;
      unitPrice: unknown;
    }>,
    warehouseId: string,
  ): Promise<CustomerCartLine[]> {
    const enriched: CustomerCartLine[] = [];

    for (const item of items) {
      const productSize = await this.db.productSize.findFirst({
        where: {
          id: item.productSizeId,
          productId: item.productId,
          isDeleted: false,
          product: {
            id: item.productId,
            warehouseId,
            isDeleted: false,
          },
        },
        include: {
          product: { select: { name: true, offerPrice: true } },
        },
      });

      if (!productSize) {
        continue;
      }

      const offerPrice =
        productSize.product.offerPrice != null
          ? Number(productSize.product.offerPrice)
          : null;
      const serverPrice =
        offerPrice != null && offerPrice > 0 ? offerPrice : Number(productSize.salePrice);

      enriched.push({
        id: item.id,
        productId: item.productId,
        productSizeId: item.productSizeId,
        colorId: item.colorId ?? undefined,
        name: item.nameSnapshot || productSize.product.name,
        imageUrl: item.imageUrl ?? undefined,
        quantity: item.quantity,
        price: serverPrice,
        variation: item.variationLabel ?? undefined,
      });
    }

    return enriched;
  }
}

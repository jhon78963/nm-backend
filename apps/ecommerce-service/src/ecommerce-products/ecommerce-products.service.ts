import { BadRequestException, Injectable, NotFoundException } from '@nestjs/common';
import { DatabaseService } from '@app/database';
import {
  buildMasterStockByProductSizeId,
  buildStockByProductSizeColorId,
} from '@app/common/utils/product-inventory.util';
import { extractProductIdPrefixFromSlug } from '@app/common/utils/product-slug.util';

import { PublicProductsQueryDto } from './dto/public-products-query.dto';
import { ProductReviewsService } from '../product-reviews/product-reviews.service';
import {
  mapCatalogProductToPublicItem,
  mapProductStockResponse,
  type PublicProductItem,
  type PublicProductStockResponse,
  type PublicProductsResponse,
} from './ecommerce-products.mapper';

@Injectable()
export class EcommerceProductsService {
  constructor(
    private readonly db: DatabaseService,
    private readonly productReviewsService: ProductReviewsService,
  ) {}

  async getPublicProducts(query: PublicProductsQueryDto): Promise<PublicProductsResponse> {
    if (!query.ids) {
      throw new BadRequestException('Debe enviar al menos un ID de producto válido.');
    }

    const productIds = this.parseProductIds(query.ids);

    if (productIds.length === 0) {
      throw new BadRequestException('Debe enviar al menos un ID de producto válido.');
    }

    const products = await this.db.product.findMany({
      where: {
        id: { in: productIds },
        warehouseId: query.warehouseId,
        isDeleted: false,
        status: { in: ['active', 'AVAILABLE'] },
        wooStatus: { in: ['publish', 'draft'] },
      },
      include: {
        gender: { select: { name: true } },
        productSizes: {
          where: { isDeleted: false },
          select: {
            id: true,
            salePrice: true,
            isDeleted: true,
            size: {
              select: { id: true, description: true, isDeleted: true },
            },
            productSizeColors: {
              select: {
                colorId: true,
                color: {
                  select: { id: true, description: true, hash: true, isDeleted: true },
                },
              },
            },
          },
        },
        media: {
          orderBy: [{ isCover: 'desc' }, { sortOrder: 'asc' }, { createdAt: 'asc' }],
          select: {
            url: true,
            isCover: true,
            sortOrder: true,
          },
        },
      },
    });

    const productSizeIds = products.flatMap((product) =>
      product.productSizes.map((size) => size.id),
    );

    const [stockByProductSizeId, stockByProductSizeColorId, reviewStatsByProductId] = await Promise.all([
      buildMasterStockByProductSizeId(this.db, query.warehouseId, productSizeIds),
      buildStockByProductSizeColorId(this.db, query.warehouseId, productSizeIds),
      this.productReviewsService.getReviewStatsForProducts(productIds),
    ]);

    const productsById = new Map(
      products.map((product) => {
        const reviewStats = reviewStatsByProductId.get(product.id);
        return [
          product.id,
          mapCatalogProductToPublicItem(
            product,
            stockByProductSizeId,
            stockByProductSizeColorId,
            reviewStats,
          ),
        ];
      }),
    );

    return {
      products: productIds
        .map((id) => productsById.get(id))
        .filter((product): product is NonNullable<typeof product> => Boolean(product)),
    };
  }

  async getPublicProductBySlug(
    slug: string,
    warehouseId: string,
  ): Promise<PublicProductItem> {
    const identifier = extractProductIdPrefixFromSlug(slug);

    if (!identifier) {
      throw new BadRequestException('Slug de producto inválido.');
    }

    const products = await this.db.product.findMany({
      where: {
        warehouseId,
        isDeleted: false,
        status: { in: ['active', 'AVAILABLE'] },
        wooStatus: { in: ['publish', 'draft'] },
        id: identifier.includes('-')
          ? identifier
          : { startsWith: identifier },
      },
      include: {
        gender: { select: { name: true } },
        productSizes: {
          where: { isDeleted: false },
          select: {
            id: true,
            salePrice: true,
            isDeleted: true,
            size: {
              select: { id: true, description: true, isDeleted: true },
            },
            productSizeColors: {
              select: {
                colorId: true,
                color: {
                  select: { id: true, description: true, hash: true, isDeleted: true },
                },
              },
            },
          },
        },
        media: {
          orderBy: [{ isCover: 'desc' }, { sortOrder: 'asc' }, { createdAt: 'asc' }],
          select: {
            url: true,
            isCover: true,
            sortOrder: true,
          },
        },
      },
      take: 2,
    });

    if (products.length !== 1) {
      throw new NotFoundException('Producto no encontrado.');
    }

    const [product] = products;
    const productSizeIds = product.productSizes.map((size) => size.id);
    const [stockByProductSizeId, stockByProductSizeColorId, reviewStatsByProductId] = await Promise.all([
      buildMasterStockByProductSizeId(this.db, warehouseId, productSizeIds),
      buildStockByProductSizeColorId(this.db, warehouseId, productSizeIds),
      this.productReviewsService.getReviewStatsForProducts([product.id]),
    ]);

    return mapCatalogProductToPublicItem(
      product,
      stockByProductSizeId,
      stockByProductSizeColorId,
      reviewStatsByProductId.get(product.id),
    );
  }

  async getPublicProductStock(
    productId: string,
    warehouseId: string,
  ): Promise<PublicProductStockResponse> {
    const product = await this.db.product.findFirst({
      where: {
        id: productId,
        warehouseId,
        isDeleted: false,
        status: { in: ['active', 'AVAILABLE'] },
        wooStatus: { in: ['publish', 'draft'] },
      },
      select: {
        id: true,
        productSizes: {
          where: { isDeleted: false },
          select: {
            id: true,
            isDeleted: true,
            size: {
              select: { id: true, description: true, isDeleted: true },
            },
            productSizeColors: {
              select: {
                colorId: true,
                color: {
                  select: { id: true, description: true, hash: true, isDeleted: true },
                },
              },
            },
          },
        },
      },
    });

    if (!product) {
      throw new NotFoundException('Producto no encontrado.');
    }

    const productSizeIds = product.productSizes.map((size) => size.id);
    const [stockByProductSizeId, stockByProductSizeColorId] = await Promise.all([
      buildMasterStockByProductSizeId(this.db, warehouseId, productSizeIds),
      buildStockByProductSizeColorId(this.db, warehouseId, productSizeIds),
    ]);

    const stock = mapProductStockResponse(
      product.productSizes,
      stockByProductSizeId,
      stockByProductSizeColorId,
    );

    return {
      productId: product.id,
      ...stock,
    };
  }

  private parseProductIds(ids: string): string[] {
    return [...new Set(ids.split(',').map((id) => id.trim()).filter(Boolean))];
  }
}

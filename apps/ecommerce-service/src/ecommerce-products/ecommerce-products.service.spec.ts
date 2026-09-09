import { NotFoundException } from '@nestjs/common';
import { Test, TestingModule } from '@nestjs/testing';
import { DatabaseService } from '@app/database';
import {
  buildMasterStockByProductSizeId,
  buildStockByProductSizeColorId,
} from '@app/common/utils/product-inventory.util';

import { ProductReviewsService } from '../product-reviews/product-reviews.service';
import { EcommerceProductsService } from './ecommerce-products.service';

jest.mock('@app/common/utils/product-inventory.util', () => ({
  buildMasterStockByProductSizeId: jest.fn(),
  buildStockByProductSizeColorId: jest.fn(),
}));

const mockDb = {
  product: {
    findMany: jest.fn(),
    findFirst: jest.fn(),
  },
};

const mockProductReviewsService = {
  getReviewStatsForProducts: jest.fn(),
};

describe('EcommerceProductsService', () => {
  let service: EcommerceProductsService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        EcommerceProductsService,
        { provide: DatabaseService, useValue: mockDb },
        { provide: ProductReviewsService, useValue: mockProductReviewsService },
      ],
    }).compile();

    service = module.get(EcommerceProductsService);
    jest.clearAllMocks();
  });

  describe('getPublicProductStock()', () => {
    it('returns stock by size and color for an active product', async () => {
      mockDb.product.findFirst.mockResolvedValue({
        id: 'prod-1',
        productSizes: [
          {
            id: 'size-1',
            isDeleted: false,
            size: { id: 's-1', description: 'M', isDeleted: false },
            productSizeColors: [
              {
                colorId: 'color-1',
                color: {
                  id: 'color-1',
                  description: 'Azul',
                  hash: '#0000ff',
                  isDeleted: false,
                },
              },
            ],
          },
        ],
      });

      (buildMasterStockByProductSizeId as jest.Mock).mockResolvedValue(
        new Map([['size-1', 5]]),
      );
      (buildStockByProductSizeColorId as jest.Mock).mockResolvedValue(
        new Map([['size-1:color-1', 3]]),
      );

      const result = await service.getPublicProductStock('prod-1', 'warehouse-1');

      expect(result).toEqual({
        productId: 'prod-1',
        stockStatus: 'in_stock',
        sizes: [
          {
            id: 'size-1',
            stock: 5,
            colors: [{ id: 'color-1', stock: 3 }],
          },
        ],
      });
    });

    it('throws NotFoundException when product is missing', async () => {
      mockDb.product.findFirst.mockResolvedValue(null);

      await expect(
        service.getPublicProductStock('missing', 'warehouse-1'),
      ).rejects.toBeInstanceOf(NotFoundException);
    });
  });
});

import { BadRequestException } from '@nestjs/common';
import { Test, TestingModule } from '@nestjs/testing';
import { DatabaseService } from '@app/database';
import { Decimal } from '@prisma/client/runtime/library';
import { CouponsService } from './coupons.service';

const mockDb = {
  ecommerceCoupon: {
    findUnique: jest.fn(),
    upsert: jest.fn(),
  },
  ecommerceCouponRedemption: {
    count: jest.fn(),
  },
  ecommerceCouponAssignment: {
    findFirst: jest.fn(),
  },
};

function makeCoupon(overrides: Record<string, unknown> = {}) {
  return {
    id: 'coupon-1',
    code: 'TEST10',
    description: 'Test coupon',
    discountType: 'percentage',
    discountValue: new Decimal(10),
    minSubtotal: new Decimal(0),
    maxDiscount: new Decimal(50),
    usageLimit: null,
    usageCount: 0,
    perCustomerLimit: 1,
    perIpLimit: 0,
    isWelcome: false,
    isActive: true,
    startsAt: null,
    expiresAt: null,
    warehouseId: null,
    ...overrides,
  };
}

describe('CouponsService', () => {
  let service: CouponsService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [CouponsService, { provide: DatabaseService, useValue: mockDb }],
    }).compile();

    service = module.get(CouponsService);
    jest.clearAllMocks();
    mockDb.ecommerceCouponRedemption.count.mockResolvedValue(0);
    mockDb.ecommerceCouponAssignment.findFirst.mockResolvedValue(null);
  });

  describe('validateCoupon()', () => {
    it('aplica descuento porcentual con tope maxDiscount', async () => {
      mockDb.ecommerceCoupon.findUnique.mockResolvedValue(makeCoupon());

      const result = await service.validateCoupon({
        code: 'test10',
        subtotal: 1000,
        customerId: 'customer-1',
      });

      expect(result.code).toBe('TEST10');
      expect(result.discountAmount).toBe(50);
    });

    it('aplica descuento fijo limitado al subtotal', async () => {
      mockDb.ecommerceCoupon.findUnique.mockResolvedValue(
        makeCoupon({
          code: 'FIJO20',
          discountType: 'fixed',
          discountValue: new Decimal(20),
          maxDiscount: null,
        }),
      );

      const result = await service.validateCoupon({
        code: 'fijo20',
        subtotal: 15,
        customerId: 'customer-1',
      });

      expect(result.discountAmount).toBe(15);
    });

    it('rechaza cupón inexistente', async () => {
      mockDb.ecommerceCoupon.findUnique.mockResolvedValue(null);

      await expect(
        service.validateCoupon({ code: 'NOEXISTE', subtotal: 100 }),
      ).rejects.toThrow(BadRequestException);
    });

    it('rechaza cupón inactivo', async () => {
      mockDb.ecommerceCoupon.findUnique.mockResolvedValue(makeCoupon({ isActive: false }));

      await expect(
        service.validateCoupon({ code: 'TEST10', subtotal: 100 }),
      ).rejects.toThrow('Cupón no válido');
    });

    it('rechaza subtotal por debajo del mínimo', async () => {
      mockDb.ecommerceCoupon.findUnique.mockResolvedValue(
        makeCoupon({ minSubtotal: new Decimal(200) }),
      );

      await expect(
        service.validateCoupon({ code: 'TEST10', subtotal: 100, customerId: 'customer-1' }),
      ).rejects.toThrow('subtotal mínimo');
    });
  });
});

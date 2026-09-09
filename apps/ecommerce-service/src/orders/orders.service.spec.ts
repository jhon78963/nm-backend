import { BadRequestException, NotFoundException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { Test, TestingModule } from '@nestjs/testing';
import { DatabaseService } from '@app/database';
import { faker } from '@faker-js/faker';
import { OrdersService } from './orders.service';
import { CouponsService } from '../coupons/coupons.service';
import { EcommerceOrderEventsService } from '../order-events/ecommerce-order-events.service';

function buildTx() {
  return {
    ecommerceOrder: {
      update: jest.fn().mockResolvedValue({
        id: 'order-1',
        orderNumber: 'NM-001',
        status: 'cancelled',
        paymentStatus: 'pending',
        items: [],
      }),
    },
    ecommerceCouponRedemption: {
      findFirst: jest.fn().mockResolvedValue(null),
    },
    inventoryBalance: {
      update: jest.fn(),
    },
  };
}

const mockDb = {
  ecommerceOrder: {
    findFirst: jest.fn(),
  },
  $transaction: jest.fn(),
};

const mockCoupons = {
  resolveCouponForOrder: jest.fn(),
};

const mockOrderEvents = {
  publishOrderUpdated: jest.fn().mockResolvedValue(undefined),
};

describe('OrdersService', () => {
  let service: OrdersService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        OrdersService,
        { provide: DatabaseService, useValue: mockDb },
        {
          provide: ConfigService,
          useValue: {
            get: jest.fn((key: string) =>
              key === 'ECOMMERCE_SYSTEM_USER_ID' ? 'system-user-id' : undefined,
            ),
          },
        },
        { provide: CouponsService, useValue: mockCoupons },
        { provide: EcommerceOrderEventsService, useValue: mockOrderEvents },
      ],
    }).compile();

    service = module.get(OrdersService);
    jest.clearAllMocks();
  });

  describe('cancelPendingCheckoutOrder()', () => {
    const email = 'cliente@example.com';

    it('cancela pedido pendiente no pagado', async () => {
      const existing = {
        id: 'order-1',
        orderNumber: 'NM-001',
        email,
        status: 'pending',
        paymentStatus: 'pending',
        warehouseId: faker.string.uuid(),
        stockReservedAt: null,
        items: [],
      };

      const tx = buildTx();
      mockDb.ecommerceOrder.findFirst.mockResolvedValue(existing);
      mockDb.$transaction.mockImplementation((fn: (client: typeof tx) => unknown) => fn(tx));

      const result = await service.cancelPendingCheckoutOrder('NM-001', email);

      expect(result.status).toBe('cancelled');
      expect(tx.ecommerceOrder.update).toHaveBeenCalledWith(
        expect.objectContaining({
          where: { id: existing.id },
          data: expect.objectContaining({ status: 'cancelled' }),
        }),
      );
      expect(mockOrderEvents.publishOrderUpdated).toHaveBeenCalled();
    });

    it('es idempotente si el pedido ya estaba cancelado', async () => {
      mockDb.ecommerceOrder.findFirst.mockResolvedValue({
        id: 'order-1',
        orderNumber: 'NM-001',
        email,
        status: 'cancelled',
        paymentStatus: 'pending',
        items: [],
      });

      const result = await service.cancelPendingCheckoutOrder('NM-001', email);

      expect(result.status).toBe('cancelled');
      expect(mockDb.$transaction).not.toHaveBeenCalled();
    });

    it('rechaza cancelar pedido pagado', async () => {
      mockDb.ecommerceOrder.findFirst.mockResolvedValue({
        id: 'order-1',
        orderNumber: 'NM-001',
        email,
        status: 'pending',
        paymentStatus: 'paid',
        items: [],
      });

      await expect(service.cancelPendingCheckoutOrder('NM-001', email)).rejects.toThrow(
        BadRequestException,
      );
    });

    it('lanza NotFoundException si no existe el pedido', async () => {
      mockDb.ecommerceOrder.findFirst.mockResolvedValue(null);

      await expect(service.cancelPendingCheckoutOrder('NM-404', email)).rejects.toThrow(
        NotFoundException,
      );
    });
  });
});

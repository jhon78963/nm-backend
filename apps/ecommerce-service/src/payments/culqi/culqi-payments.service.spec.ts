import { UnauthorizedException } from '@nestjs/common';
import { Test, TestingModule } from '@nestjs/testing';
import { DatabaseService } from '@app/database';
import { OrdersService } from '../../orders/orders.service';
import { EcommerceOrderEventsService } from '../../order-events/ecommerce-order-events.service';
import { CulqiPaymentsService } from './culqi-payments.service';
import { CulqiService } from './culqi.service';

describe('CulqiPaymentsService', () => {
  let service: CulqiPaymentsService;
  const mockCulqi = {
    verifyWebhookBasicAuth: jest.fn(),
    parseEventData: jest.fn(),
    extractOrderFromEventData: jest.fn(),
    extractChargeFromEventData: jest.fn(),
    isPaidOrder: jest.fn(),
    isSuccessfulCharge: jest.fn(),
  };

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        CulqiPaymentsService,
        { provide: DatabaseService, useValue: {} },
        { provide: CulqiService, useValue: mockCulqi },
        { provide: OrdersService, useValue: { cancelPendingCheckoutOrder: jest.fn() } },
        { provide: EcommerceOrderEventsService, useValue: { publishOrderUpdated: jest.fn() } },
      ],
    }).compile();

    service = module.get(CulqiPaymentsService);
    jest.clearAllMocks();
  });

  describe('assertWebhookAuthorized()', () => {
    it('lanza UnauthorizedException si el Basic auth es inválido', () => {
      mockCulqi.verifyWebhookBasicAuth.mockReturnValue(false);

      expect(() => service.assertWebhookAuthorized('Basic invalid')).toThrow(
        UnauthorizedException,
      );
    });

    it('no lanza si el Basic auth es válido', () => {
      mockCulqi.verifyWebhookBasicAuth.mockReturnValue(true);

      expect(() => service.assertWebhookAuthorized('Basic ok')).not.toThrow();
    });
  });

  describe('handleWebhookEvent()', () => {
    it('ignora eventos desconocidos', async () => {
      const result = await service.handleWebhookEvent({
        type: 'unknown.event',
        data: {},
      });

      expect(result).toEqual({
        handled: false,
        eventType: 'unknown.event',
        reason: 'ignored_event',
      });
    });
  });
});

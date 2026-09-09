import { BadRequestException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { Test, TestingModule } from '@nestjs/testing';
import { CulqiService } from './culqi.service';

function buildConfig(values: Record<string, string | undefined> = {}) {
  return {
    get: jest.fn((key: string, fallback?: string) => {
      if (key in values) return values[key];
      return fallback;
    }),
  };
}

describe('CulqiService', () => {
  let service: CulqiService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        CulqiService,
        {
          provide: ConfigService,
          useValue: buildConfig({
            CULQI_ENABLED: 'true',
            CULQI_SECRET_KEY: 'sk_test_secret',
            CULQI_WEBHOOK_USER: 'webhook-user',
            CULQI_WEBHOOK_PASSWORD: 'webhook-pass',
          }),
        },
      ],
    }).compile();

    service = module.get(CulqiService);
  });

  describe('assertOrderAmount()', () => {
    it('rechaza montos menores a S/ 6.00', () => {
      expect(() => service.assertOrderAmount(599)).toThrow(BadRequestException);
    });

    it('acepta montos iguales o mayores a S/ 6.00', () => {
      expect(() => service.assertOrderAmount(600)).not.toThrow();
      expect(() => service.assertOrderAmount(1200)).not.toThrow();
    });
  });

  describe('isSuccessfulCharge()', () => {
    it('detecta venta exitosa', () => {
      expect(
        service.isSuccessfulCharge({
          id: 'chr_test',
          outcome: { type: 'venta_exitosa' },
        } as never),
      ).toBe(true);
    });

    it('rechaza cargos fallidos', () => {
      expect(
        service.isSuccessfulCharge({
          id: 'chr_test',
          outcome: { type: 'venta_denegada' },
        } as never),
      ).toBe(false);
    });
  });

  describe('isPaidOrder()', () => {
    it('detecta orden pagada por state o paid_at', () => {
      expect(service.isPaidOrder({ id: 'ord', state: 'paid' } as never)).toBe(true);
      expect(service.isPaidOrder({ id: 'ord', paid_at: 123 } as never)).toBe(true);
      expect(service.isPaidOrder({ id: 'ord', state: 'pending' } as never)).toBe(false);
    });
  });

  describe('parseEventData()', () => {
    it('parsea JSON string y objetos', () => {
      expect(service.parseEventData({ data: '{"id":"x"}' })).toEqual({ id: 'x' });
      expect(service.parseEventData({ data: { id: 'y' } })).toEqual({ id: 'y' });
      expect(service.parseEventData({ data: 'not-json' })).toBeNull();
      expect(service.parseEventData({})).toBeNull();
    });
  });

  describe('verifyWebhookBasicAuth()', () => {
    it('valida credenciales Basic correctas', () => {
      const header = `Basic ${Buffer.from('webhook-user:webhook-pass').toString('base64')}`;
      expect(service.verifyWebhookBasicAuth(header)).toBe(true);
    });

    it('rechaza credenciales inválidas o ausentes', () => {
      expect(service.verifyWebhookBasicAuth(undefined)).toBe(false);
      expect(service.verifyWebhookBasicAuth('Bearer token')).toBe(false);
      expect(
        service.verifyWebhookBasicAuth(
          `Basic ${Buffer.from('webhook-user:wrong').toString('base64')}`,
        ),
      ).toBe(false);
    });
  });

  describe('extractChargeFromEventData()', () => {
    it('extrae cargos válidos del payload', () => {
      const charge = service.extractChargeFromEventData({
        object: 'charge',
        id: 'chr_123',
      });
      expect(charge?.id).toBe('chr_123');
      expect(service.extractChargeFromEventData({ object: 'order', id: 'ord' })).toBeNull();
    });
  });
});

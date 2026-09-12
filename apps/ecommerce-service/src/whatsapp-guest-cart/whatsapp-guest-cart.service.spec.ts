import { Test, TestingModule } from '@nestjs/testing';
import { ConfigService } from '@nestjs/config';

import { WhatsAppGuestCartRepository } from './whatsapp-guest-cart.repository';
import { WhatsAppGuestCartService } from './whatsapp-guest-cart.service';
import { hashWhatsAppSessionId } from './utils/whatsapp-session-id.util';

const mockRepository = {
  get: jest.fn(),
  save: jest.fn(),
  delete: jest.fn(),
  getTtl: jest.fn(),
  getDefaultTtlSeconds: jest.fn(),
};

const CART_SESSION_SALT = '7603378030f45ca641e6cd475f7b1006facf7962cd1ce16a7d2f1d322769e52f';
const HANDOFF_PHONE = '51915213408';

const configValues: Record<string, string | number> = {
  WHATSAPP_CART_SESSION_SALT: CART_SESSION_SALT,
  WHATSAPP_HANDOFF_PHONE: HANDOFF_PHONE,
  WHATSAPP_GUEST_CART_TTL_SECONDS: 86400,
  STORE_WAREHOUSE_ID: '46ea2f24-30d2-59a3-8790-8670a0105280',
};

describe('WhatsAppGuestCartService', () => {
  let service: WhatsAppGuestCartService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        WhatsAppGuestCartService,
        { provide: WhatsAppGuestCartRepository, useValue: mockRepository },
        {
          provide: ConfigService,
          useValue: {
            get: jest.fn((key: string, fallback?: string | number) => configValues[key] ?? fallback),
          },
        },
      ],
    }).compile();

    service = module.get(WhatsAppGuestCartService);
    jest.clearAllMocks();
    mockRepository.getDefaultTtlSeconds.mockReturnValue(86400);
    mockRepository.getTtl.mockResolvedValue(86400);
    mockRepository.get.mockResolvedValue(null);
    mockRepository.save.mockResolvedValue(undefined);
  });

  it('inicializa un carrito Redis con Session ID hasheado, totales y TTL 24h', async () => {
    const result = await service.upsert({
      customerPhone: '+51987654321',
      items: [
        { name: 'Polo Niño Azul', sku: '7890123456789', quantity: 2, unitPrice: 49.9 },
      ],
      source: 'pdp',
    });

    const expectedSessionId = hashWhatsAppSessionId('+51987654321', CART_SESSION_SALT);

    expect(result.sessionId).toBe(expectedSessionId);
    expect(result.ttlSeconds).toBe(86400);
    expect(result.cart?.itemCount).toBe(2);
    expect(result.cart?.total).toBe(99.8);
    expect(result.handoffUrl).toContain(`https://wa.me/${HANDOFF_PHONE}?text=`);
    expect(result.handoffUrl).toContain(encodeURIComponent(`[${expectedSessionId}]`));
    expect(mockRepository.save).toHaveBeenCalledWith(
      expect.objectContaining({ sessionId: expectedSessionId, channel: 'whatsapp' }),
      86400,
    );
  });

  it('fusiona cantidades de la misma línea en un upsert posterior', async () => {
    const sessionId = hashWhatsAppSessionId('51987654321', CART_SESSION_SALT);
    mockRepository.get.mockResolvedValue({
      sessionId,
      warehouseId: '46ea2f24-30d2-59a3-8790-8670a0105280',
      channel: 'whatsapp',
      currency: 'PEN',
      items: [
        {
          sku: '7890123456789',
          name: 'Polo Niño Azul',
          quantity: 1,
          unitPrice: 49.9,
          lineTotal: 49.9,
        },
      ],
      itemCount: 1,
      subtotal: 49.9,
      total: 49.9,
      source: 'pdp',
      createdAt: '2026-09-11T00:00:00.000Z',
      updatedAt: '2026-09-11T00:00:00.000Z',
      expiresAt: '2026-09-12T00:00:00.000Z',
    });

    const result = await service.upsert({
      customerPhone: '51987654321',
      items: [{ name: 'Polo Niño Azul', sku: '7890123456789', quantity: 1, unitPrice: 49.9 }],
    });

    expect(result.cart?.items[0]?.quantity).toBe(2);
    expect(result.cart?.total).toBe(99.8);
  });
});

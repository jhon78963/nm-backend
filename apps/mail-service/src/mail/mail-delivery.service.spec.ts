import { Test, TestingModule } from '@nestjs/testing';
import { ConfigService } from '@nestjs/config';

import { EcommerceMailTemplate } from '@app/mail-client';

import { MailDeliveryService } from './mail-delivery.service';

describe('MailDeliveryService', () => {
  let service: MailDeliveryService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        MailDeliveryService,
        {
          provide: ConfigService,
          useValue: {
            get: jest.fn((key: string, fallback?: string) => {
              const values: Record<string, string> = {
                MAIL_FROM_EMAIL: 'noreply@test.com',
                ECOMMERCE_STORE_URL: 'http://store.test',
              };

              return values[key] ?? fallback ?? '';
            }),
          },
        },
      ],
    }).compile();

    service = module.get(MailDeliveryService);
  });

  it('entrega correo en modo dry-run cuando Zoho no está configurado', async () => {
    const result = await service.deliverEcommerceMail({
      template: EcommerceMailTemplate.ORDER_CONFIRMATION,
      to: 'cliente@test.com',
      data: {
        customerName: 'Ana',
        orderNumber: 'NM-1001',
        items: [],
        subtotal: 0,
        shippingTotal: 0,
        total: 0,
        shippingMethodTitle: 'Delivery',
        paymentMethodTitle: 'Transferencia',
        shippingAddress: {},
        trackUrl: 'http://store.test/pedido',
        storeUrl: 'http://store.test',
      },
    });

    expect(result).toEqual({
      success: true,
      dryRun: true,
      template: EcommerceMailTemplate.ORDER_CONFIRMATION,
      to: 'cliente@test.com',
    });
  });
});

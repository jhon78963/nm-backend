import { ConfigService } from '@nestjs/config';
import { Test, TestingModule } from '@nestjs/testing';

import { EcommerceMailTemplate, MailClientService } from '@app/mail-client';

import { EcommerceMailNotificationsService } from './ecommerce-mail-notifications.service';

const mockMailClient = {
  sendEcommerceMail: jest.fn().mockResolvedValue(undefined),
};

function buildConfig(values: Record<string, string | undefined> = {}) {
  return {
    get: jest.fn((key: string, fallback?: string) => {
      if (key in values) return values[key];
      return fallback;
    }),
  };
}

describe('EcommerceMailNotificationsService', () => {
  let service: EcommerceMailNotificationsService;

  beforeEach(async () => {
    mockMailClient.sendEcommerceMail.mockClear();

    const module: TestingModule = await Test.createTestingModule({
      providers: [
        EcommerceMailNotificationsService,
        {
          provide: ConfigService,
          useValue: buildConfig({
            ECOMMERCE_STORE_URL: 'http://store.test',
            ERP_PANEL_URL: 'https://erp.test',
            MAIL_SUPPORT_EMAIL: 'ops@test.com',
          }),
        },
        { provide: MailClientService, useValue: mockMailClient },
      ],
    }).compile();

    service = module.get(EcommerceMailNotificationsService);
  });

  it('notifica al equipo ERP con plantilla order.staff-new', async () => {
    await service.sendStaffNewOrderAlert({
      id: 'order-uuid-1',
      orderNumber: 'NM-1001',
      email: 'cliente@test.com',
      status: 'pending',
      paymentStatus: 'pending',
      shippingAddress: { firstName: 'Ana', lastName: 'Pérez' },
      shippingMethodTitle: 'Delivery Lima',
      paymentMethodTitle: 'Transferencia',
      subtotal: 120,
      shippingTotal: 10,
      couponDiscount: 0,
      total: 130,
      items: [
        {
          nameSnapshot: 'Polo',
          quantity: 2,
          unitPrice: 60,
          subtotal: 120,
        },
      ],
    });

    expect(mockMailClient.sendEcommerceMail).toHaveBeenCalledWith({
      template: EcommerceMailTemplate.ORDER_STAFF_NEW,
      to: 'ops@test.com',
      data: expect.objectContaining({
        orderNumber: 'NM-1001',
        orderId: 'order-uuid-1',
        customerName: 'Ana Pérez',
        customerEmail: 'cliente@test.com',
        total: 130,
        itemCount: 2,
        orderUrl: 'https://erp.test/ecommerce/orders/order-uuid-1',
        storeUrl: 'http://store.test',
      }),
    });
  });
});

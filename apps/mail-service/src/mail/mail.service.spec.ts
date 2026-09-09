jest.mock('@nestjs/bullmq', () => {
  const { Inject } = require('@nestjs/common');

  return {
    InjectQueue: (name: string) => Inject(`BullQueue_${name}`),
    Processor: () => () => undefined,
    getQueueToken: (name?: string) => `BullQueue_${name}`,
  };
});

import { BadRequestException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { Test, TestingModule } from '@nestjs/testing';
import { getQueueToken } from '@nestjs/bullmq';

import { EcommerceMailTemplate } from '@app/mail-client';

import { MAIL_QUEUE } from './mail-queue.constants';
import { MailDeliveryService } from './mail-delivery.service';
import { MailService } from './mail.service';

const mockDeliveryService = {
  deliverEcommerceMail: jest.fn(),
};

describe('MailService', () => {
  let service: MailService;

  beforeEach(async () => {
    jest.clearAllMocks();
    mockDeliveryService.deliverEcommerceMail.mockResolvedValue({
      success: true,
      dryRun: true,
      template: EcommerceMailTemplate.ORDER_STAFF_NEW,
      to: 'ops@test.com',
    });

    const module: TestingModule = await Test.createTestingModule({
      providers: [
        MailService,
        {
          provide: ConfigService,
          useValue: {
            get: jest.fn((key: string, fallback?: string) => {
              if (key === 'MAIL_QUEUE_ENABLED') return 'false';
              return fallback;
            }),
          },
        },
        { provide: MailDeliveryService, useValue: mockDeliveryService },
        {
          provide: getQueueToken(MAIL_QUEUE),
          useValue: { add: jest.fn() },
        },
      ],
    }).compile();

    service = module.get(MailService);
  });

  it('envía correo de forma síncrona cuando la cola está deshabilitada', async () => {
    const payload = {
      template: EcommerceMailTemplate.ORDER_STAFF_NEW,
      to: 'ops@test.com',
      data: {
        orderNumber: 'NM-1001',
        orderId: 'order-uuid',
        customerName: 'Ana',
        customerEmail: 'cliente@test.com',
        total: 120,
        paymentMethodTitle: 'Transferencia',
        shippingMethodTitle: 'Delivery',
        itemCount: 2,
        orderUrl: 'http://erp.test/ecommerce/orders/order-uuid',
        storeUrl: 'http://store.test',
      },
    };

    const result = await service.sendEcommerceMail(payload);

    expect(mockDeliveryService.deliverEcommerceMail).toHaveBeenCalledWith(payload);
    expect(result).toEqual(
      expect.objectContaining({
        success: true,
        dryRun: true,
        queued: false,
      }),
    );
  });

  it('rechaza destinatario vacío', async () => {
    await expect(
      service.sendEcommerceMail({
        template: EcommerceMailTemplate.ORDER_STAFF_NEW,
        to: '   ',
        data: {},
      }),
    ).rejects.toBeInstanceOf(BadRequestException);
  });

  it('lista plantillas ecommerce soportadas', () => {
    const templates = service.listTemplates();

    expect(templates).toContain(EcommerceMailTemplate.ORDER_CONFIRMATION);
    expect(templates).toContain(EcommerceMailTemplate.ORDER_STAFF_NEW);
  });
});

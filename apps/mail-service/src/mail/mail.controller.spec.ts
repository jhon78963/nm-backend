jest.mock('@nestjs/bullmq', () => {
  const { Inject } = require('@nestjs/common');

  return {
    InjectQueue: (name: string) => Inject(`BullQueue_${name}`),
    Processor: () => () => undefined,
    getQueueToken: (name?: string) => `BullQueue_${name}`,
  };
});

import { Test, TestingModule } from '@nestjs/testing';

import { EcommerceMailTemplate } from '@app/mail-client';

import { MailServiceKeyGuard } from '../guards/mail-service-key.guard';
import { MailController } from './mail.controller';
import { MailService } from './mail.service';

describe('MailController', () => {
  let controller: MailController;
  const mockMailService = {
    listTemplates: jest.fn(),
    sendEcommerceMail: jest.fn(),
  };

  beforeEach(async () => {
    jest.clearAllMocks();

    const module: TestingModule = await Test.createTestingModule({
      controllers: [MailController],
      providers: [{ provide: MailService, useValue: mockMailService }],
    })
      .overrideGuard(MailServiceKeyGuard)
      .useValue({ canActivate: () => true })
      .compile();

    controller = module.get(MailController);
  });

  it('expone plantillas ecommerce disponibles', () => {
    mockMailService.listTemplates.mockReturnValue([
      EcommerceMailTemplate.ORDER_CONFIRMATION,
      EcommerceMailTemplate.ORDER_STAFF_NEW,
    ]);

    expect(controller.listTemplates()).toEqual({
      templates: [
        EcommerceMailTemplate.ORDER_CONFIRMATION,
        EcommerceMailTemplate.ORDER_STAFF_NEW,
      ],
    });
  });

  it('delega envío interno al MailService', async () => {
    mockMailService.sendEcommerceMail.mockResolvedValue({
      success: true,
      dryRun: true,
      queued: false,
    });

    const dto = {
      template: EcommerceMailTemplate.ORDER_STAFF_NEW,
      to: 'ops@test.com',
      data: { orderNumber: 'NM-1001' },
    };

    await expect(controller.sendEcommerce(dto as never)).resolves.toEqual({
      success: true,
      dryRun: true,
      queued: false,
    });
    expect(mockMailService.sendEcommerceMail).toHaveBeenCalledWith(dto);
  });
});

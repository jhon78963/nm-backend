import { Test, TestingModule } from '@nestjs/testing';

import { DatabaseService } from '@app/database';

import { EcommerceMailNotificationsService } from '../mail/ecommerce-mail-notifications.service';
import { EcommerceOrderEventsService } from './ecommerce-order-events.service';

const mockDb = {
  ecommerceCustomer: {
    findFirst: jest.fn(),
  },
  ecommerceCustomerNotificationSetting: {
    findUnique: jest.fn(),
  },
  ecommerceCustomerNotification: {
    create: jest.fn(),
    findMany: jest.fn().mockResolvedValue([]),
  },
};

const mockMailNotifications = {
  sendOrderConfirmation: jest.fn().mockResolvedValue(undefined),
  sendStaffNewOrderAlert: jest.fn().mockResolvedValue(undefined),
  sendOrderStatusChange: jest.fn().mockResolvedValue(undefined),
};

const sampleOrder = {
  id: 'order-uuid-1',
  orderNumber: 'NM-1001',
  email: 'cliente@test.com',
  customerId: 'customer-uuid-1',
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
      quantity: 1,
      unitPrice: 120,
      subtotal: 120,
    },
  ],
};

describe('EcommerceOrderEventsService', () => {
  let service: EcommerceOrderEventsService;

  beforeEach(async () => {
    jest.clearAllMocks();
    mockDb.ecommerceCustomerNotificationSetting.findUnique.mockResolvedValue(null);

    const module: TestingModule = await Test.createTestingModule({
      providers: [
        EcommerceOrderEventsService,
        { provide: DatabaseService, useValue: mockDb },
        { provide: EcommerceMailNotificationsService, useValue: mockMailNotifications },
      ],
    }).compile();

    service = module.get(EcommerceOrderEventsService);
  });

  it('publica pedido creado con notificación al cliente y al equipo ERP', async () => {
    await service.publishOrderCreated(sampleOrder);

    expect(mockDb.ecommerceCustomerNotification.create).toHaveBeenCalled();
    expect(mockMailNotifications.sendOrderConfirmation).toHaveBeenCalledWith(sampleOrder);
    expect(mockMailNotifications.sendStaffNewOrderAlert).toHaveBeenCalledWith(sampleOrder);
  });
});

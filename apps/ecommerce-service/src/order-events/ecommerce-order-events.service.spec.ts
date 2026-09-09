import { Test, TestingModule } from '@nestjs/testing';

import { DatabaseService } from '@app/database';

import { EcommerceInvoicingService } from '../ecommerce-invoicing/ecommerce-invoicing.service';
import { EcommerceMailNotificationsService } from '../mail/ecommerce-mail-notifications.service';
import { CheckoutEventPublisher } from '@app/event-bus';
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
  ecommerceOrder: {
    findFirst: jest.fn(),
  },
};

const mockMailNotifications = {
  sendOrderConfirmation: jest.fn().mockResolvedValue(undefined),
  sendStaffNewOrderAlert: jest.fn().mockResolvedValue(undefined),
  sendOrderStatusChange: jest.fn().mockResolvedValue(undefined),
};

const mockInvoicingService = {
  emitForPaidOrder: jest.fn().mockResolvedValue(undefined),
};

const mockCheckoutEvents = {
  publishEcommerceOrderPaid: jest.fn().mockResolvedValue(undefined),
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
        { provide: EcommerceInvoicingService, useValue: mockInvoicingService },
        { provide: CheckoutEventPublisher, useValue: mockCheckoutEvents },
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

  it('dispara facturación SUNAT y publica evento checkout cuando el pago pasa a paid', async () => {
    mockDb.ecommerceOrder.findFirst.mockResolvedValue({
      warehouseId: 'warehouse-1',
      total: 130,
      items: [{ productSizeId: 'ps-1', colorId: 'color-1' }],
    });

    await service.publishOrderUpdated({
      previous: { ...sampleOrder, paymentStatus: 'pending' },
      current: { ...sampleOrder, paymentStatus: 'paid' },
      source: 'culqi_charge',
    });

    expect(mockInvoicingService.emitForPaidOrder).toHaveBeenCalledWith('order-uuid-1');
    expect(mockCheckoutEvents.publishEcommerceOrderPaid).toHaveBeenCalledWith({
      warehouseId: 'warehouse-1',
      referenceId: 'order-uuid-1',
      referenceLabel: 'NM-1001',
      totalAmount: 130,
      variants: [{ productSizeId: 'ps-1', colorId: 'color-1' }],
    });
  });
});

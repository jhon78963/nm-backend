import { Test, TestingModule } from '@nestjs/testing';
import { ConfigService } from '@nestjs/config';

import { DatabaseService } from '@app/database';

import { EcommerceInvoicingService } from './ecommerce-invoicing.service';
import { InvoicingClientService } from './invoicing-client.service';

const mockDb = {
  ecommerceOrder: {
    findFirst: jest.fn(),
  },
  warehouse: {
    findFirst: jest.fn(),
  },
  user: {
    findFirst: jest.fn(),
  },
  $transaction: jest.fn(),
  sale: {
    update: jest.fn(),
  },
};

const mockInvoicingClient = {
  sendInvoice: jest.fn(),
  fetchInvoicePdf: jest.fn(),
};

describe('EcommerceInvoicingService', () => {
  let service: EcommerceInvoicingService;

  beforeEach(async () => {
    jest.clearAllMocks();

    const module: TestingModule = await Test.createTestingModule({
      providers: [
        EcommerceInvoicingService,
        { provide: DatabaseService, useValue: mockDb },
        { provide: ConfigService, useValue: { get: jest.fn() } },
        { provide: InvoicingClientService, useValue: mockInvoicingClient },
      ],
    }).compile();

    service = module.get(EcommerceInvoicingService);
  });

  it('no emite comprobante si el pedido ya tiene saleId', async () => {
    mockDb.ecommerceOrder.findFirst.mockResolvedValue({
      id: 'order-1',
      paymentStatus: 'paid',
      saleId: 'sale-1',
      items: [],
    });

    await service.emitForPaidOrder('order-1');

    expect(mockDb.$transaction).not.toHaveBeenCalled();
    expect(mockInvoicingClient.sendInvoice).not.toHaveBeenCalled();
  });

  it('no emite comprobante si el pedido no está pagado', async () => {
    mockDb.ecommerceOrder.findFirst.mockResolvedValue({
      id: 'order-1',
      paymentStatus: 'pending',
      saleId: null,
      items: [],
    });

    await service.emitForPaidOrder('order-1');

    expect(mockDb.$transaction).not.toHaveBeenCalled();
  });
});

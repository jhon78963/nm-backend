import { Test, TestingModule } from '@nestjs/testing';
import { BadRequestException, UnprocessableEntityException } from '@nestjs/common';
import { CheckoutService } from './checkout.service';
import { SunatService } from '../sunat/sunat.service';
import { DocumentSeriesService } from '../sunat/document-series.service';
import { FiscalConfigService } from '../fiscal/fiscal-config.service';
import { DatabaseService } from '@app/database';
import { faker } from '@faker-js/faker';
import { DocumentType, PaymentMethod } from './dto/checkout.dto';

// ─── Factories ────────────────────────────────────────────────────────────────

function makeItem(overrides = {}) {
  return {
    productSizeId: faker.string.uuid(),
    colorId: faker.string.uuid(),
    quantity: 2,
    unitPrice: 45.00,
    ...overrides,
  };
}

function makePayment(amount: number) {
  return { method: PaymentMethod.CASH, amount };
}

function makeCheckoutDto(overrides = {}) {
  const items = [makeItem()];
  const total = items.reduce((s, i) => s + i.unitPrice * i.quantity, 0);
  return {
    warehouseId: faker.string.uuid(),
    documentType: DocumentType.TICKET,
    items,
    payments: [makePayment(total)],
    ...overrides,
  };
}

// ─── Mocks ────────────────────────────────────────────────────────────────────

function buildTx(saleId = faker.string.uuid()) {
  return {
    sale: { create: jest.fn().mockResolvedValue({ id: saleId, fullInvoiceNumber: null, totalAmount: 90, documentType: 'TICKET', sunatStatus: null }) },
    saleDetail: { create: jest.fn().mockResolvedValue({}) },
    salePayment: { createMany: jest.fn().mockResolvedValue({ count: 1 }) },
    inventoryBalance: {
      findFirst: jest.fn().mockResolvedValue({ quantity: 50 }),
      update: jest.fn().mockResolvedValue({ quantity: 48 }),
    },
    inventoryMovement: { create: jest.fn().mockResolvedValue({}) },
    productSize: {
      findFirst: jest.fn().mockResolvedValue({
        id: faker.string.uuid(),
        product: { name: 'Polo Test' },
        size: { description: 'M' },
      }),
    },
    color: { findFirst: jest.fn().mockResolvedValue({ id: faker.string.uuid(), description: 'Rojo' }) },
    documentSeries: { update: jest.fn().mockResolvedValue({}) },
  };
}

const mockDb = {
  $transaction: jest.fn(),
  inventoryBalance: { findFirst: jest.fn() },
  sale: { update: jest.fn() },
  electronicDocumentLog: { create: jest.fn() },
};

const mockSunat = { emit: jest.fn(), void: jest.fn() };

const mockDocSeries = {
  getNextNumber: jest.fn().mockResolvedValue({ serie: 'B001', nextNumber: 1 }),
  incrementNumber: jest.fn(),
};

const mockFiscalConfig = {
  getForWarehouse: jest.fn().mockResolvedValue({
    tenantElectronicInvoicingEnabled: true,
    warehouseElectronicInvoicingEnabled: true,
    electronicInvoicingEnabled: true,
    series: { boleta: 'B001', factura: 'F001' },
  }),
};

// ═══════════════════════════════════════════════════════════════════════════════
// SUITE: CheckoutService
// ═══════════════════════════════════════════════════════════════════════════════

describe('CheckoutService', () => {
  let service: CheckoutService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        CheckoutService,
        { provide: DatabaseService, useValue: mockDb },
        { provide: SunatService, useValue: mockSunat },
        { provide: DocumentSeriesService, useValue: mockDocSeries },
        { provide: FiscalConfigService, useValue: mockFiscalConfig },
      ],
    }).compile();

    service = module.get<CheckoutService>(CheckoutService);
    jest.clearAllMocks();
  });

  // ── process (checkout exitoso) ────────────────────────────────────────────

  describe('process()', () => {
    it('crea Sale + Details + Payments + movements en una transacción atómica', async () => {
      const tx = buildTx();
      mockDb.inventoryBalance.findFirst.mockResolvedValue({ quantity: 50 });
      mockDb.$transaction.mockImplementation((fn: (tx: any) => unknown) => fn(tx));

      const dto = makeCheckoutDto();
      const result = await service.process(dto, faker.string.uuid());

      expect(tx.sale.create).toHaveBeenCalledTimes(1);
      expect(tx.saleDetail.create).toHaveBeenCalledTimes(dto.items.length);
      expect(tx.salePayment.createMany).toHaveBeenCalledTimes(1);
      expect(tx.inventoryMovement.create).toHaveBeenCalledTimes(dto.items.length);
      expect(result).toHaveProperty('sale.id');
      expect(result).toHaveProperty('ticketUrl');
    });

    it('lanza BadRequestException si pagos ≠ total de ítems', async () => {
      const dto = makeCheckoutDto({
        payments: [makePayment(999)], // total incorrecto
      });

      await expect(service.process(dto, faker.string.uuid())).rejects.toThrow(
        BadRequestException,
      );
    });

    it('lanza UnprocessableEntityException si hay stock insuficiente', async () => {
      mockDb.inventoryBalance.findFirst.mockResolvedValue({ quantity: 1 }); // Solo 1 disponible

      const dto = makeCheckoutDto({
        items: [makeItem({ quantity: 5 })], // Pide 5
        payments: [makePayment(5 * 45)],
      });

      await expect(service.process(dto, faker.string.uuid())).rejects.toThrow(
        UnprocessableEntityException,
      );
    });

    it('calcula IGV correcto para BOLETA (18%)', async () => {
      const tx = buildTx();
      mockDb.inventoryBalance.findFirst.mockResolvedValue({ quantity: 50 });
      mockDb.$transaction.mockImplementation((fn: (tx: any) => unknown) => fn(tx));
      mockDocSeries.getNextNumber.mockResolvedValue({ serie: 'B001', nextNumber: 1 });
      mockSunat.emit.mockResolvedValue({ status: 'ACCEPTED', invoiceNumber: 'B001-00000001' });

      const total = 118; // S/ 118 → taxableBase = 100, igv = 18
      const dto = makeCheckoutDto({
        documentType: DocumentType.BOLETA,
        items: [makeItem({ quantity: 1, unitPrice: 118 })],
        payments: [makePayment(118)],
      });

      await service.process(dto, faker.string.uuid());

      expect(tx.sale.create).toHaveBeenCalledWith(
        expect.objectContaining({
          data: expect.objectContaining({
            taxableBase: expect.closeTo(100, 0),
            igv: expect.closeTo(18, 0),
          }),
        }),
      );
    });

    it('llama a SunatService.emit solo si documentType ≠ TICKET', async () => {
      const tx = buildTx();
      mockDb.inventoryBalance.findFirst.mockResolvedValue({ quantity: 50 });
      mockDb.$transaction.mockImplementation((fn: (tx: any) => unknown) => fn(tx));
      mockSunat.emit.mockResolvedValue({ status: 'ACCEPTED' });

      // TICKET → NO emite
      await service.process(makeCheckoutDto({ documentType: DocumentType.TICKET }), faker.string.uuid());
      expect(mockSunat.emit).not.toHaveBeenCalled();

      jest.clearAllMocks();
      mockDb.inventoryBalance.findFirst.mockResolvedValue({ quantity: 50 });
      mockDb.$transaction.mockImplementation((fn: (tx: any) => unknown) => fn(tx));
      mockDocSeries.getNextNumber.mockResolvedValue({ serie: 'B001', nextNumber: 1 });
      mockSunat.emit.mockResolvedValue({ status: 'ACCEPTED' });

      // BOLETA → SÍ emite
      await service.process(makeCheckoutDto({ documentType: DocumentType.BOLETA }), faker.string.uuid());
      expect(mockSunat.emit).toHaveBeenCalledTimes(1);
    });

    it('NO revierte la venta si SunatService.emit falla (venta sigue registrada)', async () => {
      const tx = buildTx();
      mockDb.inventoryBalance.findFirst.mockResolvedValue({ quantity: 50 });
      mockDb.$transaction.mockImplementation((fn: (tx: any) => unknown) => fn(tx));
      mockDocSeries.getNextNumber.mockResolvedValue({ serie: 'B001', nextNumber: 1 });
      mockSunat.emit.mockRejectedValue(new Error('SUNAT no disponible'));

      const dto = makeCheckoutDto({ documentType: DocumentType.BOLETA });
      const result = await service.process(dto, faker.string.uuid());

      // La venta existe pero el SUNAT status indica pendiente
      expect(result.sale).toBeDefined();
      expect(result.sunat?.status).toBe('PENDING_EMISSION');
    });

    it('NO llama a SunatService si documentType es TICKET (sin IGV)', async () => {
      const tx = buildTx();
      mockDb.inventoryBalance.findFirst.mockResolvedValue({ quantity: 50 });
      mockDb.$transaction.mockImplementation((fn: (tx: any) => unknown) => fn(tx));

      await service.process(makeCheckoutDto(), faker.string.uuid());

      expect(tx.sale.create).toHaveBeenCalledWith(
        expect.objectContaining({
          data: expect.objectContaining({ taxableBase: null, igv: null }),
        }),
      );
    });
  });
});

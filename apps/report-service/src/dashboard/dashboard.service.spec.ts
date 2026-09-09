import { Test, TestingModule } from '@nestjs/testing';
import { DashboardService } from './dashboard.service';
import { DatabaseService } from '@app/database';
import { faker } from '@faker-js/faker';

// ─── Mock DB ──────────────────────────────────────────────────────────────────

const mockDb = {
  sale:             { count: jest.fn(), aggregate: jest.fn() },
  inventoryBalance: { count: jest.fn() },
  purchase:         { count: jest.fn() },
  customer:         { count: jest.fn() },
  cashMovement:     { aggregate: jest.fn() },
  teamPayment:      { aggregate: jest.fn() },
  saleDetail:       { groupBy: jest.fn() },
};

// ═══════════════════════════════════════════════════════════════════════════════
// SUITE: DashboardService
// ═══════════════════════════════════════════════════════════════════════════════

describe('DashboardService', () => {
  let service: DashboardService;
  const warehouseId = faker.string.uuid();

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        DashboardService,
        { provide: DatabaseService, useValue: mockDb },
      ],
    }).compile();
    service = module.get<DashboardService>(DashboardService);
    jest.resetAllMocks();
  });

  function setupMocks(opts: {
    salesToday?: number;
    revToday?: number;
    sunatPending?: number;
    salesMonth?: number;
    revMonth?: number;
    lowStock?: number;
    pendingPurchases?: number;
    customers?: number;
    cashToday?: number;
    payroll?: number;
  } = {}) {
    mockDb.sale.count
      .mockResolvedValueOnce(opts.salesToday   ?? 5)    // today
      .mockResolvedValueOnce(opts.sunatPending ?? 0)    // sunat pending
      .mockResolvedValueOnce(opts.salesMonth   ?? 120); // month
    mockDb.sale.aggregate
      .mockResolvedValueOnce({ _sum: { totalAmount: opts.revToday  ?? 450 } })
      .mockResolvedValueOnce({ _sum: { totalAmount: opts.revMonth  ?? 12000 } });
    mockDb.inventoryBalance.count.mockResolvedValue(opts.lowStock  ?? 8);
    mockDb.purchase.count.mockResolvedValue(opts.pendingPurchases ?? 3);
    mockDb.customer.count.mockResolvedValue(opts.customers        ?? 85);
    mockDb.cashMovement.aggregate.mockResolvedValue({ _sum: { amount: opts.cashToday ?? 500 } });
    mockDb.teamPayment.aggregate.mockResolvedValue({ _sum: { amount: opts.payroll    ?? 5400 } });
    mockDb.saleDetail.groupBy.mockResolvedValue([]);
  }

  describe('getMetrics()', () => {
    it('retorna métricas del día y del mes en paralelo', async () => {
      setupMocks({ salesToday: 8, revToday: 720, salesMonth: 150, revMonth: 18000 });

      const result = await service.getMetrics(warehouseId);

      expect(result.sales.today.count).toBe(8);
      expect(result.sales.today.revenue).toBe(720);
      expect(result.sales.month.count).toBe(150);
      expect(result.sales.month.revenue).toBe(18000);
    });

    it('incluye conteo de stock con bajo inventario (< 5 unidades)', async () => {
      setupMocks({ lowStock: 12 });

      const result = await service.getMetrics(warehouseId);

      expect(result.inventory.lowStockItems).toBe(12);
    });

    it('incluye tareas pendientes del día (comprobantes SUNAT)', async () => {
      setupMocks({ sunatPending: 4 });

      const result = await service.getMetrics(warehouseId);

      expect(result.tasks.pendingToday).toBe(4);
    });

    it('retorna 0 en todos los campos cuando no hay actividad', async () => {
      setupMocks({ salesToday: 0, revToday: 0, salesMonth: 0, revMonth: 0, lowStock: 0, cashToday: 0, payroll: 0 });

      const result = await service.getMetrics(warehouseId);

      expect(result.sales.today.count).toBe(0);
      expect(result.sales.today.revenue).toBe(0);
      expect(result.payroll.monthTotal).toBe(0);
    });

    it('retorna topProducts vacío cuando no hay ventas en el mes', async () => {
      setupMocks();
      mockDb.saleDetail.groupBy.mockResolvedValue([]);

      const result = await service.getMetrics(warehouseId);

      expect(result.topProducts).toHaveLength(0);
    });

    it('la estructura de respuesta contiene todas las claves esperadas por el frontend Angular', async () => {
      setupMocks();

      const result = await service.getMetrics(warehouseId);

      expect(result).toMatchObject({
        today: expect.any(String),
        month: expect.any(String),
        sales: { today: expect.any(Object), month: expect.any(Object) },
        inventory: { lowStockItems: expect.any(Number) },
        purchases: { pendingThisMonth: expect.any(Number) },
        customers: { total: expect.any(Number) },
        tasks: { pendingToday: expect.any(Number) },
        cashflow: { todayMovements: expect.any(Number) },
        payroll: { monthTotal: expect.any(Number) },
        topProducts: expect.any(Array),
      });
    });
  });
});

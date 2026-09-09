import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { CashflowService } from './cashflow.service';
import { DatabaseService } from '@app/database';
import { faker } from '@faker-js/faker';
import { MovementType, CashPaymentMethod } from './dto/create-cash-movement.dto';

// ─── Factories ────────────────────────────────────────────────────────────────

function makeMovement(overrides = {}) {
  return {
    id: faker.string.uuid(),
    warehouseId: faker.string.uuid(),
    type: 'INCOME',
    amount: 150.00,
    category: 'Venta directa',
    paymentMethod: 'CASH',
    date: new Date(),
    accountingMonth: '2026-08',
    isDeleted: false,
    vouchers: [],
    ...overrides,
  };
}

// ─── Mocks ────────────────────────────────────────────────────────────────────

const mockDb = {
  cashMovement: {
    create: jest.fn(),
    update: jest.fn(),
    findFirst: jest.fn(),
    findMany: jest.fn(),
    aggregate: jest.fn(),
    groupBy: jest.fn(),
  },
  sale: {
    findMany: jest.fn(),
  },
  warehouse: {
    findFirst: jest.fn(),
  },
};

// ═══════════════════════════════════════════════════════════════════════════════
// SUITE: CashflowService
// ═══════════════════════════════════════════════════════════════════════════════

describe('CashflowService', () => {
  let service: CashflowService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        CashflowService,
        { provide: DatabaseService, useValue: mockDb },
      ],
    }).compile();

    service = module.get<CashflowService>(CashflowService);
    jest.clearAllMocks();
  });

  // ── create ────────────────────────────────────────────────────────────────

  describe('create()', () => {
    it('crea un movimiento de caja con todos los campos requeridos', async () => {
      const movement = makeMovement();
      mockDb.cashMovement.create.mockResolvedValue(movement);

      const dto = {
        type: MovementType.INCOME,
        amount: 150.00,
        category: 'Venta directa',
        paymentMethod: CashPaymentMethod.CASH,
        date: '2026-08-25',
        accountingMonth: '2026-08',
      };

      const warehouseId = movement.warehouseId;
      const result = await service.create(dto, warehouseId, faker.string.uuid());

      expect(mockDb.cashMovement.create).toHaveBeenCalledWith(
        expect.objectContaining({
          data: expect.objectContaining({
            warehouseId,
            type: 'INCOME',
            amount: 150.00,
            category: 'Venta directa',
          }),
        }),
      );
      expect(result.id).toBe(movement.id);
    });
  });

  // ── delete (soft-delete) ──────────────────────────────────────────────────

  describe('delete()', () => {
    it('hace soft-delete (isDeleted: true), nunca elimina físicamente', async () => {
      const movement = makeMovement();
      mockDb.cashMovement.findFirst.mockResolvedValue(movement);
      mockDb.cashMovement.update.mockResolvedValue({ ...movement, isDeleted: true });

      await service.delete(movement.id);

      expect(mockDb.cashMovement.update).toHaveBeenCalledWith({
        where: { id: movement.id },
        data: { isDeleted: true },
      });
    });

    it('lanza NotFoundException si el movimiento no existe', async () => {
      mockDb.cashMovement.findFirst.mockResolvedValue(null);

      await expect(service.delete(faker.string.uuid())).rejects.toThrow(NotFoundException);
    });
  });

  // ── getDaily ──────────────────────────────────────────────────────────────

  describe('getDaily()', () => {
    it('incluye ventas del día y movimientos de caja en formato legacy', async () => {
      const warehouseId = faker.string.uuid();
      mockDb.warehouse.findFirst.mockResolvedValue({ baseCash: 100 });
      mockDb.sale.findMany.mockResolvedValue([
        {
          id: 'sale-1',
          code: 'V-TEST',
          paymentMethod: 'CASH',
          createdAt: new Date('2026-08-27T04:15:00.000Z'),
          payments: [{ method: 'CASH', amount: 90 }],
          details: [
            {
              productNameSnapshot: 'test',
              sizeSnapshot: 'ESTÁNDAR',
              colorSnapshot: 'Azul',
            },
          ],
        },
      ]);
      mockDb.cashMovement.findMany.mockResolvedValue([
        makeMovement({ type: 'EXPENSE', amount: 50, category: 'STORE' }),
      ]);

      const result = await service.getDaily(warehouseId, '2026-08-27');

      expect(result.data.lists.sales).toHaveLength(1);
      expect(result.data.lists.sales[0].amount).toBe(90);
      expect(result.data.lists.sales[0].payments).toEqual([{ method: 'CASH', amount: 90 }]);
      expect(result.data.summary.total_sales).toBe(90);
      expect(result.data.summary.total_expenses).toBe(50);
      expect(result.data.summary.opening_balance).toBe(100);
      expect(result.data.summary.closing_balance).toBe(140);
    });

    it('incluye el desglose de pagos en ventas mixtas', async () => {
      mockDb.warehouse.findFirst.mockResolvedValue({ baseCash: 100 });
      mockDb.sale.findMany.mockResolvedValue([
        {
          id: 'sale-mixed',
          code: 'V-MIXED',
          paymentMethod: 'MIXED',
          createdAt: new Date('2026-08-26T23:15:00.000Z'),
          payments: [
            { method: 'CASH', amount: 5 },
            { method: 'YAPE', amount: 40 },
          ],
          details: [
            {
              productNameSnapshot: 'test',
              sizeSnapshot: 'ESTÁNDAR',
              colorSnapshot: 'Arena',
            },
          ],
        },
      ]);
      mockDb.cashMovement.findMany.mockResolvedValue([]);

      const result = await service.getDaily(faker.string.uuid(), '2026-08-26');

      expect(result.data.lists.sales[0]).toMatchObject({
        amount: 45,
        method: 'MIXED',
        payments: [
          { method: 'CASH', amount: 5 },
          { method: 'YAPE', amount: 40 },
        ],
      });
    });

    it('retorna listas vacías si no hay ventas ni movimientos', async () => {
      mockDb.warehouse.findFirst.mockResolvedValue({ baseCash: 100 });
      mockDb.sale.findMany.mockResolvedValue([]);
      mockDb.cashMovement.findMany.mockResolvedValue([]);

      const result = await service.getDaily(faker.string.uuid(), '2026-08-01');

      expect(result.data.lists.sales).toEqual([]);
      expect(result.data.summary.total_sales).toBe(0);
      expect(result.data.summary.opening_balance).toBe(100);
      expect(result.data.summary.closing_balance).toBe(100);
    });
  });

  // ── getMonthlyAdminExpenses ───────────────────────────────────────────────

  describe('getMonthlyAdminExpenses()', () => {
    it('retorna gastos administrativos del mes con totales legacy', async () => {
      mockDb.cashMovement.findMany.mockResolvedValue([
        makeMovement({
          type: 'EXPENSE',
          category: 'ADMINISTRATIVE',
          amount: 450,
          description: 'Sueldo',
          accountingMonth: '2026-08',
        }),
        makeMovement({
          type: 'EXPENSE',
          category: 'ADMINISTRATIVE',
          amount: 70,
          description: 'Servicio',
          accountingMonth: '2026-08',
        }),
      ]);

      const result = await service.getMonthlyAdminExpenses('2026-08');

      expect(result.data.expenses).toHaveLength(2);
      expect(result.data.total_monthly_admin).toBe(520);
      expect(result.data.expenses[0]).toMatchObject({
        category: 'ADMINISTRATIVE',
        payment_method: 'CASH',
      });
    });
  });

  // ── getMonthlyReport ──────────────────────────────────────────────────────

  describe('getMonthlyReport()', () => {
    it('calcula netBalance = totalIncome - totalExpense', async () => {
      mockDb.cashMovement.aggregate
        .mockResolvedValueOnce({ _sum: { amount: 12000 }, _count: { id: 40 } }) // income
        .mockResolvedValueOnce({ _sum: { amount: 4500  }, _count: { id: 15 } }); // expense
      mockDb.cashMovement.groupBy
        .mockResolvedValueOnce([]) // byCategory
        .mockResolvedValueOnce([]); // byPaymentMethod

      const result = await service.getMonthlyReport('2026-08');

      expect(result.totalIncome).toBe(12000);
      expect(result.totalExpense).toBe(4500);
      expect(result.netBalance).toBe(7500);
    });

    it('filtra por warehouseId cuando se provee', async () => {
      const warehouseId = faker.string.uuid();
      mockDb.cashMovement.aggregate
        .mockResolvedValue({ _sum: { amount: 0 }, _count: { id: 0 } });
      mockDb.cashMovement.groupBy.mockResolvedValue([]);

      await service.getMonthlyReport('2026-08', warehouseId);

      expect(mockDb.cashMovement.aggregate).toHaveBeenCalledWith(
        expect.objectContaining({
          where: expect.objectContaining({ warehouseId }),
        }),
      );
    });
  });
});

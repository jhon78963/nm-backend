import { Test, TestingModule } from '@nestjs/testing';
import { DashboardController } from './dashboard.controller';
import { DashboardService } from './dashboard.service';
import type { AuthenticatedUser } from '@app/common/types/authenticated-user.type';

const mockUser: AuthenticatedUser = {
  id: 'user-uuid',
  username: 'admin',
  tenantId: 'tenant-uuid',
  warehouseId: 'warehouse-uuid',
  roles: ['Admin'],
  permissions: [],
  mustChangePassword: false,
};

const mockMetrics = {
  today: '2026-08-25',
  month: '2026-08',
  sales: {
    today: { count: 12, revenue: 4800 },
    month: { count: 248, revenue: 99200 },
  },
  inventory: { lowStockItems: 7 },
  purchases: { pendingThisMonth: 3 },
  customers: { total: 412 },
  tasks: { pendingToday: 2 },
  cashflow: { todayMovements: 1200 },
  payroll: { monthTotal: 14400 },
  topProducts: [
    { productSizeId: 'ps-uuid-1', unitsSold: 42 },
    { productSizeId: 'ps-uuid-2', unitsSold: 38 },
  ],
};

describe('DashboardController', () => {
  let controller: DashboardController;
  let service: jest.Mocked<DashboardService>;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [DashboardController],
      providers: [
        {
          provide: DashboardService,
          useValue: {
            getMetrics: jest.fn(),
          },
        },
      ],
    }).compile();

    controller = module.get<DashboardController>(DashboardController);
    service = module.get(DashboardService);
  });

  it('should be defined', () => {
    expect(controller).toBeDefined();
  });

  describe('getMetrics', () => {
    it('should return full dashboard metrics for the warehouse', async () => {
      service.getMetrics.mockResolvedValue(mockMetrics as any);
      const result = await controller.getMetrics(mockUser);
      expect(service.getMetrics).toHaveBeenCalledWith('warehouse-uuid');
      expect(result).toMatchObject({ today: '2026-08-25', month: '2026-08' });
    });

    it('should include sales, inventory, cashflow and payroll sections', async () => {
      service.getMetrics.mockResolvedValue(mockMetrics as any);
      const result = await controller.getMetrics(mockUser);
      expect(result).toHaveProperty('sales');
      expect(result).toHaveProperty('inventory');
      expect(result).toHaveProperty('cashflow');
      expect(result).toHaveProperty('payroll');
    });

    it('should return top 5 products sold this month', async () => {
      service.getMetrics.mockResolvedValue(mockMetrics as any);
      const result = await controller.getMetrics(mockUser);
      expect(result.topProducts).toHaveLength(2);
      expect(result.topProducts[0]).toHaveProperty('unitsSold');
    });

    it('should always use warehouseId from JWT, not from body', async () => {
      service.getMetrics.mockResolvedValue(mockMetrics as any);
      await controller.getMetrics(mockUser);
      expect(service.getMetrics).toHaveBeenCalledWith('warehouse-uuid');
    });
  });

  describe('getMetricsForFrontend', () => {
    it('should map backend metrics to the frontend contract', async () => {
      service.getMetrics.mockResolvedValue(mockMetrics as any);

      const result = await controller.getMetricsForFrontend(mockUser);

      expect(result).toEqual({
        todaySales: 12,
        todaySalesAmount: 4800,
        todayExpenses: 0,
        lowStockProducts: 7,
        pendingPurchases: 3,
        activeCustomers: 412,
        pendingTasks: 2,
      });
    });
  });
});

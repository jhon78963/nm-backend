import { Injectable } from '@nestjs/common';
import { DatabaseService } from '@app/database';
import dayjs from 'dayjs';

/**
 * DashboardService — Equivale a DashboardMetricsService de Laravel.
 * Agrega métricas clave del día y del mes para la pantalla principal del admin.
 * Todas las consultas se ejecutan en paralelo con Promise.all().
 */
@Injectable()
export class DashboardService {
  constructor(private readonly db: DatabaseService) {}

  async getMetrics(warehouseId: string) {
    const today      = dayjs().startOf('day').toDate();
    const todayEnd   = dayjs().endOf('day').toDate();
    const monthStart = dayjs().startOf('month').toDate();
    const monthStr   = dayjs().format('YYYY-MM');

    const [
      // Ventas de hoy
      salesToday,
      revenueTodayAgg,
      sunatPendingToday,

      // Ventas del mes
      salesMonth,
      revenueMonthAgg,

      // Stock con bajo inventario (quantity < 5)
      lowStockCount,

      // Compras pendientes de pago del mes
      purchasesMonth,

      // Clientes registrados
      customersCount,

      // Movimientos de caja del día
      cashToday,

      // Planilla del mes
      payrollMonth,

      // Top 5 productos más vendidos del mes
      topProducts,
    ] = await Promise.all([
      this.db.sale.count({
        where: { warehouseId, isDeleted: false, createdAt: { gte: today, lte: todayEnd } },
      }),
      this.db.sale.aggregate({
        where: { warehouseId, isDeleted: false, createdAt: { gte: today, lte: todayEnd } },
        _sum: { totalAmount: true },
      }),
      this.db.sale.count({
        where: {
          warehouseId,
          isDeleted: false,
          createdAt: { gte: today, lte: todayEnd },
          sunatStatus: { in: ['PENDING', 'REJECTED'] },
        },
      }),

      this.db.sale.count({
        where: { warehouseId, isDeleted: false, createdAt: { gte: monthStart } },
      }),
      this.db.sale.aggregate({
        where: { warehouseId, isDeleted: false, createdAt: { gte: monthStart } },
        _sum: { totalAmount: true },
      }),

      this.db.inventoryBalance.count({
        where: { warehouseId, quantity: { lt: 5, gt: 0 } },
      }),

      this.db.purchase.count({
        where: { warehouseId, isDeleted: false, status: 'REGISTERED', createdAt: { gte: monthStart } },
      }),

      this.db.customer.count({ where: { warehouseId, isDeleted: false } }),

      this.db.cashMovement.aggregate({
        where: { warehouseId, isDeleted: false, date: { gte: today, lte: todayEnd } },
        _sum: { amount: true },
      }),

      this.db.teamPayment.aggregate({
        where: { team: { warehouseId }, accountingMonth: monthStr },
        _sum: { amount: true },
      }),

      this.db.saleDetail.groupBy({
        by: ['productSizeId'],
        where: {
          sale: { warehouseId, isDeleted: false, createdAt: { gte: monthStart } },
        },
        _sum: { quantity: true },
        orderBy: { _sum: { quantity: 'desc' } },
        take: 5,
      }),
    ]);

    return {
      today: dayjs().format('YYYY-MM-DD'),
      month: monthStr,
      sales: {
        today: { count: salesToday, revenue: Number(revenueTodayAgg._sum.totalAmount ?? 0) },
        month: { count: salesMonth, revenue: Number(revenueMonthAgg._sum.totalAmount ?? 0) },
      },
      inventory: { lowStockItems: lowStockCount },
      purchases: { pendingThisMonth: purchasesMonth },
      customers: { total: customersCount },
      tasks: { pendingToday: sunatPendingToday },
      cashflow: { todayMovements: Number(cashToday._sum.amount ?? 0) },
      payroll: { monthTotal: Number(payrollMonth._sum.amount ?? 0) },
      topProducts: topProducts.map((p) => ({
        productSizeId: p.productSizeId,
        unitsSold: p._sum.quantity ?? 0,
      })),
    };
  }
}

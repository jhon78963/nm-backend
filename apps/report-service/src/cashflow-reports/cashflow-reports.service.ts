import { Injectable } from '@nestjs/common';
import { DatabaseService } from '@app/database';
import dayjs from 'dayjs';
import { resolveSalePayments } from '../reports/reports-sales.util';

export interface DailyCashflowListItem {
  id: string;
  type?: string;
  time: string;
  description: string;
  method: string;
  amount: number;
  date?: string | Date;
  payment_method?: string;
  payments?: Array<{ method: string; amount: number }>;
}

export interface DailyCashflowReport {
  success: true;
  data: {
    summary: {
      opening_balance: number;
      total_sales: number;
      total_incomes: number;
      total_expenses: number;
      closing_balance?: number;
      final_balance?: number;
    };
    lists: {
      sales: DailyCashflowListItem[];
      incomes: DailyCashflowListItem[];
      expenses: DailyCashflowListItem[];
    };
  };
}

const DEFAULT_PAYMENT_FILTERS = ['CASH', 'YAPE', 'CARD'] as const;
const DEFAULT_BASE_CASH = 100;

@Injectable()
export class CashflowReportsService {
  constructor(private readonly db: DatabaseService) {}

  async getDaily(
    warehouseId: string,
    date: string,
    activeFilters: string[] = [...DEFAULT_PAYMENT_FILTERS],
  ): Promise<DailyCashflowReport> {
    const from = dayjs(date).startOf('day').toDate();
    const to = dayjs(date).endOf('day').toDate();
    const filters = this.normalizePaymentFilters(activeFilters);

    const [warehouse, salesRows, movementModels] = await Promise.all([
      this.db.warehouse.findFirst({
        where: { id: warehouseId, isDeleted: false },
        select: { baseCash: true },
      }),
      this.db.sale.findMany({
        where: {
          warehouseId,
          isDeleted: false,
          status: 'COMPLETED',
          createdAt: { gte: from, lte: to },
        },
        include: {
          payments: { select: { method: true, amount: true } },
          details: {
            select: {
              productNameSnapshot: true,
              sizeSnapshot: true,
              colorSnapshot: true,
            },
          },
        },
        orderBy: { createdAt: 'desc' },
      }),
      this.db.cashMovement.findMany({
        where: {
          warehouseId,
          isDeleted: false,
          category: 'STORE',
          paymentMethod: { in: filters },
          date: { gte: from, lte: to },
        },
        orderBy: { date: 'desc' },
      }),
    ]);

    const sales: DailyCashflowListItem[] = salesRows.flatMap((sale) => {
      const payments = resolveSalePayments(sale);
      const totalAmount = payments.reduce((sum, payment) => sum + payment.amount, 0);

      if (totalAmount <= 0) {
        return [];
      }

      const itemsDescription = sale.details
        .map((detail) => {
          const color = detail.colorSnapshot ? ` | ${detail.colorSnapshot}` : '';
          return `${detail.productNameSnapshot} | ${detail.sizeSnapshot}${color}`;
        })
        .join(' + ');

      return [{
        id: sale.id,
        type: 'SALE',
        time: dayjs(sale.createdAt).format('HH:mm A'),
        description: `${sale.code ?? sale.id} | ${itemsDescription}`,
        method: this.resolveDisplayPaymentMethod(payments, sale.paymentMethod),
        amount: totalAmount,
        payments,
      }];
    });

    const incomes = movementModels
      .filter((movement) => movement.type === 'INCOME')
      .map((movement) => this.mapCashMovementListItem(movement));

    const expenses = movementModels
      .filter((movement) => movement.type === 'EXPENSE')
      .map((movement) => this.mapCashMovementListItem(movement));

    const totalSales = sales.reduce((sum, row) => sum + row.amount, 0);
    const totalIncomes = incomes.reduce((sum, row) => sum + row.amount, 0);
    const totalExpenses = expenses.reduce((sum, row) => sum + row.amount, 0);
    const baseCash = Number(warehouse?.baseCash ?? DEFAULT_BASE_CASH);
    const openingBalance = baseCash;
    const closingBalance = baseCash + totalSales + totalIncomes - totalExpenses;

    return {
      success: true,
      data: {
        summary: {
          opening_balance: openingBalance,
          total_sales: totalSales,
          total_incomes: totalIncomes,
          total_expenses: totalExpenses,
          closing_balance: closingBalance,
          final_balance: closingBalance,
        },
        lists: {
          sales,
          incomes,
          expenses,
        },
      },
    };
  }

  async getMonthlyAdminExpenses(accountingMonth: string) {
    const expenses = await this.db.cashMovement.findMany({
      where: {
        isDeleted: false,
        type: 'EXPENSE',
        category: 'ADMINISTRATIVE',
        accountingMonth,
      },
      include: {
        vouchers: { orderBy: { sortOrder: 'asc' } },
      },
      orderBy: { date: 'desc' },
    });

    const mapped = expenses.map((movement) => this.mapCashMovementResource(movement));
    const totalMonthlyAdmin = mapped.reduce((sum, movement) => sum + movement.amount, 0);

    return {
      success: true,
      data: {
        month: this.formatMonthLabel(accountingMonth),
        total_monthly_admin: totalMonthlyAdmin,
        expenses: mapped,
      },
    };
  }

  async getMonthlyAccumulatedExpenses(month: string) {
    const parsedMonth = dayjs(`${month}-01`);
    const from = parsedMonth.startOf('month').toDate();
    const to = parsedMonth.endOf('month').toDate();

    const expenses = await this.db.cashMovement.findMany({
      where: {
        isDeleted: false,
        type: 'EXPENSE',
        category: 'ACCUMULATED',
        date: { gte: from, lte: to },
      },
      include: {
        vouchers: { orderBy: { sortOrder: 'asc' } },
      },
      orderBy: { date: 'desc' },
    });

    const mapped = expenses.map((movement) => this.mapCashMovementResource(movement));
    const totalMonthlyAccumulated = mapped.reduce((sum, movement) => sum + movement.amount, 0);

    return {
      success: true,
      data: {
        month: this.formatMonthLabel(month),
        total_monthly_accumulated: totalMonthlyAccumulated,
        expenses: mapped,
      },
    };
  }

  async getMonthlyReport(accountingMonth: string, warehouseId?: string) {
    const where = {
      isDeleted: false,
      accountingMonth,
      ...(warehouseId && { warehouseId }),
    };

    const [incomeAgg, expenseAgg, byCategory, byPaymentMethod] = await Promise.all([
      this.db.cashMovement.aggregate({
        where: { ...where, type: 'INCOME' },
        _sum: { amount: true },
        _count: { id: true },
      }),
      this.db.cashMovement.aggregate({
        where: { ...where, type: 'EXPENSE' },
        _sum: { amount: true },
        _count: { id: true },
      }),
      this.db.cashMovement.groupBy({
        by: ['category', 'type'],
        where,
        _sum: { amount: true },
        orderBy: { _sum: { amount: 'desc' } },
      }),
      this.db.cashMovement.groupBy({
        by: ['paymentMethod'],
        where: { ...where, type: 'INCOME' },
        _sum: { amount: true },
      }),
    ]);

    return {
      accountingMonth,
      warehouseId,
      totalIncome: Number(incomeAgg._sum.amount ?? 0),
      totalExpense: Number(expenseAgg._sum.amount ?? 0),
      netBalance: Number(incomeAgg._sum.amount ?? 0) - Number(expenseAgg._sum.amount ?? 0),
      incomeCount: incomeAgg._count.id,
      expenseCount: expenseAgg._count.id,
      byCategory: byCategory.map((row) => ({
        category: row.category,
        type: row.type,
        amount: Number(row._sum.amount ?? 0),
      })),
      byPaymentMethod: Object.fromEntries(
        byPaymentMethod.map((row) => [row.paymentMethod, Number(row._sum.amount ?? 0)]),
      ),
    };
  }

  private resolveDisplayPaymentMethod(
    payments: Array<{ method: string; amount: number }>,
    headerMethod: string,
  ): string {
    const uniqueMethods = new Set(payments.map((payment) => payment.method));
    if (uniqueMethods.size > 1) {
      return 'MIXED';
    }

    if (payments.length === 1) {
      return payments[0].method;
    }

    return headerMethod;
  }

  private normalizePaymentFilters(filters: string[]): string[] {
    const normalized = filters
      .map((filter) => filter.trim().toUpperCase())
      .filter((filter) => filter.length > 0);

    if (!normalized.length) {
      return [...DEFAULT_PAYMENT_FILTERS];
    }

    const expanded = new Set<string>();
    for (const filter of normalized) {
      if (filter === 'EFECTIVO') {
        expanded.add('CASH');
        continue;
      }
      if (filter.includes('YAPE') || filter.includes('PLIN')) {
        expanded.add('YAPE');
        continue;
      }
      if (filter === 'TARJETA' || filter === 'CARD') {
        expanded.add('CARD');
        continue;
      }
      expanded.add(filter);
    }

    return [...expanded];
  }

  private mapCashMovementListItem(movement: {
    id: string;
    date: Date;
    description: string | null;
    paymentMethod: string;
    amount: unknown;
  }): DailyCashflowListItem {
    return {
      id: movement.id,
      time: dayjs(movement.date).format('HH:mm A'),
      description: movement.description ?? '',
      method: movement.paymentMethod,
      payment_method: movement.paymentMethod,
      amount: Number(movement.amount),
      date: movement.date,
    };
  }

  private mapCashMovementResource(movement: {
    id: string;
    type: string;
    category: string;
    amount: unknown;
    description: string | null;
    paymentMethod: string;
    date: Date;
    accountingMonth: string;
    vouchers: Array<{ voucherPath: string }>;
  }) {
    const voucherPaths = movement.vouchers.map((voucher) => voucher.voucherPath);

    return {
      id: movement.id,
      type: movement.type,
      category: movement.category,
      amount: Number(movement.amount),
      description: movement.description ?? '',
      payment_method: movement.paymentMethod,
      method: movement.paymentMethod,
      date: dayjs(movement.date).format('YYYY-MM-DD HH:mm:ss'),
      accounting_month: movement.accountingMonth,
      payroll_period: null,
      accounting_period_label: this.buildAccountingPeriodLabel(movement.accountingMonth),
      voucher_path: voucherPaths[0] ?? null,
      voucher_paths: voucherPaths,
    };
  }

  private buildAccountingPeriodLabel(accountingMonth: string): string {
    if (!accountingMonth) {
      return '—';
    }

    const [year, month] = accountingMonth.split('-').map(Number);
    if (!year || !month) {
      return accountingMonth;
    }

    const formatted = new Intl.DateTimeFormat('es-PE', {
      month: 'long',
      year: 'numeric',
    }).format(new Date(year, month - 1, 1));

    return formatted.charAt(0).toUpperCase() + formatted.slice(1);
  }

  private formatMonthLabel(accountingMonth: string): string {
    return this.buildAccountingPeriodLabel(accountingMonth);
  }
}

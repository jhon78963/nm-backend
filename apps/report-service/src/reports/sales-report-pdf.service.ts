import { Injectable } from '@nestjs/common';
import dayjs from 'dayjs';
import { DocumentClientService } from '@app/document-client';
import { ReportsService } from './reports.service';

interface SalesReportTemplateData {
  title: string;
  subtitle: string;
  generatedAt: string;
  isDaily: boolean;
  summary: Record<string, string | number>;
  paymentBreakdown: Array<{
    label: string;
    count: number;
    amount: string;
  }>;
  transactions?: Array<{
    time: string;
    code: string;
    customer: string;
    itemsCount: number;
    paymentLabel: string;
    totalAmount: string;
  }>;
  dailyBreakdown?: Array<{
    date: string;
    dayOfWeek: string;
    transactions: number;
    cash: string;
    digital: string;
    total: string;
  }>;
}

@Injectable()
export class SalesReportPdfService {
  constructor(
    private readonly reportsService: ReportsService,
    private readonly documentClient: DocumentClientService,
  ) {}

  async generateDaily(date: string, warehouseId: string): Promise<Buffer> {
    const report = await this.reportsService.getDailySalesReport(date, warehouseId);
    const templateData = this.buildDailyTemplateData(report);

    return this.documentClient.generatePdf('sales-report', templateData);
  }

  async generatePeriod(
    startDate: string,
    endDate: string,
    warehouseId: string,
  ): Promise<Buffer> {
    const report = await this.reportsService.getPeriodSalesReport(
      startDate,
      endDate,
      warehouseId,
    );
    const templateData = this.buildPeriodTemplateData(report);

    return this.documentClient.generatePdf('sales-report', templateData);
  }

  async generateMonthly(month: string, warehouseId: string): Promise<Buffer> {
    const report = await this.reportsService.getMonthlySalesReport(month, warehouseId);
    const templateData = this.buildMonthlyTemplateData(report);

    return this.documentClient.generatePdf('sales-report', templateData);
  }

  private buildDailyTemplateData(report: Awaited<ReturnType<ReportsService['getDailySalesReport']>>): SalesReportTemplateData {
    return {
      title: 'Reporte de Ventas Diario',
      subtitle: report.date,
      generatedAt: dayjs().format('DD/MM/YYYY HH:mm'),
      isDaily: true,
      summary: this.formatSummary(report.summary),
      paymentBreakdown: report.paymentBreakdown.map((entry) => ({
        label: entry.label,
        count: entry.count,
        amount: this.formatMoney(entry.amount),
      })),
      transactions: report.sales.map((sale) => ({
        time: sale.time,
        code: sale.code,
        customer: sale.customer,
        itemsCount: sale.itemsCount,
        paymentLabel: sale.paymentLabel,
        totalAmount: this.formatMoney(sale.totalAmount),
      })),
    };
  }

  private buildPeriodTemplateData(report: Awaited<ReturnType<ReportsService['getPeriodSalesReport']>>): SalesReportTemplateData {
    return {
      title: 'Reporte de Ventas por Período',
      subtitle: report.periodLabel,
      generatedAt: dayjs().format('DD/MM/YYYY HH:mm'),
      isDaily: false,
      summary: this.formatSummary(report.summary),
      paymentBreakdown: report.paymentBreakdown.map((entry) => ({
        label: entry.label,
        count: entry.count,
        amount: this.formatMoney(entry.amount),
      })),
      dailyBreakdown: report.dailyBreakdown.map((day) => ({
        date: day.date,
        dayOfWeek: day.dayOfWeek,
        transactions: day.transactions,
        cash: this.formatMoney(day.cash),
        digital: this.formatMoney(day.digital),
        total: this.formatMoney(day.total),
      })),
    };
  }

  private buildMonthlyTemplateData(
    report: Awaited<ReturnType<ReportsService['getMonthlySalesReport']>>,
  ): SalesReportTemplateData {
    const daysInMonth = dayjs(`${report.monthIso}-01`).daysInMonth();

    return {
      title: 'Reporte de Ventas Mensual',
      subtitle: report.monthLabel,
      generatedAt: dayjs().format('DD/MM/YYYY HH:mm'),
      isDaily: false,
      summary: this.formatSummary({
        ...report.summary,
        daysInRange: daysInMonth,
      }),
      paymentBreakdown: report.paymentBreakdown.map((entry) => ({
        label: entry.label,
        count: entry.count,
        amount: this.formatMoney(entry.amount),
      })),
      dailyBreakdown: report.dailyBreakdown.map((day) => ({
        date: day.date,
        dayOfWeek: day.dayOfWeek,
        transactions: day.transactions,
        cash: this.formatMoney(day.cash),
        digital: this.formatMoney(day.digital),
        total: this.formatMoney(day.total),
      })),
    };
  }

  private formatSummary(summary: Record<string, number>): Record<string, string | number> {
    const formatted: Record<string, string | number> = {
      transactionCount: summary.transactionCount,
      itemsSold: summary.itemsSold,
    };

    for (const key of ['totalAmount', 'averageTicket', 'cash', 'digital', 'averageDaily'] as const) {
      if (summary[key] !== undefined) {
        formatted[key] = this.formatMoney(summary[key]);
      }
    }

    if (summary.daysWithSales !== undefined) {
      formatted.daysWithSales = summary.daysWithSales;
    }

    if (summary.daysInRange !== undefined) {
      formatted.daysInRange = summary.daysInRange;
    }

    return formatted;
  }

  private formatMoney(value: number): string {
    return value.toLocaleString('es-PE', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }
}

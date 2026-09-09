import {
  Controller, Get, Query, UseGuards, StreamableFile, Header,
} from '@nestjs/common';
import { ApiTags, ApiBearerAuth, ApiOperation, ApiQuery } from '@nestjs/swagger';
import dayjs from 'dayjs';
import { JwtAuthGuard } from '@app/common/guards/jwt-auth.guard';
import { PermissionsGuard } from '@app/common/guards/permissions.guard';
import { Permissions } from '@app/common/decorators/permissions.decorator';
import { CurrentUser } from '@app/common/decorators/current-user.decorator';
import type { AuthenticatedUser } from '@app/common/types/authenticated-user.type';
import { ReportsService } from './reports.service';
import { ManagementDashboardService } from './management-dashboard.service';
import { ProductsInventoryPdfService } from './products-inventory-pdf.service';
import { SalesReportPdfService } from './sales-report-pdf.service';

@ApiTags('Reports')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, PermissionsGuard)
@Controller({ path: 'reports', version: '1' })
export class ReportsController {
  constructor(
    private readonly reportsService: ReportsService,
    private readonly managementDashboardService: ManagementDashboardService,
    private readonly productsInventoryPdfService: ProductsInventoryPdfService,
    private readonly salesReportPdfService: SalesReportPdfService,
  ) {}

  @Get('dashboard')
  @Permissions('report.index')
  @ApiOperation({ summary: 'Reporte gerencial (totales, P&L, ranking, históricos)' })
  @ApiQuery({ name: 'start_date', required: false, description: 'YYYY-MM-DD' })
  @ApiQuery({ name: 'end_date', required: false, description: 'YYYY-MM-DD' })
  @ApiQuery({ name: 'startDate', required: false, description: 'Alias camelCase' })
  @ApiQuery({ name: 'endDate', required: false, description: 'Alias camelCase' })
  getManagementDashboard(
    @CurrentUser() user: AuthenticatedUser,
    @Query('start_date') startDateSnake?: string,
    @Query('end_date') endDateSnake?: string,
    @Query('startDate') startDateCamel?: string,
    @Query('endDate') endDateCamel?: string,
  ) {
    const startDate = startDateSnake ?? startDateCamel ?? dayjs().startOf('month').format('YYYY-MM-DD');
    const endDate = endDateSnake ?? endDateCamel ?? dayjs().endOf('month').format('YYYY-MM-DD');

    return this.managementDashboardService.getDashboard(
      startDate,
      endDate,
      user.warehouseId,
    );
  }

  @Get('sales/daily')
  @Permissions('report.sales')
  @ApiOperation({ summary: 'Reporte diario de ventas' })
  @ApiQuery({ name: 'date', required: false, description: 'YYYY-MM-DD (default: hoy)' })
  @ApiQuery({ name: 'warehouse_id', required: false })
  getDailySalesReport(
    @CurrentUser() user: AuthenticatedUser,
    @Query('date') date?: string,
    @Query('warehouse_id') warehouseId?: string,
  ) {
    const targetDate = date ?? new Date().toISOString().slice(0, 10);
    const targetWarehouse = warehouseId ?? user.warehouseId;
    return this.reportsService.getDailySalesReport(targetDate, targetWarehouse);
  }

  @Get('sales/daily/pdf')
  @Permissions('report.sales')
  @ApiOperation({ summary: 'Exportar reporte diario en PDF' })
  @ApiQuery({ name: 'date', required: false, description: 'YYYY-MM-DD (default: hoy)' })
  @ApiQuery({ name: 'warehouse_id', required: false })
  async getDailySalesPdf(
    @CurrentUser() user: AuthenticatedUser,
    @Query('date') date?: string,
    @Query('warehouse_id') warehouseId?: string,
  ) {
    const targetDate = date ?? new Date().toISOString().slice(0, 10);
    const targetWarehouse = warehouseId ?? user.warehouseId;
    const buffer = await this.salesReportPdfService.generateDaily(targetDate, targetWarehouse);
    const filename = `reporte-ventas-diario-${targetDate}.pdf`;

    return new StreamableFile(buffer, {
      type: 'application/pdf',
      disposition: `attachment; filename="${filename}"`,
    });
  }

  @Get('sales/monthly')
  @Permissions('report.sales')
  @ApiOperation({ summary: 'Reporte mensual de ventas' })
  @ApiQuery({ name: 'month', required: false, description: 'YYYY-MM (default: mes actual)' })
  @ApiQuery({ name: 'warehouse_id', required: false })
  getMonthlySalesReport(
    @CurrentUser() user: AuthenticatedUser,
    @Query('month') month?: string,
    @Query('warehouse_id') warehouseId?: string,
  ) {
    const now = new Date();
    const targetMonth = month ?? `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    const targetWarehouse = warehouseId ?? user.warehouseId;
    return this.reportsService.getMonthlySalesReport(targetMonth, targetWarehouse);
  }

  @Get('sales/monthly/pdf')
  @Permissions('report.sales')
  @Header('Content-Type', 'application/pdf')
  @ApiOperation({ summary: 'Exportar reporte mensual en PDF' })
  @ApiQuery({ name: 'month', required: false, description: 'YYYY-MM (default: mes actual)' })
  @ApiQuery({ name: 'warehouse_id', required: false })
  async getMonthlySalesPdf(
    @CurrentUser() user: AuthenticatedUser,
    @Query('month') month?: string,
    @Query('warehouse_id') warehouseId?: string,
  ) {
    const now = new Date();
    const targetMonth = month ?? `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    const targetWarehouse = warehouseId ?? user.warehouseId;
    const buffer = await this.salesReportPdfService.generateMonthly(targetMonth, targetWarehouse);
    const filename = `reporte-ventas-mensual-${targetMonth}.pdf`;

    return new StreamableFile(buffer, {
      type: 'application/pdf',
      disposition: `attachment; filename="${filename}"`,
    });
  }

  @Get('sales/daily-period')
  @Permissions('report.sales')
  @ApiOperation({ summary: 'Reporte de ventas por rango de fechas' })
  @ApiQuery({ name: 'start_date', required: true, description: 'YYYY-MM-DD' })
  @ApiQuery({ name: 'end_date', required: true, description: 'YYYY-MM-DD' })
  @ApiQuery({ name: 'warehouse_id', required: false })
  getPeriodSalesReport(
    @CurrentUser() user: AuthenticatedUser,
    @Query('start_date') startDate?: string,
    @Query('end_date') endDate?: string,
    @Query('warehouse_id') warehouseId?: string,
  ) {
    const today = new Date().toISOString().slice(0, 10);
    const targetWarehouse = warehouseId ?? user.warehouseId;
    return this.reportsService.getPeriodSalesReport(
      startDate ?? today,
      endDate ?? today,
      targetWarehouse,
    );
  }

  @Get('sales/period/pdf')
  @Permissions('report.sales')
  @ApiOperation({ summary: 'Exportar reporte de período en PDF' })
  @ApiQuery({ name: 'start_date', required: true, description: 'YYYY-MM-DD' })
  @ApiQuery({ name: 'end_date', required: true, description: 'YYYY-MM-DD' })
  @ApiQuery({ name: 'warehouse_id', required: false })
  async getPeriodSalesPdf(
    @CurrentUser() user: AuthenticatedUser,
    @Query('start_date') startDate?: string,
    @Query('end_date') endDate?: string,
    @Query('warehouse_id') warehouseId?: string,
  ) {
    const today = new Date().toISOString().slice(0, 10);
    const targetWarehouse = warehouseId ?? user.warehouseId;
    const from = startDate ?? today;
    const to = endDate ?? today;
    const buffer = await this.salesReportPdfService.generatePeriod(from, to, targetWarehouse);
    const filename = `reporte-ventas-periodo-${from}_${to}.pdf`;

    return new StreamableFile(buffer, {
      type: 'application/pdf',
      disposition: `attachment; filename="${filename}"`,
    });
  }

  @Get('products')
  @Permissions('report.products')
  @ApiOperation({ summary: 'Inventario de productos con stock' })
  @ApiQuery({ name: 'warehouse_id', required: false })
  getProductsInventory(
    @CurrentUser() user: AuthenticatedUser,
    @Query('warehouse_id') warehouseId?: string,
  ) {
    const targetWarehouse = warehouseId ?? user.warehouseId;
    return this.reportsService.getProductsInventory(targetWarehouse).then((products) => ({
      success: true,
      data: products,
    }));
  }

  @Get('products/export/pdf')
  @Permissions('report.products')
  @ApiOperation({ summary: 'Exportar inventario en PDF' })
  async getProductsPdf(
    @CurrentUser() user: AuthenticatedUser,
    @Query('warehouse_id') warehouseId?: string,
  ) {
    const targetWarehouse = warehouseId ?? user.warehouseId;
    const buffer = await this.productsInventoryPdfService.generate(targetWarehouse);
    const filename = `reporte-productos-inventario-${dayjs().format('YYYY-MM-DD')}.pdf`;

    return new StreamableFile(buffer, {
      type: 'application/pdf',
      disposition: `attachment; filename="${filename}"`,
    });
  }
}

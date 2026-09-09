import { Controller, Get, UseGuards } from '@nestjs/common';
import {
  ApiTags, ApiBearerAuth, ApiOperation, ApiResponse,
} from '@nestjs/swagger';
import { DashboardService } from './dashboard.service';
import { JwtAuthGuard } from '@app/common/guards/jwt-auth.guard';
import { CurrentUser } from '@app/common/decorators/current-user.decorator';
import type { AuthenticatedUser } from '@app/common/types/authenticated-user.type';

@ApiTags('Dashboard')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller({ path: 'dashboard', version: '1' })
export class DashboardController {
  constructor(private readonly dashboardService: DashboardService) {}

  @Get()
  @ApiOperation({
    summary: 'Métricas del día y del mes para la pantalla principal del admin',
  })
  @ApiResponse({
    status: 200,
    description: [
      'Ventas (hoy + mes), stock bajo, compras pendientes,',
      'movimientos de caja, planilla del mes y top 5 productos.',
    ].join(' '),
  })
  getMetrics(@CurrentUser() user: AuthenticatedUser) {
    return this.dashboardService.getMetrics(user.warehouseId);
  }

  // Alias Laravel: GET /dashboard/metrics (frontend nm-frontend-v2)
  @Get('metrics')
  async getMetricsForFrontend(@CurrentUser() user: AuthenticatedUser) {
    const raw = await this.dashboardService.getMetrics(user.warehouseId);
    return {
      todaySales: raw.sales.today.count,
      todaySalesAmount: raw.sales.today.revenue,
      todayExpenses: 0,
      lowStockProducts: raw.inventory.lowStockItems,
      pendingPurchases: raw.purchases.pendingThisMonth,
      activeCustomers: raw.customers.total,
      pendingTasks: raw.tasks.pendingToday,
    };
  }
}

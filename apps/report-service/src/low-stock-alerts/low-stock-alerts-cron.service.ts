import { Injectable, Logger } from '@nestjs/common';
import { Cron } from '@nestjs/schedule';
import { LowStockAlertsService } from '@app/common/inventory/low-stock-alerts.service';

/**
 * Digest diario de stock bajo — 8:00 AM hora Perú (UTC-5 → 13:00 UTC).
 */
@Injectable()
export class LowStockAlertsCronService {
  private readonly logger = new Logger(LowStockAlertsCronService.name);

  constructor(private readonly lowStockAlerts: LowStockAlertsService) {}

  @Cron('0 13 * * *')
  async handleDailyDigest(): Promise<void> {
    this.logger.log('Ejecutando digest diario de alertas de stock bajo');
    await this.lowStockAlerts.runDailyDigest();
  }
}

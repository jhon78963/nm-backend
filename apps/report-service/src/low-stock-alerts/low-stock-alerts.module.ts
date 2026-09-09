import { Module } from '@nestjs/common';
import { LowStockAlertsModule as CommonLowStockAlertsModule } from '@app/common/inventory/low-stock-alerts.module';

import { LowStockAlertsCronService } from './low-stock-alerts-cron.service';

@Module({
  imports: [CommonLowStockAlertsModule],
  providers: [LowStockAlertsCronService],
})
export class LowStockAlertsModule {}

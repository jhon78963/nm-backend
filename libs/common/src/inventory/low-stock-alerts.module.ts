import { Module } from '@nestjs/common';
import { DatabaseModule } from '@app/database';
import { MailClientModule } from '@app/mail-client';

import { LowStockAlertsService } from './low-stock-alerts.service';

@Module({
  imports: [DatabaseModule, MailClientModule],
  providers: [LowStockAlertsService],
  exports: [LowStockAlertsService],
})
export class LowStockAlertsModule {}

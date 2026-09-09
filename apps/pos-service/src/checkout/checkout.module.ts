import { Module } from '@nestjs/common';
import { CheckoutController } from './checkout.controller';
import { CheckoutService } from './checkout.service';
import { SunatModule } from '../sunat/sunat.module';
import { FiscalModule } from '../fiscal/fiscal.module';
import { DatabaseModule } from '@app/database';
import { LowStockAlertsModule } from '@app/common/inventory/low-stock-alerts.module';

@Module({
  imports: [DatabaseModule, SunatModule, FiscalModule, LowStockAlertsModule],
  controllers: [CheckoutController],
  providers: [CheckoutService],
})
export class CheckoutModule {}

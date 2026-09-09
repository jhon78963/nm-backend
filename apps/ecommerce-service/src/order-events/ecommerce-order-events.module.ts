import { Module } from '@nestjs/common';

import { LowStockAlertsModule } from '@app/common/inventory/low-stock-alerts.module';
import { EcommerceInvoicingModule } from '../ecommerce-invoicing/ecommerce-invoicing.module';
import { EcommerceMailModule } from '../mail/ecommerce-mail.module';
import { EcommerceOrderEventsService } from './ecommerce-order-events.service';

@Module({
  imports: [EcommerceMailModule, EcommerceInvoicingModule, LowStockAlertsModule],
  providers: [EcommerceOrderEventsService],
  exports: [EcommerceOrderEventsService],
})
export class EcommerceOrderEventsModule {}

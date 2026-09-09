import { Module } from '@nestjs/common';

import { LowStockAlertsModule } from '@app/common/inventory/low-stock-alerts.module';
import { EventBusModule } from '@app/event-bus';

import { CheckoutEventsConsumer } from './checkout-events.consumer';

@Module({
  imports: [EventBusModule.forConsumer(), LowStockAlertsModule],
  providers: [CheckoutEventsConsumer],
})
export class CheckoutEventsModule {}

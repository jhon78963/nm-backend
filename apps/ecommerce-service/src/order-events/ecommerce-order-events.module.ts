import { Module } from '@nestjs/common';

import { EventBusModule } from '@app/event-bus';
import { EcommerceInvoicingModule } from '../ecommerce-invoicing/ecommerce-invoicing.module';
import { EcommerceMailModule } from '../mail/ecommerce-mail.module';
import { EcommerceOrderEventsService } from './ecommerce-order-events.service';

@Module({
  imports: [EcommerceMailModule, EcommerceInvoicingModule, EventBusModule.forPublisher()],
  providers: [EcommerceOrderEventsService],
  exports: [EcommerceOrderEventsService],
})
export class EcommerceOrderEventsModule {}

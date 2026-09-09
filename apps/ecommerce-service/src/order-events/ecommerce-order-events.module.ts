import { Module } from '@nestjs/common';

import { EcommerceInvoicingModule } from '../ecommerce-invoicing/ecommerce-invoicing.module';
import { EcommerceMailModule } from '../mail/ecommerce-mail.module';
import { EcommerceOrderEventsService } from './ecommerce-order-events.service';

@Module({
  imports: [EcommerceMailModule, EcommerceInvoicingModule],
  providers: [EcommerceOrderEventsService],
  exports: [EcommerceOrderEventsService],
})
export class EcommerceOrderEventsModule {}

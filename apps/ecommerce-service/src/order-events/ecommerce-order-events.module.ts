import { Module } from '@nestjs/common';

import { EcommerceMailModule } from '../mail/ecommerce-mail.module';
import { EcommerceOrderEventsService } from './ecommerce-order-events.service';

@Module({
  imports: [EcommerceMailModule],
  providers: [EcommerceOrderEventsService],
  exports: [EcommerceOrderEventsService],
})
export class EcommerceOrderEventsModule {}

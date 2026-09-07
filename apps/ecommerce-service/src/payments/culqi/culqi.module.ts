import { Module } from '@nestjs/common';

import { RecaptchaModule } from '@app/common/recaptcha/recaptcha.module';

import { OrdersModule } from '../../orders/orders.module';
import { EcommerceOrderEventsModule } from '../../order-events/ecommerce-order-events.module';
import { CulqiPaymentsController, CulqiWebhookController } from './culqi.controller';
import { CulqiPaymentsService } from './culqi-payments.service';
import { CulqiService } from './culqi.service';

@Module({
  imports: [RecaptchaModule, OrdersModule, EcommerceOrderEventsModule],
  controllers: [CulqiPaymentsController, CulqiWebhookController],
  providers: [CulqiService, CulqiPaymentsService],
  exports: [CulqiService, CulqiPaymentsService],
})
export class CulqiModule {}

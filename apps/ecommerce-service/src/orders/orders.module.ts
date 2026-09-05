import { Module } from '@nestjs/common';

import { RecaptchaModule } from '@app/common/recaptcha/recaptcha.module';

import { CouponsModule } from '../coupons/coupons.module';
import { CustomerAuthModule } from '../customer-auth/customer-auth.module';
import { EcommerceMailModule } from '../mail/ecommerce-mail.module';
import { OrdersController } from './orders.controller';
import { OrdersService } from './orders.service';

@Module({
  imports: [RecaptchaModule, CouponsModule, CustomerAuthModule, EcommerceMailModule],
  controllers: [OrdersController],
  providers: [OrdersService],
  exports: [OrdersService],
})
export class OrdersModule {}

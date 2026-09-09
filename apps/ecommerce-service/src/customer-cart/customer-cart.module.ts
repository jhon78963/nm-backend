import { Module } from '@nestjs/common';

import { CustomerAuthModule } from '../customer-auth/customer-auth.module';
import { CustomerCartController } from './customer-cart.controller';
import { CustomerCartService } from './customer-cart.service';

@Module({
  imports: [CustomerAuthModule],
  controllers: [CustomerCartController],
  providers: [CustomerCartService],
  exports: [CustomerCartService],
})
export class CustomerCartModule {}

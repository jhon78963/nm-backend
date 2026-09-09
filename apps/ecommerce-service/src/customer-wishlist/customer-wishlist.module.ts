import { Module } from '@nestjs/common';

import { CustomerAuthModule } from '../customer-auth/customer-auth.module';
import { CustomerWishlistController } from './customer-wishlist.controller';
import { CustomerWishlistService } from './customer-wishlist.service';

@Module({
  imports: [CustomerAuthModule],
  controllers: [CustomerWishlistController],
  providers: [CustomerWishlistService],
  exports: [CustomerWishlistService],
})
export class CustomerWishlistModule {}

import {
  Body,
  Controller,
  Delete,
  Get,
  HttpCode,
  HttpStatus,
  Put,
  Query,
  UseGuards,
} from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiQuery, ApiTags } from '@nestjs/swagger';
import { Throttle, ThrottlerGuard } from '@nestjs/throttler';

import { CustomerJwtAuthGuard } from '../customer-auth/guards/customer-jwt.guard';
import { CurrentCustomer } from '../customer-auth/decorators/current-customer.decorator';
import type { AuthenticatedCustomer } from '../customer-auth/types/authenticated-customer.type';
import { CustomerWishlistService } from './customer-wishlist.service';
import { ReplaceWishlistDto } from './dto/replace-wishlist.dto';

@ApiTags('Ecommerce Customer Wishlist')
@Controller('ecommerce/customer/wishlist')
@ApiBearerAuth()
@UseGuards(CustomerJwtAuthGuard, ThrottlerGuard)
@Throttle({ default: { limit: 60, ttl: 60_000 } })
export class CustomerWishlistController {
  constructor(private readonly customerWishlistService: CustomerWishlistService) {}

  @Get()
  @ApiOperation({ summary: 'Obtener favoritos persistidos del cliente' })
  @ApiQuery({ name: 'warehouse_id', required: true })
  getWishlist(
    @CurrentCustomer() customer: AuthenticatedCustomer,
    @Query('warehouse_id') warehouseId: string,
  ) {
    return this.customerWishlistService.getWishlist(customer.id, warehouseId);
  }

  @Put()
  @ApiOperation({ summary: 'Reemplazar favoritos del cliente' })
  replaceWishlist(
    @CurrentCustomer() customer: AuthenticatedCustomer,
    @Body() dto: ReplaceWishlistDto,
  ) {
    return this.customerWishlistService.replaceWishlist(customer.id, dto);
  }

  @Delete()
  @HttpCode(HttpStatus.NO_CONTENT)
  @ApiOperation({ summary: 'Vaciar favoritos persistidos del cliente' })
  async clearWishlist(@CurrentCustomer() customer: AuthenticatedCustomer) {
    await this.customerWishlistService.clearWishlist(customer.id);
  }
}

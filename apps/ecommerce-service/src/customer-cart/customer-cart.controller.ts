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
import { CustomerCartService } from './customer-cart.service';
import { ReplaceCartDto } from './dto/replace-cart.dto';

@ApiTags('Ecommerce Customer Cart')
@Controller('ecommerce/customer/cart')
@ApiBearerAuth()
@UseGuards(CustomerJwtAuthGuard, ThrottlerGuard)
@Throttle({ default: { limit: 60, ttl: 60_000 } })
export class CustomerCartController {
  constructor(private readonly customerCartService: CustomerCartService) {}

  @Get()
  @ApiOperation({ summary: 'Obtener carrito persistido del cliente' })
  @ApiQuery({ name: 'warehouse_id', required: true })
  getCart(
    @CurrentCustomer() customer: AuthenticatedCustomer,
    @Query('warehouse_id') warehouseId: string,
  ) {
    return this.customerCartService.getCart(customer.id, warehouseId);
  }

  @Put()
  @ApiOperation({ summary: 'Reemplazar carrito del cliente (merge de variantes)' })
  replaceCart(
    @CurrentCustomer() customer: AuthenticatedCustomer,
    @Body() dto: ReplaceCartDto,
  ) {
    return this.customerCartService.replaceCart(customer.id, dto);
  }

  @Delete()
  @HttpCode(HttpStatus.NO_CONTENT)
  @ApiOperation({ summary: 'Vaciar carrito persistido del cliente' })
  async clearCart(@CurrentCustomer() customer: AuthenticatedCustomer) {
    await this.customerCartService.clearCart(customer.id);
  }
}

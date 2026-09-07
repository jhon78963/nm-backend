import {
  Body,
  Controller,
  Get,
  HttpCode,
  HttpStatus,
  Param,
  Patch,
  Post,
  Query,
  Req,
  UseGuards,
} from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { Throttle, ThrottlerGuard } from '@nestjs/throttler';

import { Public } from '@app/common/decorators/public.decorator';
import { Roles } from '@app/common/decorators/roles.decorator';
import { JwtAuthGuard } from '@app/common/guards/jwt-auth.guard';
import { RolesGuard } from '@app/common/guards/roles.guard';
import { resolveClientIp } from '@app/common/utils/client-ip.util';
import { RECAPTCHA_ACTIONS } from '@app/common/recaptcha/recaptcha.constants';
import { RecaptchaService } from '@app/common/recaptcha/recaptcha.service';

import { CancelCheckoutOrderDto } from './dto/cancel-checkout-order.dto';
import { CreateOrderDto } from './dto/create-order.dto';
import { ListCustomerOrdersQueryDto } from './dto/list-customer-orders-query.dto';
import { ListOrdersQueryDto } from './dto/list-orders-query.dto';
import { PublicOrderQueryDto, TrackOrderDto } from './dto/track-order.dto';
import { UpdateOrderDto } from './dto/update-order.dto';
import { OrdersService } from './orders.service';
import { OptionalCustomerJwtAuthGuard } from '../customer-auth/guards/optional-customer-jwt.guard';
import { CustomerJwtAuthGuard } from '../customer-auth/guards/customer-jwt.guard';
import { CurrentCustomer } from '../customer-auth/decorators/current-customer.decorator';
import type { AuthenticatedCustomer } from '../customer-auth/types/authenticated-customer.type';

@ApiTags('Ecommerce Orders')
@Controller('ecommerce/orders')
export class OrdersController {
  constructor(
    private readonly ordersService: OrdersService,
    private readonly recaptcha: RecaptchaService,
  ) {}

  @Post()
  @Public()
  @UseGuards(OptionalCustomerJwtAuthGuard, ThrottlerGuard)
  @Throttle({ default: { limit: 10, ttl: 60_000 } })
  @HttpCode(HttpStatus.CREATED)
  @ApiOperation({ summary: 'Crear pedido web (checkout público o cliente autenticado)' })
  async createOrder(
    @Body() dto: CreateOrderDto,
    @Req() request: { user?: AuthenticatedCustomer | null; headers: Record<string, string | string[] | undefined> },
  ) {
    await this.recaptcha.verify(dto.captchaToken, RECAPTCHA_ACTIONS.checkoutOrder);

    return this.ordersService.createOrder(
      {
        ...dto,
        clientIp: dto.clientIp ?? resolveClientIp(request.headers),
      },
      request.user ?? undefined,
    );
  }

  @Post('cancel-checkout')
  @Public()
  @UseGuards(ThrottlerGuard)
  @Throttle({ default: { limit: 10, ttl: 60_000 } })
  @HttpCode(HttpStatus.OK)
  @ApiOperation({ summary: 'Cancelar pedido pendiente de checkout (rollback stock)' })
  async cancelCheckoutOrder(@Body() dto: CancelCheckoutOrderDto) {
    await this.recaptcha.verify(dto.captchaToken, RECAPTCHA_ACTIONS.checkoutOrder);

    return this.ordersService.cancelPendingCheckoutOrder(dto.orderNumber, dto.email);
  }

  @Get('mine')
  @ApiBearerAuth()
  @UseGuards(CustomerJwtAuthGuard, ThrottlerGuard)
  @Throttle({ default: { limit: 30, ttl: 60_000 } })
  @ApiOperation({ summary: 'Listar pedidos del cliente autenticado' })
  listCustomerOrders(
    @CurrentCustomer() customer: AuthenticatedCustomer,
    @Query() query: ListCustomerOrdersQueryDto,
  ) {
    return this.ordersService.listCustomerOrders(customer.id, query);
  }

  @Get('mine/:orderNumber')
  @ApiBearerAuth()
  @UseGuards(CustomerJwtAuthGuard, ThrottlerGuard)
  @Throttle({ default: { limit: 30, ttl: 60_000 } })
  @ApiOperation({ summary: 'Detalle de pedido del cliente autenticado' })
  getCustomerOrder(
    @CurrentCustomer() customer: AuthenticatedCustomer,
    @Param('orderNumber') orderNumber: string,
  ) {
    return this.ordersService.getCustomerOrder(customer.id, orderNumber);
  }

  @Post('track')
  @Public()
  @UseGuards(ThrottlerGuard)
  @Throttle({ default: { limit: 20, ttl: 60_000 } })
  @HttpCode(HttpStatus.OK)
  @ApiOperation({ summary: 'Seguimiento de pedido por número y correo/teléfono' })
  async trackOrder(@Body() dto: TrackOrderDto) {
    await this.recaptcha.verify(dto.captchaToken, RECAPTCHA_ACTIONS.orderTrack);

    return this.ordersService.trackOrder(dto.orderNumber, dto.contact);
  }

  @Get('public/:orderNumber')
  @Public()
  @UseGuards(ThrottlerGuard)
  @Throttle({ default: { limit: 30, ttl: 60_000 } })
  @ApiOperation({ summary: 'Detalle público de pedido (confirmación)' })
  getPublicOrder(
    @Param('orderNumber') orderNumber: string,
    @Query() query: PublicOrderQueryDto,
  ) {
    return this.ordersService.getPublicOrder(orderNumber, query.email);
  }

  @Get('admin')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, RolesGuard)
  @Roles('Admin', 'Super Admin')
  @ApiOperation({ summary: 'Listar pedidos web (admin)' })
  listAdminOrders(@Query() query: ListOrdersQueryDto) {
    return this.ordersService.listAdminOrders(query);
  }

  @Get('admin/:id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, RolesGuard)
  @Roles('Admin', 'Super Admin')
  @ApiOperation({ summary: 'Detalle de pedido web (admin)' })
  getAdminOrder(@Param('id') id: string) {
    return this.ordersService.getAdminOrder(id);
  }

  @Patch('admin/:id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, RolesGuard)
  @Roles('Admin', 'Super Admin')
  @ApiOperation({ summary: 'Actualizar estado o notas del pedido (admin)' })
  updateAdminOrder(@Param('id') id: string, @Body() dto: UpdateOrderDto) {
    return this.ordersService.updateAdminOrder(id, dto);
  }
}

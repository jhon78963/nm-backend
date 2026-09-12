import {
  Body,
  Controller,
  Delete,
  Get,
  Headers,
  HttpCode,
  HttpStatus,
  Param,
  Post,
  Put,
  UnauthorizedException,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { ApiOperation, ApiTags } from '@nestjs/swagger';

import { Public } from '@app/common/decorators/public.decorator';

import { UpsertWhatsAppGuestCartDto } from './dto/upsert-whatsapp-guest-cart.dto';
import { WhatsAppGuestCartHandoffDto } from './dto/whatsapp-guest-cart-handoff.dto';
import { WhatsAppGuestCartService } from './whatsapp-guest-cart.service';

@ApiTags('Ecommerce WhatsApp Guest Cart Internal')
@Controller('ecommerce/whatsapp/internal/guest-cart')
export class WhatsAppGuestCartController {
  constructor(
    private readonly guestCartService: WhatsAppGuestCartService,
    private readonly config: ConfigService,
  ) {}

  @Put()
  @Public()
  @ApiOperation({ summary: 'Crear o fusionar carrito guest de WhatsApp (service-to-service)' })
  upsert(
    @Headers('x-internal-service-key') serviceKey: string | undefined,
    @Body() dto: UpsertWhatsAppGuestCartDto,
  ) {
    this.assertInternalKey(serviceKey);
    return this.guestCartService.upsert(dto);
  }

  @Post('handoff')
  @Public()
  @HttpCode(HttpStatus.OK)
  @ApiOperation({ summary: 'Obtener carrito + deep link wa.me para transferencia a humano' })
  getHandoff(
    @Headers('x-internal-service-key') serviceKey: string | undefined,
    @Body() dto: WhatsAppGuestCartHandoffDto,
  ) {
    this.assertInternalKey(serviceKey);
    return this.guestCartService.getHandoff(dto);
  }

  @Get(':sessionId')
  @Public()
  @ApiOperation({ summary: 'Leer carrito guest por Session ID hasheado' })
  getBySessionId(
    @Headers('x-internal-service-key') serviceKey: string | undefined,
    @Param('sessionId') sessionId: string,
  ) {
    this.assertInternalKey(serviceKey);
    return this.guestCartService.getBySessionId(sessionId);
  }

  @Delete(':sessionId')
  @Public()
  @HttpCode(HttpStatus.NO_CONTENT)
  @ApiOperation({ summary: 'Eliminar carrito guest de WhatsApp' })
  async clear(
    @Headers('x-internal-service-key') serviceKey: string | undefined,
    @Param('sessionId') sessionId: string,
  ) {
    this.assertInternalKey(serviceKey);
    await this.guestCartService.clear(sessionId);
  }

  private assertInternalKey(serviceKey: string | undefined): void {
    const expected = this.config.get<string>('INTERNAL_SERVICE_KEY', 'nm-internal-dev-key');
    if (!serviceKey || serviceKey !== expected) {
      throw new UnauthorizedException('No autorizado.');
    }
  }
}

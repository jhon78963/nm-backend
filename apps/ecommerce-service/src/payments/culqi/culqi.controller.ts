import { Body, Controller, Headers, HttpCode, HttpStatus, Post, UseGuards } from '@nestjs/common';
import { ApiOperation, ApiTags } from '@nestjs/swagger';
import { Throttle, ThrottlerGuard } from '@nestjs/throttler';

import { Public } from '@app/common/decorators/public.decorator';
import { RecaptchaService } from '@app/common/recaptcha/recaptcha.service';
import { RECAPTCHA_ACTIONS } from '@app/common/recaptcha/recaptcha.constants';

import { CulqiPaymentsService } from './culqi-payments.service';
import { CulqiChargeDto } from './dto/culqi-charge.dto';
import { CulqiPrepareDto } from './dto/culqi-prepare.dto';

@ApiTags('Ecommerce Culqi')
@Controller('ecommerce/payments/culqi')
export class CulqiPaymentsController {
  constructor(
    private readonly culqiPayments: CulqiPaymentsService,
    private readonly recaptcha: RecaptchaService,
  ) {}

  @Post('prepare')
  @Public()
  @UseGuards(ThrottlerGuard)
  @Throttle({ default: { limit: 10, ttl: 60_000 } })
  @HttpCode(HttpStatus.OK)
  @ApiOperation({ summary: 'Preparar sesión Culqi (orden + RSA) para tarjeta y Yape' })
  async prepare(@Body() dto: CulqiPrepareDto) {
    await this.recaptcha.verify(dto.captchaToken, RECAPTCHA_ACTIONS.checkoutOrder);

    return this.culqiPayments.prepareCheckoutSession({
      orderNumber: dto.orderNumber,
      email: dto.email,
    });
  }

  @Post('charge')
  @Public()
  @UseGuards(ThrottlerGuard)
  @Throttle({ default: { limit: 10, ttl: 60_000 } })
  @HttpCode(HttpStatus.OK)
  @ApiOperation({ summary: 'Crear cargo Culqi para un pedido pendiente' })
  async charge(@Body() dto: CulqiChargeDto) {
    await this.recaptcha.verify(dto.captchaToken, RECAPTCHA_ACTIONS.checkoutOrder);

    return this.culqiPayments.chargeOrder({
      orderNumber: dto.orderNumber,
      email: dto.email,
      culqiToken: dto.culqiToken,
    });
  }
}

@ApiTags('Ecommerce Culqi Webhooks')
@Controller('ecommerce/webhooks')
export class CulqiWebhookController {
  constructor(private readonly culqiPayments: CulqiPaymentsService) {}

  @Post('culqi')
  @Public()
  @HttpCode(HttpStatus.OK)
  @ApiOperation({ summary: 'Webhook de eventos Culqi' })
  async handleWebhook(
    @Headers('authorization') authorization: string | undefined,
    @Body() body: Record<string, unknown>,
  ) {
    this.culqiPayments.assertWebhookAuthorized(authorization);
    return this.culqiPayments.handleWebhookEvent(body);
  }
}

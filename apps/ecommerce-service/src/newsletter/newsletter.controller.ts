import { Body, Controller, HttpCode, HttpStatus, Post, UseGuards } from '@nestjs/common';
import { ApiOperation, ApiTags } from '@nestjs/swagger';
import { Throttle, ThrottlerGuard } from '@nestjs/throttler';

import { Public } from '@app/common/decorators/public.decorator';
import { RECAPTCHA_ACTIONS } from '@app/common/recaptcha/recaptcha.constants';
import { RecaptchaService } from '@app/common/recaptcha/recaptcha.service';

import { SubscribeNewsletterDto } from './dto/subscribe-newsletter.dto';
import { NewsletterService } from './newsletter.service';

@ApiTags('Ecommerce Newsletter')
@Controller('ecommerce/newsletter')
export class NewsletterController {
  constructor(
    private readonly newsletterService: NewsletterService,
    private readonly recaptcha: RecaptchaService,
  ) {}

  @Post('subscribe')
  @Public()
  @HttpCode(HttpStatus.CREATED)
  @UseGuards(ThrottlerGuard)
  @Throttle({ default: { limit: 5, ttl: 60_000 } })
  @ApiOperation({ summary: 'Suscribirse al boletín del ecommerce (público)' })
  async subscribe(@Body() dto: SubscribeNewsletterDto) {
    await this.recaptcha.verify(dto.captchaToken, RECAPTCHA_ACTIONS.newsletterSubscribe);
    return this.newsletterService.subscribe(dto);
  }
}

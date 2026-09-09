import { Body, Controller, HttpCode, HttpStatus, Post, UseGuards } from '@nestjs/common';
import { ApiOperation, ApiTags } from '@nestjs/swagger';
import { Throttle, ThrottlerGuard } from '@nestjs/throttler';

import { Public } from '@app/common/decorators/public.decorator';
import { RECAPTCHA_ACTIONS } from '@app/common/recaptcha/recaptcha.constants';
import { RecaptchaService } from '@app/common/recaptcha/recaptcha.service';

import { SubmitContactDto } from './dto/submit-contact.dto';
import { SubmitLibroReclamacionesDto } from './dto/submit-libro.dto';
import { SubmitWholesaleQuoteDto } from './dto/submit-wholesale-quote.dto';
import { InstitutionalService } from './institutional.service';

@ApiTags('Ecommerce Institutional')
@Controller('ecommerce/institutional')
export class InstitutionalController {
  constructor(
    private readonly institutionalService: InstitutionalService,
    private readonly recaptcha: RecaptchaService,
  ) {}

  @Post('contact')
  @Public()
  @HttpCode(HttpStatus.CREATED)
  @UseGuards(ThrottlerGuard)
  @Throttle({ default: { limit: 5, ttl: 60_000 } })
  @ApiOperation({ summary: 'Formulario Contáctanos (público)' })
  async submitContact(@Body() dto: SubmitContactDto) {
    if (dto.captchaToken) {
      await this.recaptcha.verify(dto.captchaToken, RECAPTCHA_ACTIONS.contactForm);
    }
    return this.institutionalService.submitContact(dto);
  }

  @Post('wholesale-quote')
  @Public()
  @HttpCode(HttpStatus.CREATED)
  @UseGuards(ThrottlerGuard)
  @Throttle({ default: { limit: 5, ttl: 60_000 } })
  @ApiOperation({ summary: 'Solicitud de cotización mayorista (público)' })
  async submitWholesaleQuote(@Body() dto: SubmitWholesaleQuoteDto) {
    if (dto.captchaToken) {
      await this.recaptcha.verify(dto.captchaToken, RECAPTCHA_ACTIONS.wholesaleQuote);
    }
    return this.institutionalService.submitWholesaleQuote(dto);
  }

  @Post('libro-reclamaciones')
  @Public()
  @HttpCode(HttpStatus.CREATED)
  @UseGuards(ThrottlerGuard)
  @Throttle({ default: { limit: 3, ttl: 60_000 } })
  @ApiOperation({ summary: 'Libro de reclamaciones (público)' })
  async submitLibro(@Body() dto: SubmitLibroReclamacionesDto) {
    if (dto.captchaToken) {
      await this.recaptcha.verify(dto.captchaToken, RECAPTCHA_ACTIONS.libroReclamaciones);
    }
    return this.institutionalService.submitLibroReclamaciones(dto);
  }
}

import { BadRequestException, Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

import type { RecaptchaAction } from './recaptcha.constants';

interface SiteVerifyResponse {
  success: boolean;
  score?: number;
  action?: string;
  'error-codes'?: string[];
}

@Injectable()
export class RecaptchaService {
  private readonly logger = new Logger(RecaptchaService.name);

  constructor(private readonly config: ConfigService) {}

  isEnabled(): boolean {
    return this.config.get<string>('RECAPTCHA_ENABLED', 'false') === 'true';
  }

  async verify(token: string | undefined, action: RecaptchaAction): Promise<void> {
    if (!this.isEnabled()) {
      return;
    }

    if (!token?.trim()) {
      throw new BadRequestException('Verificación de seguridad requerida.');
    }

    const secret = this.config.get<string>('RECAPTCHA_SECRET_KEY')?.trim();
    if (!secret) {
      this.logger.error('RECAPTCHA_ENABLED=true pero falta RECAPTCHA_SECRET_KEY');
      throw new BadRequestException('Verificación de seguridad no disponible.');
    }

    const minScore = Number.parseFloat(this.config.get<string>('RECAPTCHA_MIN_SCORE', '0.5'));
    const params = new URLSearchParams({
      secret,
      response: token.trim(),
    });

    let data: SiteVerifyResponse;
    try {
      const response = await fetch('https://www.google.com/recaptcha/api/siteverify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString(),
        signal: AbortSignal.timeout(10_000),
      });
      data = (await response.json()) as SiteVerifyResponse;
    } catch (error) {
      this.logger.warn(`reCAPTCHA siteverify failed: ${error}`);
      throw new BadRequestException('No pudimos verificar la seguridad del formulario.');
    }

    if (!data.success) {
      this.logger.debug(`reCAPTCHA rejected: ${data['error-codes']?.join(', ') ?? 'unknown'}`);
      throw new BadRequestException('No pudimos verificar la seguridad del formulario.');
    }

    if (data.action && data.action !== action) {
      throw new BadRequestException('Verificación de seguridad inválida.');
    }

    if (typeof data.score === 'number' && data.score < minScore) {
      throw new BadRequestException('No pudimos verificar la seguridad del formulario.');
    }
  }
}

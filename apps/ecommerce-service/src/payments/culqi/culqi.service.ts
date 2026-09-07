import {
  BadRequestException,
  Injectable,
  Logger,
  ServiceUnavailableException,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

import type {
  CulqiChargeResource,
  CulqiCreateChargeParams,
  CulqiCreateOrderParams,
  CulqiEventPayload,
  CulqiOrderResource,
} from './culqi.types';

const CULQI_API_BASE = 'https://api.culqi.com/v2';
const MIN_CULQI_ORDER_AMOUNT_CENTIMOS = 600;

@Injectable()
export class CulqiService {
  private readonly logger = new Logger(CulqiService.name);

  constructor(private readonly config: ConfigService) {}

  isEnabled(): boolean {
    return this.config.get<string>('CULQI_ENABLED', 'false') === 'true';
  }

  getSecretKey(): string | undefined {
    return this.config.get<string>('CULQI_SECRET_KEY')?.trim() || undefined;
  }

  getRsaConfig(): { rsaId: string; rsaPublicKey: string } {
    const rsaId = this.config.get<string>('CULQI_RSA_ID')?.trim();
    const rsaPublicKey = this.config
      .get<string>('CULQI_RSA_PUBLIC_KEY')
      ?.trim()
      ?.replace(/\\n/g, '\n');

    if (!rsaId || !rsaPublicKey) {
      throw new BadRequestException(
        'Yape requiere CULQI_RSA_ID y CULQI_RSA_PUBLIC_KEY en el servidor.',
      );
    }

    return { rsaId, rsaPublicKey };
  }

  assertConfigured(): void {
    if (!this.isEnabled()) {
      throw new BadRequestException('Los pagos con Culqi no están habilitados.');
    }

    if (!this.getSecretKey()) {
      this.logger.error('CULQI_ENABLED=true pero falta CULQI_SECRET_KEY');
      throw new ServiceUnavailableException('Pagos en línea no disponibles temporalmente.');
    }
  }

  assertOrderAmount(amountInCentimos: number): void {
    if (amountInCentimos < MIN_CULQI_ORDER_AMOUNT_CENTIMOS) {
      throw new BadRequestException(
        'El monto mínimo para pagar con tarjeta o Yape es S/ 6.00.',
      );
    }
  }

  isSuccessfulCharge(charge: CulqiChargeResource): boolean {
    return charge.outcome?.type === 'venta_exitosa';
  }

  isPaidOrder(order: CulqiOrderResource): boolean {
    return order.state === 'paid' || Boolean(order.paid_at);
  }

  parseEventData(event: CulqiEventPayload): Record<string, unknown> | null {
    const { data } = event;
    if (!data) {
      return null;
    }

    if (typeof data === 'string') {
      try {
        return JSON.parse(data) as Record<string, unknown>;
      } catch {
        return null;
      }
    }

    if (typeof data === 'object') {
      return data as Record<string, unknown>;
    }

    return null;
  }

  async createPaymentOrder(params: CulqiCreateOrderParams): Promise<CulqiOrderResource> {
    this.assertConfigured();
    this.assertOrderAmount(params.amount);

    const expirationDate = Math.floor(Date.now() / 1000) + 24 * 60 * 60;

    const response = await fetch(`${CULQI_API_BASE}/orders`, {
      method: 'POST',
      headers: this.buildHeaders(),
      body: JSON.stringify({
        amount: params.amount,
        currency_code: params.currencyCode,
        description: params.description.slice(0, 80),
        order_number: params.orderNumber.slice(0, 36),
        expiration_date: String(expirationDate),
        client_details: {
          first_name: params.firstName.slice(0, 50),
          last_name: params.lastName.slice(0, 50),
          email: params.email,
          phone_number: params.phoneNumber.replace(/\D/g, '').slice(0, 15),
        },
        metadata: params.metadata,
      }),
      signal: AbortSignal.timeout(20_000),
    });

    const body = (await response.json()) as CulqiOrderResource & {
      merchant_message?: string;
      user_message?: string;
    };

    if (!response.ok) {
      const message =
        body.user_message ?? body.merchant_message ?? 'No pudimos iniciar el pago con Culqi.';
      this.logger.warn(`Culqi order rejected: ${message}`);
      throw new BadRequestException(message);
    }

    return body;
  }

  async getPaymentOrder(orderId: string): Promise<CulqiOrderResource> {
    this.assertConfigured();

    const response = await fetch(`${CULQI_API_BASE}/orders/${encodeURIComponent(orderId)}`, {
      method: 'GET',
      headers: this.buildHeaders(),
      signal: AbortSignal.timeout(15_000),
    });

    if (!response.ok) {
      throw new BadRequestException('No pudimos verificar la orden en Culqi.');
    }

    return (await response.json()) as CulqiOrderResource;
  }

  async createCharge(params: CulqiCreateChargeParams): Promise<CulqiChargeResource> {
    this.assertConfigured();

    const response = await fetch(`${CULQI_API_BASE}/charges`, {
      method: 'POST',
      headers: this.buildHeaders(),
      body: JSON.stringify({
        amount: params.amount,
        currency_code: params.currencyCode,
        email: params.email,
        source_id: params.sourceId,
        description: params.description,
        metadata: params.metadata,
      }),
      signal: AbortSignal.timeout(20_000),
    });

    const body = (await response.json()) as CulqiChargeResource & {
      merchant_message?: string;
      user_message?: string;
    };

    if (!response.ok) {
      const message =
        body.user_message ??
        body.merchant_message ??
        'No pudimos procesar el pago con tarjeta.';
      this.logger.warn(`Culqi charge rejected: ${message}`);
      throw new BadRequestException(message);
    }

    return body;
  }

  async getCharge(chargeId: string): Promise<CulqiChargeResource> {
    this.assertConfigured();

    const response = await fetch(`${CULQI_API_BASE}/charges/${encodeURIComponent(chargeId)}`, {
      method: 'GET',
      headers: this.buildHeaders(),
      signal: AbortSignal.timeout(15_000),
    });

    if (!response.ok) {
      throw new BadRequestException('No pudimos verificar el cargo en Culqi.');
    }

    return (await response.json()) as CulqiChargeResource;
  }

  parseWebhookEvent(body: unknown): CulqiEventPayload {
    if (!body || typeof body !== 'object') {
      throw new BadRequestException('Evento Culqi inválido.');
    }

    return body as CulqiEventPayload;
  }

  extractChargeFromEventData(data: Record<string, unknown> | null): CulqiChargeResource | null {
    if (!data || data.object !== 'charge' || typeof data.id !== 'string') {
      return null;
    }

    return data as unknown as CulqiChargeResource;
  }

  extractOrderFromEventData(data: Record<string, unknown> | null): CulqiOrderResource | null {
    if (!data || data.object !== 'order' || typeof data.id !== 'string') {
      return null;
    }

    return data as unknown as CulqiOrderResource;
  }

  verifyWebhookBasicAuth(authorizationHeader: string | undefined): boolean {
    const expectedUser = this.config.get<string>('CULQI_WEBHOOK_USER')?.trim();
    const expectedPassword = this.config.get<string>('CULQI_WEBHOOK_PASSWORD')?.trim();

    if (!expectedUser || !expectedPassword) {
      this.logger.warn('Webhook Culqi sin CULQI_WEBHOOK_USER/PASSWORD configurados');
      return false;
    }

    if (!authorizationHeader?.startsWith('Basic ')) {
      return false;
    }

    const decoded = Buffer.from(authorizationHeader.slice(6), 'base64').toString('utf8');
    const separatorIndex = decoded.indexOf(':');
    if (separatorIndex === -1) {
      return false;
    }

    const user = decoded.slice(0, separatorIndex);
    const password = decoded.slice(separatorIndex + 1);

    return user === expectedUser && password === expectedPassword;
  }

  private buildHeaders(): Record<string, string> {
    return {
      Authorization: `Bearer ${this.getSecretKey()}`,
      'Content-Type': 'application/json',
    };
  }
}

import {
  BadRequestException,
  Injectable,
  Logger,
  NotFoundException,
  UnauthorizedException,
} from '@nestjs/common';
import { Decimal } from '@prisma/client/runtime/library';

import { DatabaseService } from '@app/database';

import { OrdersService } from '../../orders/orders.service';
import { EcommerceOrderEventsService } from '../../order-events/ecommerce-order-events.service';
import type { CulqiCheckoutSession, CulqiEventPayload } from './culqi.types';
import { CulqiService } from './culqi.service';

interface OrderAddress {
  firstName?: string;
  lastName?: string;
  phone?: string;
}

@Injectable()
export class CulqiPaymentsService {
  private readonly logger = new Logger(CulqiPaymentsService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly culqi: CulqiService,
    private readonly ordersService: OrdersService,
    private readonly orderEvents: EcommerceOrderEventsService,
  ) {}

  async prepareCheckoutSession(params: {
    orderNumber: string;
    email: string;
  }): Promise<CulqiCheckoutSession> {
    this.culqi.assertConfigured();
    const rsa = this.culqi.getRsaConfig();

    const order = await this.findCulqiOrder(params.orderNumber, params.email);
    const amountInCentimos = Math.round(Number(order.total) * 100);
    this.culqi.assertOrderAmount(amountInCentimos);

    if (order.culqiOrderId) {
      return {
        culqiOrderId: order.culqiOrderId,
        amountInCentimos,
        rsaId: rsa.rsaId,
        rsaPublicKey: rsa.rsaPublicKey,
      };
    }

    const billing = order.billingAddress as OrderAddress;
    const shipping = order.shippingAddress as OrderAddress;
    const culqiOrder = await this.culqi.createPaymentOrder({
      amount: amountInCentimos,
      currencyCode: order.currency || 'PEN',
      description: `Pedido ${order.orderNumber}`,
      orderNumber: order.orderNumber,
      email: order.email,
      firstName: billing.firstName?.trim() || shipping.firstName?.trim() || 'Cliente',
      lastName: billing.lastName?.trim() || shipping.lastName?.trim() || 'Maritex',
      phoneNumber: billing.phone?.trim() || shipping.phone?.trim() || '999999999',
      metadata: {
        order_number: order.orderNumber,
        order_id: order.id,
      },
    });

    await this.db.ecommerceOrder.update({
      where: { id: order.id },
      data: { culqiOrderId: culqiOrder.id },
    });

    return {
      culqiOrderId: culqiOrder.id,
      amountInCentimos,
      rsaId: rsa.rsaId,
      rsaPublicKey: rsa.rsaPublicKey,
    };
  }

  async chargeOrder(params: {
    orderNumber: string;
    email: string;
    culqiToken: string;
  }) {
    this.culqi.assertConfigured();

    const order = await this.findCulqiOrder(params.orderNumber, params.email, { includeItems: true });

    if (order.paymentStatus === 'paid') {
      return {
        orderNumber: order.orderNumber,
        paymentStatus: order.paymentStatus,
        culqiChargeId: order.culqiChargeId,
      };
    }

    if (order.paymentStatus !== 'pending') {
      throw new BadRequestException('Este pedido ya no admite un nuevo cargo.');
    }

    const amountInCentimos = Math.round(Number(order.total) * 100);
    const charge = await this.culqi.createCharge({
      amount: amountInCentimos,
      currencyCode: order.currency || 'PEN',
      email: order.email,
      sourceId: params.culqiToken,
      description: `Pedido ${order.orderNumber}`,
      metadata: {
        order_number: order.orderNumber,
        order_id: order.id,
        ...(order.culqiOrderId ? { culqi_order_id: order.culqiOrderId } : {}),
      },
    });

    if (!this.culqi.isSuccessfulCharge(charge)) {
      await this.rollbackFailedCheckout(order.orderNumber, order.email);

      throw new BadRequestException(
        charge.outcome?.user_message ??
          charge.outcome?.merchant_message ??
          'El pago fue rechazado. Intenta con otra tarjeta o Yape.',
      );
    }

    const updated = await this.db.ecommerceOrder.update({
      where: { id: order.id },
      data: {
        culqiChargeId: charge.id,
        paymentStatus: 'paid',
      },
      include: { items: true },
    });

    void this.orderEvents
      .publishOrderUpdated({
        previous: order,
        current: updated,
        source: 'culqi_charge',
      })
      .catch((error) => {
        this.logger.warn(`No se pudo publicar evento de pago ${order.orderNumber}`, error);
      });

    return {
      orderNumber: updated.orderNumber,
      paymentStatus: updated.paymentStatus,
      culqiChargeId: updated.culqiChargeId,
    };
  }

  async handleWebhookEvent(event: CulqiEventPayload) {
    const eventType = event.type?.trim();
    if (!eventType) {
      return { handled: false, reason: 'missing_type' };
    }

    if (eventType === 'order.status.changed') {
      return this.handleOrderStatusChanged(event);
    }

    if (eventType.startsWith('charge.')) {
      return this.handleChargeEvent(eventType, event);
    }

    if (eventType === 'refund.creation.succeeded') {
      return this.handleRefundSucceeded(event);
    }

    return { handled: false, reason: 'ignored_event', eventType };
  }

  assertWebhookAuthorized(authorizationHeader: string | undefined): void {
    if (!this.culqi.verifyWebhookBasicAuth(authorizationHeader)) {
      throw new UnauthorizedException('Webhook Culqi no autorizado.');
    }
  }

  private async handleOrderStatusChanged(event: CulqiEventPayload) {
    const eventData = this.culqi.parseEventData(event);
    const orderFromEvent = this.culqi.extractOrderFromEventData(eventData);
    if (!orderFromEvent?.id) {
      return { handled: false, reason: 'missing_order' };
    }

    const culqiOrder = await this.culqi.getPaymentOrder(orderFromEvent.id);
    if (!this.culqi.isPaidOrder(culqiOrder)) {
      return { handled: true, orderNumber: culqiOrder.order_number, paymentStatus: culqiOrder.state };
    }

    const order = await this.findLocalOrderByCulqiReferences(culqiOrder);
    if (!order) {
      this.logger.warn(`Webhook Culqi order sin pedido local: ${culqiOrder.id}`);
      return { handled: false, reason: 'order_not_found' };
    }

    const previous = await this.db.ecommerceOrder.findFirst({
      where: { id: order.id },
      include: { items: true },
    });

    if (!previous) {
      return { handled: false, reason: 'order_not_found' };
    }

    if (previous.paymentStatus === 'paid') {
      return { handled: true, orderNumber: previous.orderNumber, paymentStatus: 'paid' };
    }

    const expectedAmount = Math.round(Number(previous.total) * 100);
    if (culqiOrder.amount !== expectedAmount) {
      this.logger.error(
        `Monto orden Culqi distinto al pedido ${previous.orderNumber}: ${culqiOrder.amount} vs ${expectedAmount}`,
      );
      return { handled: false, reason: 'amount_mismatch' };
    }

    const updated = await this.db.ecommerceOrder.update({
      where: { id: previous.id },
      data: {
        culqiOrderId: culqiOrder.id,
        paymentStatus: 'paid',
      },
      include: { items: true },
    });

    void this.orderEvents
      .publishOrderUpdated({
        previous,
        current: updated,
        source: 'culqi_webhook',
      })
      .catch((error) => {
        this.logger.warn(`No se pudo publicar evento Culqi order ${previous.orderNumber}`, error);
      });

    return { handled: true, orderNumber: previous.orderNumber, paymentStatus: 'paid' };
  }

  private async handleChargeEvent(eventType: string, event: CulqiEventPayload) {
    const eventData = this.culqi.parseEventData(event);
    const chargeFromEvent = this.culqi.extractChargeFromEventData(eventData);
    if (!chargeFromEvent?.id) {
      return { handled: false, reason: 'missing_charge' };
    }

    const charge = await this.culqi.getCharge(chargeFromEvent.id);
    const orderNumber =
      typeof charge.metadata?.order_number === 'string'
        ? charge.metadata.order_number
        : undefined;

    let order = orderNumber
      ? await this.db.ecommerceOrder.findFirst({ where: { orderNumber }, include: { items: true } })
      : null;

    if (!order) {
      order = await this.db.ecommerceOrder.findFirst({
        where: { culqiChargeId: charge.id },
        include: { items: true },
      });
    }

    if (!order) {
      this.logger.warn(`Webhook Culqi sin pedido local: ${charge.id} (${eventType})`);
      return { handled: false, reason: 'order_not_found' };
    }

    if (eventType === 'charge.creation.succeeded' && this.culqi.isSuccessfulCharge(charge)) {
      if (order.paymentStatus === 'paid') {
        return { handled: true, orderNumber: order.orderNumber, paymentStatus: 'paid' };
      }

      const expectedAmount = Math.round(Number(order.total) * 100);
      if (charge.amount !== expectedAmount) {
        this.logger.error(
          `Monto Culqi distinto al pedido ${order.orderNumber}: ${charge.amount} vs ${expectedAmount}`,
        );
        return { handled: false, reason: 'amount_mismatch' };
      }

      const updated = await this.db.ecommerceOrder.update({
        where: { id: order.id },
        data: {
          culqiChargeId: charge.id,
          paymentStatus: 'paid',
        },
        include: { items: true },
      });

      void this.orderEvents
        .publishOrderUpdated({
          previous: order,
          current: updated,
          source: 'culqi_webhook',
        })
        .catch((error) => {
          this.logger.warn(`No se pudo publicar evento Culqi charge ${order.orderNumber}`, error);
        });

      return { handled: true, orderNumber: order.orderNumber, paymentStatus: 'paid' };
    }

    if (eventType === 'charge.creation.failed') {
      await this.rollbackFailedCheckout(order.orderNumber, order.email);

      return { handled: true, orderNumber: order.orderNumber, paymentStatus: 'cancelled' };
    }

    return { handled: false, reason: 'ignored_event', eventType };
  }

  private async handleRefundSucceeded(event: CulqiEventPayload) {
    const eventData = this.culqi.parseEventData(event);
    const chargeFromEvent = this.culqi.extractChargeFromEventData(eventData);
    if (!chargeFromEvent?.id) {
      return { handled: false, reason: 'missing_charge' };
    }

    const charge = await this.culqi.getCharge(chargeFromEvent.id);
    const orderNumber =
      typeof charge.metadata?.order_number === 'string'
        ? charge.metadata.order_number
        : undefined;

    const order = orderNumber
      ? await this.db.ecommerceOrder.findFirst({ where: { orderNumber }, include: { items: true } })
      : await this.db.ecommerceOrder.findFirst({
          where: { culqiChargeId: charge.id },
          include: { items: true },
        });

    if (!order) {
      return { handled: false, reason: 'order_not_found' };
    }

    const updated = await this.db.ecommerceOrder.update({
      where: { id: order.id },
      data: { paymentStatus: 'refunded' },
      include: { items: true },
    });

    await this.db.ecommerceRefund.updateMany({
      where: {
        orderId: order.id,
        status: { in: ['pending', 'approved'] },
      },
      data: { status: 'completed', amount: new Decimal(order.total) },
    });

    void this.orderEvents
      .publishOrderUpdated({
        previous: order,
        current: updated,
        source: 'culqi_webhook',
      })
      .catch((error) => {
        this.logger.warn(`No se pudo publicar evento de reembolso ${order.orderNumber}`, error);
      });

    return { handled: true, orderNumber: order.orderNumber, paymentStatus: 'refunded' };
  }

  private async rollbackFailedCheckout(orderNumber: string, email: string) {
    try {
      await this.ordersService.cancelPendingCheckoutOrder(orderNumber, email, {
        silent: true,
      });
    } catch (error) {
      this.logger.warn(
        `No se pudo revertir el pedido ${orderNumber} tras pago fallido: ${
          error instanceof Error ? error.message : String(error)
        }`,
      );
    }
  }

  private async findCulqiOrder(
    orderNumber: string,
    email: string,
    options?: { includeItems?: boolean },
  ) {
    const order = await this.db.ecommerceOrder.findFirst({
      where: {
        orderNumber: orderNumber.trim(),
        email: email.trim().toLowerCase(),
      },
      ...(options?.includeItems ? { include: { items: true } } : {}),
    });

    if (!order) {
      throw new NotFoundException('No encontramos un pedido con esos datos.');
    }

    if (order.paymentMethodId !== 'culqi') {
      throw new BadRequestException('Este pedido no usa pago con Culqi.');
    }

    return order;
  }

  private async findLocalOrderByCulqiReferences(culqiOrder: {
    id: string;
    order_number?: string;
    metadata?: Record<string, string | number | boolean | null>;
  }) {
    const metadataOrderNumber =
      typeof culqiOrder.metadata?.order_number === 'string'
        ? culqiOrder.metadata.order_number
        : undefined;

    if (metadataOrderNumber) {
      const byMetadata = await this.db.ecommerceOrder.findFirst({
        where: { orderNumber: metadataOrderNumber },
      });
      if (byMetadata) {
        return byMetadata;
      }
    }

    if (culqiOrder.order_number) {
      const byCulqiOrderNumber = await this.db.ecommerceOrder.findFirst({
        where: { orderNumber: culqiOrder.order_number },
      });
      if (byCulqiOrderNumber) {
        return byCulqiOrderNumber;
      }
    }

    return this.db.ecommerceOrder.findFirst({
      where: { culqiOrderId: culqiOrder.id },
    });
  }
}

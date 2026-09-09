import { Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

import { EcommerceMailTemplate, MailClientService } from '@app/mail-client';

import { ECOMMERCE_ORDER_STATUS_LABELS } from '../orders/constants/order-statuses';

type OrderWithItems = {
  id?: string;
  orderNumber: string;
  email: string;
  status: string;
  paymentStatus: string;
  shippingAddress: unknown;
  shippingMethodTitle: string;
  paymentMethodTitle: string;
  subtotal: number | { toNumber?: () => number };
  shippingTotal: number | { toNumber?: () => number };
  couponDiscount: number | { toNumber?: () => number };
  total: number | { toNumber?: () => number };
  items: Array<{
    nameSnapshot: string;
    variationLabel?: string | null;
    quantity: number;
    unitPrice: number | { toNumber?: () => number };
    subtotal: number | { toNumber?: () => number };
  }>;
};

@Injectable()
export class EcommerceMailNotificationsService {
  constructor(
    private readonly mailClient: MailClientService,
    private readonly config: ConfigService,
  ) {}

  private get storeUrl(): string {
    return this.config.get<string>(
      'ECOMMERCE_STORE_URL',
      this.config.get<string>('FRONTEND_URL', 'http://localhost:3001'),
    );
  }

  private get erpPanelUrl(): string {
    return (
      this.config.get<string>('ERP_PANEL_URL') ??
      this.config.get<string>('FRONTEND_URL', 'http://localhost:4200')
    ).replace(/\/$/, '');
  }

  private get supportEmail(): string {
    return (
      this.config.get<string>('MAIL_SUPPORT_EMAIL') ??
      this.config.get<string>('MAIL_FROM_EMAIL') ??
      'soporte@novedadesmaritex.net.pe'
    );
  }

  private toNumber(value: number | { toNumber?: () => number }): number {
    return typeof value === 'number' ? value : Number(value);
  }

  private customerNameFromShipping(shipping: Record<string, unknown> | null | undefined): string {
    if (!shipping) return 'Cliente';
    const first = String(shipping.firstName ?? shipping.first_name ?? '').trim();
    const last = String(shipping.lastName ?? shipping.last_name ?? '').trim();
    return [first, last].filter(Boolean).join(' ') || 'Cliente';
  }

  private buildTrackUrl(orderNumber: string, email: string): string {
    const params = new URLSearchParams({
      order_number: orderNumber,
      email_or_phone: email,
    });
    return `${this.storeUrl}/pedido/detalle?${params.toString()}`;
  }

  async sendOrderConfirmation(order: OrderWithItems): Promise<void> {
    const email = order.email.trim().toLowerCase();
    const shipping = order.shippingAddress as Record<string, unknown> | null;

    await this.mailClient.sendEcommerceMail({
      template: EcommerceMailTemplate.ORDER_CONFIRMATION,
      to: email,
      data: {
        customerName: this.customerNameFromShipping(shipping),
        orderNumber: order.orderNumber,
        items: order.items.map((item) => ({
          name: item.nameSnapshot,
          variationLabel: item.variationLabel ?? undefined,
          quantity: item.quantity,
          unitPrice: this.toNumber(item.unitPrice),
          subtotal: this.toNumber(item.subtotal),
        })),
        subtotal: this.toNumber(order.subtotal),
        shippingTotal: this.toNumber(order.shippingTotal),
        couponDiscount: this.toNumber(order.couponDiscount),
        total: this.toNumber(order.total),
        shippingMethodTitle: order.shippingMethodTitle,
        paymentMethodTitle: order.paymentMethodTitle,
        shippingAddress: (shipping ?? {}) as never,
        trackUrl: this.buildTrackUrl(order.orderNumber, email),
        storeUrl: this.storeUrl,
      },
    });
  }

  async sendStaffNewOrderAlert(order: OrderWithItems & { id: string }): Promise<void> {
    const itemCount = order.items.reduce((sum, item) => sum + item.quantity, 0);
    const shipping = order.shippingAddress as Record<string, unknown> | null;

    await this.mailClient.sendEcommerceMail({
      template: EcommerceMailTemplate.ORDER_STAFF_NEW,
      to: this.supportEmail,
      data: {
        orderNumber: order.orderNumber,
        orderId: order.id,
        customerName: this.customerNameFromShipping(shipping),
        customerEmail: order.email.trim().toLowerCase(),
        total: this.toNumber(order.total),
        paymentMethodTitle: order.paymentMethodTitle,
        shippingMethodTitle: order.shippingMethodTitle,
        itemCount,
        orderUrl: `${this.erpPanelUrl}/ecommerce/orders/${order.id}`,
        storeUrl: this.storeUrl,
      },
    });
  }

  async sendOrderStatusChange(
    previous: Pick<OrderWithItems, 'status' | 'paymentStatus' | 'orderNumber' | 'email' | 'shippingAddress'>,
    current: Pick<OrderWithItems, 'status' | 'paymentStatus' | 'orderNumber' | 'email' | 'shippingAddress' | 'total'>,
  ): Promise<void> {
    const email = current.email.trim().toLowerCase();
    const customerName = this.customerNameFromShipping(
      current.shippingAddress as Record<string, unknown> | null,
    );
    const trackUrl = this.buildTrackUrl(current.orderNumber, email);

    if (previous.paymentStatus !== 'paid' && current.paymentStatus === 'paid') {
      await this.mailClient.sendEcommerceMail({
        template: EcommerceMailTemplate.ORDER_PAYMENT_RECEIVED,
        to: email,
        data: {
          customerName,
          orderNumber: current.orderNumber,
          total: this.toNumber(current.total as number),
          trackUrl,
          storeUrl: this.storeUrl,
        },
      });
    }

    if (previous.status === current.status) {
      return;
    }

    if (current.status === 'delivered') {
      await this.mailClient.sendEcommerceMail({
        template: EcommerceMailTemplate.ORDER_DELIVERED,
        to: email,
        data: {
          customerName,
          orderNumber: current.orderNumber,
          reviewUrl: this.storeUrl,
          storeUrl: this.storeUrl,
        },
      });
      return;
    }

    if (current.status === 'cancelled') {
      await this.mailClient.sendEcommerceMail({
        template: EcommerceMailTemplate.ORDER_CANCELLED,
        to: email,
        data: {
          customerName,
          orderNumber: current.orderNumber,
          storeUrl: this.storeUrl,
        },
      });
      return;
    }

    const statusLabel =
      ECOMMERCE_ORDER_STATUS_LABELS[
        current.status as keyof typeof ECOMMERCE_ORDER_STATUS_LABELS
      ] ?? current.status;

    await this.mailClient.sendEcommerceMail({
      template: EcommerceMailTemplate.ORDER_STATUS_UPDATE,
      to: email,
      data: {
        customerName,
        orderNumber: current.orderNumber,
        status: current.status,
        statusLabel,
        trackUrl,
        storeUrl: this.storeUrl,
      },
    });
  }
}

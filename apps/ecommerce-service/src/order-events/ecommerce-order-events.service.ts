import { Injectable, Logger } from '@nestjs/common';

import { DatabaseService } from '@app/database';

import { ECOMMERCE_ORDER_STATUS_LABELS } from '../orders/constants/order-statuses';
import { getPaymentStatusLabel } from '../orders/constants/order-payment-statuses';
import { EcommerceInvoicingService } from '../ecommerce-invoicing/ecommerce-invoicing.service';
import { EcommerceMailNotificationsService } from '../mail/ecommerce-mail-notifications.service';
import { LowStockAlertsService } from '@app/common/inventory/low-stock-alerts.service';
import type { OrderEventOrder, OrderUpdatedEvent } from './ecommerce-order-events.types';

@Injectable()
export class EcommerceOrderEventsService {
  private readonly logger = new Logger(EcommerceOrderEventsService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly mailNotifications: EcommerceMailNotificationsService,
    private readonly invoicingService: EcommerceInvoicingService,
    private readonly lowStockAlerts: LowStockAlertsService,
  ) {}

  async publishOrderCreated(order: OrderEventOrder): Promise<void> {
    await this.createInAppNotification(order, {
      type: 'order',
      title: 'Pedido recibido',
      message: `Registramos tu pedido #${order.orderNumber}. Te avisaremos cuando haya novedades.`,
      metadata: {
        orderNumber: order.orderNumber,
        orderId: order.id,
        event: 'created',
      },
    });

    void this.mailNotifications.sendOrderConfirmation(order).catch((error) => {
      this.logger.warn(`No se pudo enviar confirmación de pedido ${order.orderNumber}`, error);
    });

    void this.mailNotifications.sendStaffNewOrderAlert(order).catch((error) => {
      this.logger.warn(
        `No se pudo notificar al equipo ERP del pedido ${order.orderNumber}`,
        error,
      );
    });
  }

  async publishOrderUpdated(event: OrderUpdatedEvent): Promise<void> {
    await this.dispatchOrderUpdated(event);

    if (
      event.previous.paymentStatus !== 'paid'
      && event.current.paymentStatus === 'paid'
    ) {
      void this.invoicingService
        .emitForPaidOrder(event.current.id)
        .catch((error) => {
          this.logger.warn(
            `No se pudo emitir comprobante SUNAT para pedido ${event.current.orderNumber}`,
            error,
          );
        });

      void this.notifyLowStockAfterPaidOrder(event.current.id, event.current.orderNumber).catch(
        (error) => {
          this.logger.warn(
            `No se pudo evaluar stock bajo tras pago ${event.current.orderNumber}`,
            error,
          );
        },
      );
    }

    if (event.silent) {
      return;
    }

    void this.mailNotifications
      .sendOrderStatusChange(event.previous, event.current)
      .catch((error) => {
        this.logger.warn(
          `No se pudo enviar correo de actualización ${event.current.orderNumber}`,
          error,
        );
      });
  }

  private async notifyLowStockAfterPaidOrder(orderId: string, orderNumber: string): Promise<void> {
    const order = await this.db.ecommerceOrder.findFirst({
      where: { id: orderId },
      select: {
        warehouseId: true,
        items: {
          where: { colorId: { not: null } },
          select: { productSizeId: true, colorId: true },
        },
      },
    });

    if (!order?.warehouseId || order.items.length === 0) {
      return;
    }

    const variants = order.items
      .filter((item): item is { productSizeId: string; colorId: string } => Boolean(item.colorId))
      .map((item) => ({
        productSizeId: item.productSizeId,
        colorId: item.colorId,
      }));

    await this.lowStockAlerts.checkAfterSale(order.warehouseId, variants, {
      source: 'ecommerce_order',
      referenceLabel: orderNumber,
    });
  }

  private async dispatchOrderUpdated(event: OrderUpdatedEvent): Promise<void> {
    const { previous, current } = event;
    const customerId = await this.resolveCustomerId(current);

    if (!customerId) {
      return;
    }

    const settings = await this.db.ecommerceCustomerNotificationSetting.findUnique({
      where: { customerId },
    });

    if (settings && !settings.orderUpdates) {
      return;
    }

    const notifications = this.buildInAppNotifications(previous, current);
    for (const notification of notifications) {
      await this.createInAppNotification(current, notification);
    }
  }

  private buildInAppNotifications(
    previous: OrderUpdatedEvent['previous'],
    current: OrderEventOrder,
  ): Array<{
    type: string;
    title: string;
    message: string;
    metadata: Record<string, string>;
  }> {
    const notifications: Array<{
      type: string;
      title: string;
      message: string;
      metadata: Record<string, string>;
    }> = [];

    const baseMetadata = {
      orderNumber: current.orderNumber,
      orderId: current.id,
    };

    const hasPaymentChange = previous.paymentStatus !== current.paymentStatus;
    const hasStatusChange = previous.status !== current.status;

    if (!hasPaymentChange && !hasStatusChange) {
      return notifications;
    }

    if (previous.paymentStatus !== 'paid' && current.paymentStatus === 'paid') {
      notifications.push({
        type: 'order',
        title: 'Pago confirmado',
        message: `Recibimos el pago de tu pedido #${current.orderNumber}.`,
        metadata: { ...baseMetadata, event: 'payment_paid', paymentStatus: current.paymentStatus },
      });
    }

    if (previous.status !== current.status) {
      if (current.status === 'cancelled') {
        notifications.push({
          type: 'order',
          title: 'Pedido cancelado',
          message: `Tu pedido #${current.orderNumber} fue cancelado.`,
          metadata: { ...baseMetadata, event: 'cancelled', status: current.status },
        });
      } else if (current.status === 'delivered') {
        notifications.push({
          type: 'order',
          title: 'Pedido entregado',
          message: `Tu pedido #${current.orderNumber} fue entregado. ¡Gracias por tu compra!`,
          metadata: { ...baseMetadata, event: 'delivered', status: current.status },
        });
      } else {
        const statusLabel =
          ECOMMERCE_ORDER_STATUS_LABELS[
            current.status as keyof typeof ECOMMERCE_ORDER_STATUS_LABELS
          ] ?? current.status;

        notifications.push({
          type: 'order',
          title: 'Actualización de pedido',
          message: `Tu pedido #${current.orderNumber} ahora está: ${statusLabel}.`,
          metadata: { ...baseMetadata, event: 'status_changed', status: current.status },
        });
      }
    } else if (
      previous.paymentStatus !== current.paymentStatus
      && current.paymentStatus !== 'paid'
    ) {
      notifications.push({
        type: 'order',
        title: 'Estado de pago actualizado',
        message: `El pago de tu pedido #${current.orderNumber} ahora está: ${getPaymentStatusLabel(current.paymentStatus)}.`,
        metadata: {
          ...baseMetadata,
          event: 'payment_status_changed',
          paymentStatus: current.paymentStatus,
        },
      });
    }

    return notifications;
  }

  private async resolveCustomerId(order: Pick<OrderEventOrder, 'customerId' | 'email'>) {
    if (order.customerId) {
      return order.customerId;
    }

    const customer = await this.db.ecommerceCustomer.findFirst({
      where: {
        email: order.email.trim().toLowerCase(),
        isEnabled: true,
      },
      select: { id: true },
    });

    return customer?.id ?? null;
  }

  private async createInAppNotification(
    order: Pick<OrderEventOrder, 'customerId' | 'email'>,
    notification: {
      type: string;
      title: string;
      message: string;
      metadata: Record<string, string>;
    },
  ) {
    const customerId = await this.resolveCustomerId(order);
    if (!customerId) {
      return;
    }

    const settings = await this.db.ecommerceCustomerNotificationSetting.findUnique({
      where: { customerId },
    });

    if (settings && !settings.orderUpdates) {
      return;
    }

    const duplicate = await this.hasDuplicateNotification(customerId, notification);

    if (duplicate) {
      return;
    }

    await this.db.ecommerceCustomerNotification.create({
      data: {
        customerId,
        type: notification.type,
        title: notification.title,
        message: notification.message,
        metadata: notification.metadata,
      },
    });
  }

  private async hasDuplicateNotification(
    customerId: string,
    notification: {
      type: string;
      metadata: Record<string, string>;
    },
  ) {
    const recent = await this.db.ecommerceCustomerNotification.findMany({
      where: { customerId, type: notification.type },
      orderBy: { createdAt: 'desc' },
      take: 20,
      select: { metadata: true },
    });

    return recent.some((item) => {
      if (!item.metadata || typeof item.metadata !== 'object' || Array.isArray(item.metadata)) {
        return false;
      }

      const metadata = item.metadata as Record<string, unknown>;
      return (
        metadata.orderNumber === notification.metadata.orderNumber
        && metadata.event === notification.metadata.event
      );
    });
  }
}

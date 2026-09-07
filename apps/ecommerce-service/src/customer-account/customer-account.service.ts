import {
  BadRequestException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';

import { DatabaseService } from '@app/database';

import { CreateRefundRequestDto } from './dto/create-refund-request.dto';
import { UpdateNotificationSettingsDto } from './dto/update-notification-settings.dto';
import { UpsertCustomerAddressDto } from './dto/upsert-customer-address.dto';

const REFUND_ELIGIBLE_STATUSES = new Set([
  'processing',
  'shipped',
  'out-for-delivery',
  'delivered',
]);

@Injectable()
export class CustomerAccountService {
  constructor(private readonly db: DatabaseService) {}

  listAddresses(customerId: string) {
    return this.db.ecommerceCustomerAddress.findMany({
      where: { customerId },
      orderBy: [{ isDefault: 'desc' }, { updatedAt: 'desc' }],
    });
  }

  async createAddress(customerId: string, dto: UpsertCustomerAddressDto) {
    if (dto.isDefault) {
      await this.clearDefaultAddress(customerId);
    }

    const isFirst = (await this.db.ecommerceCustomerAddress.count({ where: { customerId } })) === 0;

    return this.db.ecommerceCustomerAddress.create({
      data: {
        customerId,
        label: dto.label?.trim() || 'Principal',
        firstName: dto.firstName.trim(),
        lastName: dto.lastName.trim(),
        country: dto.country.trim().toUpperCase(),
        address1: dto.address1.trim(),
        address2: dto.address2?.trim() || null,
        city: dto.city.trim(),
        state: dto.state.trim(),
        postcode: dto.postcode.trim(),
        phone: dto.phone?.trim() || null,
        isDefault: dto.isDefault ?? isFirst,
      },
    });
  }

  async updateAddress(
    customerId: string,
    addressId: string,
    dto: UpsertCustomerAddressDto,
  ) {
    await this.ensureAddressOwnership(customerId, addressId);

    if (dto.isDefault) {
      await this.clearDefaultAddress(customerId);
    }

    return this.db.ecommerceCustomerAddress.update({
      where: { id: addressId },
      data: {
        label: dto.label?.trim() || 'Principal',
        firstName: dto.firstName.trim(),
        lastName: dto.lastName.trim(),
        country: dto.country.trim().toUpperCase(),
        address1: dto.address1.trim(),
        address2: dto.address2?.trim() || null,
        city: dto.city.trim(),
        state: dto.state.trim(),
        postcode: dto.postcode.trim(),
        phone: dto.phone?.trim() || null,
        ...(dto.isDefault !== undefined ? { isDefault: dto.isDefault } : {}),
      },
    });
  }

  async deleteAddress(customerId: string, addressId: string) {
    const address = await this.ensureAddressOwnership(customerId, addressId);
    await this.db.ecommerceCustomerAddress.delete({ where: { id: addressId } });

    if (address.isDefault) {
      const next = await this.db.ecommerceCustomerAddress.findFirst({
        where: { customerId },
        orderBy: { updatedAt: 'desc' },
      });

      if (next) {
        await this.db.ecommerceCustomerAddress.update({
          where: { id: next.id },
          data: { isDefault: true },
        });
      }
    }

    return { deleted: true };
  }

  async getNotificationSettings(customerId: string) {
    const existing = await this.db.ecommerceCustomerNotificationSetting.findUnique({
      where: { customerId },
    });

    if (existing) {
      return existing;
    }

    return this.db.ecommerceCustomerNotificationSetting.create({
      data: { customerId },
    });
  }

  updateNotificationSettings(customerId: string, dto: UpdateNotificationSettingsDto) {
    return this.db.ecommerceCustomerNotificationSetting.upsert({
      where: { customerId },
      create: {
        customerId,
        orderUpdates: dto.orderUpdates ?? true,
        promotions: dto.promotions ?? false,
        newsletter: dto.newsletter ?? false,
      },
      update: {
        ...(dto.orderUpdates !== undefined ? { orderUpdates: dto.orderUpdates } : {}),
        ...(dto.promotions !== undefined ? { promotions: dto.promotions } : {}),
        ...(dto.newsletter !== undefined ? { newsletter: dto.newsletter } : {}),
      },
    });
  }

  async listNotifications(customerId: string) {
    const settings = await this.getNotificationSettings(customerId);
    const excludedTypes = this.getExcludedNotificationTypes(settings);

    return this.db.ecommerceCustomerNotification.findMany({
      where: {
        customerId,
        ...(excludedTypes.length > 0 ? { type: { notIn: excludedTypes } } : {}),
      },
      orderBy: { createdAt: 'desc' },
      take: 50,
    });
  }

  private getExcludedNotificationTypes(settings: {
    orderUpdates: boolean;
    promotions: boolean;
    newsletter: boolean;
  }): string[] {
    const excluded: string[] = [];

    if (!settings.orderUpdates) {
      excluded.push('order', 'refund');
    }

    if (!settings.promotions) {
      excluded.push('promotion');
    }

    if (!settings.newsletter) {
      excluded.push('newsletter');
    }

    return excluded;
  }

  async markNotificationRead(customerId: string, notificationId: string) {
    const notification = await this.db.ecommerceCustomerNotification.findFirst({
      where: { id: notificationId, customerId },
    });

    if (!notification) {
      throw new NotFoundException('Notificación no encontrada.');
    }

    if (notification.readAt) {
      return notification;
    }

    return this.db.ecommerceCustomerNotification.update({
      where: { id: notificationId },
      data: { readAt: new Date() },
    });
  }

  async markAllNotificationsRead(customerId: string) {
    await this.db.ecommerceCustomerNotification.updateMany({
      where: { customerId, readAt: null },
      data: { readAt: new Date() },
    });

    return { updated: true };
  }

  listRefunds(customerId: string) {
    return this.db.ecommerceRefund.findMany({
      where: { customerId },
      orderBy: { createdAt: 'desc' },
      include: {
        order: {
          select: { orderNumber: true, total: true, status: true, paymentStatus: true },
        },
      },
    });
  }

  async createRefund(customerId: string, dto: CreateRefundRequestDto) {
    const order = await this.db.ecommerceOrder.findFirst({
      where: { customerId, orderNumber: dto.orderNumber.trim() },
    });

    if (!order) {
      throw new NotFoundException('Pedido no encontrado.');
    }

    if (order.paymentStatus !== 'paid') {
      throw new BadRequestException('Solo puedes solicitar reembolso de pedidos pagados.');
    }

    if (!REFUND_ELIGIBLE_STATUSES.has(order.status)) {
      throw new BadRequestException('Este pedido aún no es elegible para reembolso.');
    }

    const existingPending = await this.db.ecommerceRefund.findFirst({
      where: {
        orderId: order.id,
        status: { in: ['pending', 'approved'] },
      },
    });

    if (existingPending) {
      throw new BadRequestException('Ya existe una solicitud de reembolso para este pedido.');
    }

    const refund = await this.db.ecommerceRefund.create({
      data: {
        orderId: order.id,
        customerId,
        reason: dto.reason.trim(),
        amount: order.total,
        status: 'pending',
      },
      include: {
        order: {
          select: { orderNumber: true, total: true, status: true, paymentStatus: true },
        },
      },
    });

    await this.db.ecommerceCustomerNotification.create({
      data: {
        customerId,
        type: 'refund',
        title: 'Solicitud de reembolso recibida',
        message: `Registramos tu solicitud de reembolso para el pedido #${order.orderNumber}. Te avisaremos cuando sea revisada.`,
        metadata: { refundId: refund.id, orderNumber: order.orderNumber },
      },
    });

    return refund;
  }

  private async ensureAddressOwnership(customerId: string, addressId: string) {
    const address = await this.db.ecommerceCustomerAddress.findFirst({
      where: { id: addressId, customerId },
    });

    if (!address) {
      throw new NotFoundException('Dirección no encontrada.');
    }

    return address;
  }

  private async clearDefaultAddress(customerId: string) {
    await this.db.ecommerceCustomerAddress.updateMany({
      where: { customerId, isDefault: true },
      data: { isDefault: false },
    });
  }
}

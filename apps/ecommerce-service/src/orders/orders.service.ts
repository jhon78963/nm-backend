import {
  BadRequestException,
  Injectable,
  NotFoundException,
  UnprocessableEntityException,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import type { Prisma } from '@prisma/client';
import { DatabaseService } from '@app/database';
import { recordProductColorStockHistory } from '@app/common/utils/product-history.util';
import { syncMasterBalanceToColorSum } from '@app/common/utils/product-inventory.util';
import Decimal from 'decimal.js';

import {
  ECOMMERCE_ORDER_STATUS_LABELS,
  isEcommerceOrderStatus,
} from './constants/order-statuses';
import { getPaymentStatusLabel } from './constants/order-payment-statuses';
import {
  getPaymentMethod,
  getShippingMethod,
  resolveShippingZone,
} from './constants/shipping-payment.constants';
import { CreateOrderDto } from './dto/create-order.dto';
import type { OrderAddressDto } from './dto/create-order.dto';
import { ListCustomerOrdersQueryDto } from './dto/list-customer-orders-query.dto';
import { ListOrdersQueryDto } from './dto/list-orders-query.dto';
import { UpdateOrderDto } from './dto/update-order.dto';
import {
  buildOrderNumber,
  buildOrderNumberPrefix,
  normalizeOrderNumberForLookup,
} from './utils/order-number.util';
import type { AuthenticatedCustomer } from '../customer-auth/types/authenticated-customer.type';
import { EcommerceOrderEventsService } from '../order-events/ecommerce-order-events.service';
import { CouponsService } from '../coupons/coupons.service';

type ResolvedOrderItem = {
  productId: string;
  productSizeId: string;
  colorId: string;
  nameSnapshot: string;
  variationLabel?: string;
  imageUrl?: string;
  quantity: number;
  unitPrice: number;
  subtotal: number;
};

@Injectable()
export class OrdersService {
  constructor(
    private readonly db: DatabaseService,
    private readonly config: ConfigService,
    private readonly orderEvents: EcommerceOrderEventsService,
    private readonly couponsService: CouponsService,
  ) {}

  async createOrder(dto: CreateOrderDto, customer?: AuthenticatedCustomer) {
    const warehouse = await this.db.warehouse.findFirst({
      where: { id: dto.warehouseId, isDeleted: false },
    });

    if (!warehouse) {
      throw new NotFoundException('Tienda no encontrada.');
    }

    const customerId = await this.resolveLinkedCustomerId(dto.email, customer);

    const shippingZone = resolveShippingZone(dto.shipping.state, dto.shipping.postcode);
    const shippingMethod = getShippingMethod(dto.shippingMethodId, shippingZone);
    const paymentMethod = getPaymentMethod(dto.paymentMethodId);

    if (!shippingMethod) {
      throw new BadRequestException('Método de envío no válido para la zona seleccionada.');
    }

    if (!paymentMethod) {
      throw new BadRequestException('Método de pago no válido.');
    }

    const resolvedItems = await this.resolveOrderItems(dto);
    const subtotal = resolvedItems.reduce((sum, item) => sum + item.subtotal, 0);

    let resolvedCoupon = null;
    if (dto.couponCode?.trim()) {
      try {
        resolvedCoupon = await this.couponsService.resolveCouponForOrder(
          dto.couponCode,
          subtotal,
          customerId,
          dto.warehouseId,
          dto.clientIp,
        );
      } catch (error) {
        if (error instanceof BadRequestException) {
          throw error;
        }
        throw new BadRequestException('Cupón no válido.');
      }

      if (!resolvedCoupon) {
        throw new BadRequestException('Cupón no válido.');
      }
    }

    const couponDiscount = resolvedCoupon?.discountAmount ?? 0;
    const shippingTotal = shippingMethod.cost;
    const total = Math.max(0, subtotal + shippingTotal - couponDiscount);

    await this.validateStock(dto.warehouseId, resolvedItems);

    const systemUserId = await this.resolveSystemUserId(dto.warehouseId);
    const orderNumber = await this.generateUniqueOrderNumber();

    const order = await this.db.$transaction(async (tx) => {
      const created = await tx.ecommerceOrder.create({
        data: {
          orderNumber,
          warehouseId: dto.warehouseId,
          customerId,
          status: 'pending',
          paymentStatus: 'pending',
          email: dto.email.trim().toLowerCase(),
          billingAddress: dto.billing as object,
          shippingAddress: dto.shipping as object,
          sameAsBilling: dto.sameAsBilling ?? true,
          orderNotes: dto.orderNotes?.trim() || null,
          shippingMethodId: shippingMethod.id,
          shippingMethodTitle: shippingMethod.title,
          shippingTotal: new Decimal(shippingTotal).toDecimalPlaces(2).toNumber(),
          paymentMethodId: paymentMethod.id,
          paymentMethodTitle: paymentMethod.title,
          subtotal: new Decimal(subtotal).toDecimalPlaces(2).toNumber(),
          couponCode: dto.couponCode?.trim().toUpperCase() || null,
          couponDiscount: new Decimal(couponDiscount).toDecimalPlaces(2).toNumber(),
          taxAmount: 0,
          total: new Decimal(total).toDecimalPlaces(2).toNumber(),
          items: {
            create: resolvedItems.map((item) => ({
              productId: item.productId,
              productSizeId: item.productSizeId,
              colorId: item.colorId,
              nameSnapshot: item.nameSnapshot,
              variationLabel: item.variationLabel,
              imageUrl: item.imageUrl,
              quantity: item.quantity,
              unitPrice: new Decimal(item.unitPrice).toDecimalPlaces(2).toNumber(),
              subtotal: new Decimal(item.subtotal).toDecimalPlaces(2).toNumber(),
            })),
          },
        },
        include: { items: true },
      });

      for (const item of resolvedItems) {
        const existingBalance = await tx.inventoryBalance.findFirst({
          where: {
            warehouseId: dto.warehouseId,
            productSizeId: item.productSizeId,
            colorId: item.colorId,
          },
          select: { quantity: true },
        });
        const oldStock = existingBalance?.quantity ?? 0;

        const updated = await tx.inventoryBalance.update({
          where: {
            warehouseId_productSizeId_colorId: {
              warehouseId: dto.warehouseId,
              productSizeId: item.productSizeId,
              colorId: item.colorId,
            },
          },
          data: { quantity: { decrement: item.quantity } },
        });

        await tx.inventoryMovement.create({
          data: {
            warehouseId: dto.warehouseId,
            productSizeId: item.productSizeId,
            colorId: item.colorId,
            direction: 'OUT',
            quantity: item.quantity,
            movementType: 'ECOMMERCE_ORDER',
            referenceId: created.id,
            referenceType: 'EcommerceOrder',
            balanceAfter: updated.quantity,
            occurredAt: new Date(),
            createdById: systemUserId,
          },
        });

        await syncMasterBalanceToColorSum(tx, dto.warehouseId, item.productSizeId);

        await recordProductColorStockHistory(tx, {
          productId: item.productId,
          productSizeId: item.productSizeId,
          colorId: item.colorId,
          oldStock,
          newStock: updated.quantity,
          createdById: systemUserId,
          eventType: 'ECOMMERCE_ORDER_STOCK',
          reason: `Pedido ecommerce ${orderNumber}`,
          orderNumber,
        });
      }

      if (customerId) {
        await this.seedDefaultCustomerAddressIfNeeded(tx, customerId, dto.shipping);
      }

      if (resolvedCoupon) {
        await this.couponsService.redeemCoupon(tx, {
          couponId: resolvedCoupon.couponId,
          orderId: created.id,
          customerId,
          clientIp: dto.clientIp,
          discountAmount: couponDiscount,
        });
      }

      return created;
    });

    void this.orderEvents.publishOrderCreated(order).catch(() => undefined);

    return this.mapPublicOrder(order);
  }

  async cancelPendingCheckoutOrder(
    orderNumber: string,
    email: string,
    options?: { silent?: boolean },
  ) {
    const existing = await this.db.ecommerceOrder.findFirst({
      where: {
        orderNumber: normalizeOrderNumberForLookup(orderNumber),
        email: email.trim().toLowerCase(),
      },
      include: { items: true },
    });

    if (!existing) {
      throw new NotFoundException('No encontramos un pedido con esos datos.');
    }

    if (existing.paymentStatus === 'paid') {
      throw new BadRequestException('No se puede cancelar un pedido ya pagado.');
    }

    if (existing.status === 'cancelled') {
      return {
        orderNumber: existing.orderNumber,
        status: existing.status,
        paymentStatus: existing.paymentStatus,
      };
    }

    if (existing.status !== 'pending') {
      throw new BadRequestException('Este pedido ya no se puede cancelar automáticamente.');
    }

    const order = await this.db.$transaction(async (tx) => {
      await this.restoreOrderInventory(tx, existing, 'ECOMMERCE_ORDER_CANCEL');
      await this.reverseCouponRedemption(tx, existing.id);

      return tx.ecommerceOrder.update({
        where: { id: existing.id },
        data: {
          status: 'cancelled',
          cancelledAt: new Date(),
        },
        include: { items: true },
      });
    });

    if (!options?.silent) {
      void this.orderEvents
        .publishOrderUpdated({
          previous: existing,
          current: order,
          source: 'customer',
        })
        .catch(() => undefined);
    }

    return {
      orderNumber: order.orderNumber,
      status: order.status,
      paymentStatus: order.paymentStatus,
    };
  }

  async trackOrder(orderNumber: string, contact: string) {
    const order = await this.findOrderByNumberAndContact(orderNumber, contact);
    if (!order) {
      throw new NotFoundException('No encontramos un pedido con esos datos.');
    }

    return this.mapPublicOrder(order);
  }

  async getPublicOrder(orderNumber: string, email: string) {
    const order = await this.db.ecommerceOrder.findFirst({
      where: {
        orderNumber: normalizeOrderNumberForLookup(orderNumber),
        email: email.trim().toLowerCase(),
      },
      include: { items: true },
    });

    if (!order) {
      throw new NotFoundException('Pedido no encontrado.');
    }

    return this.mapPublicOrder(order);
  }

  async listCustomerOrders(customerId: string, query: ListCustomerOrdersQueryDto) {
    const page = query.page ?? 1;
    const perPage = query.perPage ?? 10;
    const skip = (page - 1) * perPage;

    const where = { customerId };

    const [orders, total] = await Promise.all([
      this.db.ecommerceOrder.findMany({
        where,
        orderBy: { createdAt: 'desc' },
        skip,
        take: perPage,
        select: {
          id: true,
          orderNumber: true,
          status: true,
          paymentStatus: true,
          paymentMethodTitle: true,
          total: true,
          createdAt: true,
        },
      }),
      this.db.ecommerceOrder.count({ where }),
    ]);

    return {
      orders: orders.map((order) => ({
        id: order.id,
        orderNumber: order.orderNumber,
        status: order.status,
        statusLabel:
          ECOMMERCE_ORDER_STATUS_LABELS[
            order.status as keyof typeof ECOMMERCE_ORDER_STATUS_LABELS
          ] ?? order.status,
        paymentStatus: order.paymentStatus,
        paymentStatusLabel: getPaymentStatusLabel(order.paymentStatus),
        paymentMethodTitle: order.paymentMethodTitle,
        total: Number(order.total),
        createdAt: order.createdAt,
      })),
      meta: {
        total,
        page,
        perPage,
        totalPages: Math.max(1, Math.ceil(total / perPage)),
      },
    };
  }

  async getCustomerOrder(customerId: string, orderNumber: string) {
    const normalizedNumber = normalizeOrderNumberForLookup(orderNumber);
    const order = await this.db.ecommerceOrder.findFirst({
      where: { customerId, orderNumber: normalizedNumber },
      include: { items: true },
    });

    if (!order) {
      throw new NotFoundException('Pedido no encontrado.');
    }

    return this.mapPublicOrder(order);
  }

  async listAdminOrders(query: ListOrdersQueryDto) {
    const page = query.page ?? 1;
    const perPage = query.perPage ?? 20;
    const skip = (page - 1) * perPage;

    const where = {
      ...(query.status ? { status: query.status } : {}),
      ...(query.search
        ? {
            OR: [
              { orderNumber: { contains: query.search, mode: 'insensitive' as const } },
              { email: { contains: query.search, mode: 'insensitive' as const } },
            ],
          }
        : {}),
    };

    const [orders, total] = await Promise.all([
      this.db.ecommerceOrder.findMany({
        where,
        orderBy: { createdAt: 'desc' },
        skip,
        take: perPage,
        include: { items: true },
      }),
      this.db.ecommerceOrder.count({ where }),
    ]);

    return {
      orders: orders.map((order) => this.mapAdminOrder(order)),
      meta: {
        total,
        page,
        perPage,
        totalPages: Math.max(1, Math.ceil(total / perPage)),
      },
    };
  }

  async getAdminOrder(id: string) {
    const order = await this.db.ecommerceOrder.findFirst({
      where: { id },
      include: { items: true },
    });

    if (!order) {
      throw new NotFoundException('Pedido no encontrado.');
    }

    return this.mapAdminOrder(order);
  }

  async updateAdminOrder(id: string, dto: UpdateOrderDto) {
    const existing = await this.db.ecommerceOrder.findFirst({
      where: { id },
      include: { items: true },
    });

    if (!existing) {
      throw new NotFoundException('Pedido no encontrado.');
    }

    if (dto.status && !isEcommerceOrderStatus(dto.status)) {
      throw new BadRequestException('Estado de pedido no válido.');
    }

    const shouldRestoreStock =
      dto.status === 'cancelled' && existing.status !== 'cancelled';

    const order = await this.db.$transaction(async (tx) => {
      if (shouldRestoreStock) {
        await this.restoreOrderInventory(tx, existing, 'ECOMMERCE_ORDER_CANCEL');
      }

      return tx.ecommerceOrder.update({
        where: { id },
        data: {
          ...(dto.status ? { status: dto.status } : {}),
          ...(dto.paymentStatus ? { paymentStatus: dto.paymentStatus } : {}),
          ...(dto.orderNotes !== undefined ? { orderNotes: dto.orderNotes || null } : {}),
          ...(dto.status === 'cancelled' && existing.status !== 'cancelled'
            ? { cancelledAt: new Date() }
            : {}),
        },
        include: { items: true },
      });
    });

    void this.orderEvents
      .publishOrderUpdated({
        previous: existing,
        current: order,
        source: 'admin',
      })
      .catch(() => undefined);

    return this.mapAdminOrder(order);
  }

  private async resolveOrderItems(dto: CreateOrderDto): Promise<ResolvedOrderItem[]> {
    const resolved: ResolvedOrderItem[] = [];

    for (const item of dto.items) {
      const productSize = await this.db.productSize.findFirst({
        where: {
          id: item.productSizeId,
          productId: item.productId,
          isDeleted: false,
          product: {
            id: item.productId,
            warehouseId: dto.warehouseId,
            isDeleted: false,
          },
        },
        include: {
          product: { select: { name: true, offerPrice: true } },
          productSizeColors: { select: { colorId: true } },
        },
      });

      if (!productSize) {
        throw new BadRequestException(`Producto o variante no válida: ${item.name}`);
      }

      const colorId = await this.resolveColorId(
        item.productSizeId,
        item.colorId,
        productSize.productSizeColors.map((link) => link.colorId),
      );

      const offerPrice =
        productSize.product.offerPrice != null
          ? Number(productSize.product.offerPrice)
          : null;
      const serverPrice =
        offerPrice != null && offerPrice > 0 ? offerPrice : Number(productSize.salePrice);
      if (Math.abs(serverPrice - item.unitPrice) > 0.02) {
        throw new BadRequestException(`El precio de "${item.name}" cambió. Actualiza tu carrito.`);
      }

      resolved.push({
        productId: item.productId,
        productSizeId: item.productSizeId,
        colorId,
        nameSnapshot: item.name || productSize.product.name,
        variationLabel: item.variation,
        imageUrl: item.imageUrl,
        quantity: item.quantity,
        unitPrice: serverPrice,
        subtotal: serverPrice * item.quantity,
      });
    }

    return resolved;
  }

  private async resolveColorId(
    productSizeId: string,
    requestedColorId: string | undefined,
    linkedColorIds: string[],
  ): Promise<string> {
    if (requestedColorId) {
      if (!linkedColorIds.includes(requestedColorId)) {
        throw new BadRequestException('Color no válido para la variante seleccionada.');
      }

      return requestedColorId;
    }

    if (linkedColorIds.length === 1) {
      return linkedColorIds[0];
    }

    if (linkedColorIds.length === 0) {
      const fallback = await this.db.color.findFirst({
        where: { description: 'Sin color', isDeleted: false },
        select: { id: true },
      });

      if (!fallback) {
        throw new BadRequestException('No se pudo determinar el color del producto.');
      }

      return fallback.id;
    }

    throw new BadRequestException('Debe seleccionar un color para completar el pedido.');
  }

  private async validateStock(warehouseId: string, items: ResolvedOrderItem[]) {
    for (const item of items) {
      const balance = await this.db.inventoryBalance.findFirst({
        where: {
          warehouseId,
          productSizeId: item.productSizeId,
          colorId: item.colorId,
        },
      });

      if (!balance || balance.quantity < item.quantity) {
        throw new UnprocessableEntityException(
          `Stock insuficiente para "${item.nameSnapshot}" (disponible: ${balance?.quantity ?? 0}).`,
        );
      }
    }
  }

  private async generateUniqueOrderNumber(): Promise<string> {
    const prefix = buildOrderNumberPrefix();
    const countToday = await this.db.ecommerceOrder.count({
      where: {
        orderNumber: { startsWith: prefix },
      },
    });

    for (let attempt = 0; attempt < 5; attempt += 1) {
      const candidate = buildOrderNumber(countToday + 1 + attempt);
      const exists = await this.db.ecommerceOrder.findFirst({
        where: { orderNumber: candidate },
        select: { id: true },
      });

      if (!exists) {
        return candidate;
      }
    }

    return `${prefix}-${Date.now().toString().slice(-6)}`;
  }

  private async seedDefaultCustomerAddressIfNeeded(
    tx: Pick<DatabaseService, 'ecommerceCustomerAddress'>,
    customerId: string,
    address: OrderAddressDto,
  ): Promise<void> {
    const savedAddressCount = await tx.ecommerceCustomerAddress.count({
      where: { customerId },
    });

    if (savedAddressCount > 0) {
      return;
    }

    await tx.ecommerceCustomerAddress.create({
      data: {
        customerId,
        label: 'Principal',
        firstName: address.firstName.trim(),
        lastName: address.lastName.trim(),
        country: address.country.trim().toUpperCase(),
        address1: address.address1.trim(),
        address2: address.address2?.trim() || null,
        city: address.city.trim(),
        state: address.state.trim(),
        postcode: address.postcode.trim(),
        phone: address.phone?.trim() || null,
        isDefault: true,
      },
    });
  }

  private async resolveLinkedCustomerId(
    email: string,
    customer?: AuthenticatedCustomer,
  ): Promise<string | null> {
    if (!customer) {
      return null;
    }

    const normalizedEmail = email.trim().toLowerCase();
    if (normalizedEmail !== customer.email.toLowerCase()) {
      throw new BadRequestException(
        'El correo del pedido debe coincidir con el de tu cuenta.',
      );
    }

    const record = await this.db.ecommerceCustomer.findFirst({
      where: { id: customer.id, isEnabled: true },
      select: { id: true },
    });

    if (!record) {
      throw new BadRequestException('No se pudo vincular el pedido a tu cuenta.');
    }

    return record.id;
  }

  private async resolveSystemUserId(warehouseId: string): Promise<string> {
    const configured = this.config.get<string>('ECOMMERCE_SYSTEM_USER_ID');
    if (configured) {
      return configured;
    }

    const warehouseUser = await this.db.user.findFirst({
      where: { warehouseId, isDeleted: false, isEnabled: true },
      select: { id: true },
      orderBy: { createdAt: 'asc' },
    });

    if (warehouseUser) {
      return warehouseUser.id;
    }

    const anyUser = await this.db.user.findFirst({
      where: { isDeleted: false, isEnabled: true },
      select: { id: true },
      orderBy: { createdAt: 'asc' },
    });

    if (!anyUser) {
      throw new BadRequestException('No hay usuario del sistema para registrar movimientos de inventario.');
    }

    return anyUser.id;
  }

  private async restoreOrderInventory(
    tx: Prisma.TransactionClient,
    order: {
      id: string;
      orderNumber: string;
      warehouseId: string;
      items: Array<{
        productId: string;
        productSizeId: string;
        colorId: string | null;
        quantity: number;
      }>;
    },
    movementType: 'ECOMMERCE_ORDER_CANCEL',
  ) {
    const systemUserId = await this.resolveSystemUserId(order.warehouseId);

    for (const item of order.items) {
      if (!item.colorId) continue;

      const existingBalance = await tx.inventoryBalance.findFirst({
        where: {
          warehouseId: order.warehouseId,
          productSizeId: item.productSizeId,
          colorId: item.colorId,
        },
        select: { quantity: true },
      });
      const oldStock = existingBalance?.quantity ?? 0;

      const updated = await tx.inventoryBalance.update({
        where: {
          warehouseId_productSizeId_colorId: {
            warehouseId: order.warehouseId,
            productSizeId: item.productSizeId,
            colorId: item.colorId,
          },
        },
        data: { quantity: { increment: item.quantity } },
      });

      await tx.inventoryMovement.create({
        data: {
          warehouseId: order.warehouseId,
          productSizeId: item.productSizeId,
          colorId: item.colorId,
          direction: 'IN',
          quantity: item.quantity,
          movementType,
          referenceId: order.id,
          referenceType: 'EcommerceOrder',
          balanceAfter: updated.quantity,
          occurredAt: new Date(),
          createdById: systemUserId,
        },
      });

      await syncMasterBalanceToColorSum(tx, order.warehouseId, item.productSizeId);

      await recordProductColorStockHistory(tx, {
        productId: item.productId,
        productSizeId: item.productSizeId,
        colorId: item.colorId,
        oldStock,
        newStock: updated.quantity,
        createdById: systemUserId,
        eventType: 'ECOMMERCE_ORDER_CANCEL_STOCK',
        reason: `Cancelación pedido ${order.orderNumber}`,
        orderNumber: order.orderNumber,
      });
    }
  }

  private async reverseCouponRedemption(
    tx: Prisma.TransactionClient,
    orderId: string,
  ) {
    const redemption = await tx.ecommerceCouponRedemption.findFirst({
      where: { orderId },
    });

    if (!redemption) {
      return;
    }

    await tx.ecommerceCouponRedemption.delete({ where: { id: redemption.id } });
    await tx.ecommerceCoupon.update({
      where: { id: redemption.couponId },
      data: { usageCount: { decrement: 1 } },
    });
  }

  private async findOrderByNumberAndContact(orderNumber: string, contact: string) {
    const normalizedNumber = normalizeOrderNumberForLookup(orderNumber);
    const normalizedContact = contact.trim().toLowerCase();
    const normalizedPhone = contact.replace(/\s/g, '');

    const orders = await this.db.ecommerceOrder.findMany({
      where: { orderNumber: normalizedNumber },
      include: { items: true },
      take: 1,
    });

    const order = orders[0];
    if (!order) return null;

    const billing = order.billingAddress as { phone?: string };
    const shipping = order.shippingAddress as { phone?: string };
    const emailMatch = order.email.toLowerCase() === normalizedContact;
    const billingPhone = (billing.phone ?? '').replace(/\s/g, '');
    const shippingPhone = (shipping.phone ?? '').replace(/\s/g, '');
    const phoneMatch =
      (billingPhone && billingPhone === normalizedPhone)
      || (shippingPhone && shippingPhone === normalizedPhone);

    return emailMatch || phoneMatch ? order : null;
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private mapPublicOrder(order: any) {
    return {
      id: order.id,
      orderNumber: order.orderNumber,
      status: order.status,
      statusLabel: ECOMMERCE_ORDER_STATUS_LABELS[order.status as keyof typeof ECOMMERCE_ORDER_STATUS_LABELS] ?? order.status,
      paymentStatus: order.paymentStatus,
      paymentStatusLabel: getPaymentStatusLabel(order.paymentStatus),
      createdAt: order.createdAt,
      email: order.email,
      billing: order.billingAddress,
      shipping: order.shippingAddress,
      orderNotes: order.orderNotes,
      shippingMethodId: order.shippingMethodId,
      shippingMethodTitle: order.shippingMethodTitle,
      shippingTotal: Number(order.shippingTotal),
      paymentMethodId: order.paymentMethodId,
      paymentMethodTitle: order.paymentMethodTitle,
      subtotal: Number(order.subtotal),
      couponCode: order.couponCode,
      couponDiscount: Number(order.couponDiscount),
      total: Number(order.total),
      items: (order.items ?? []).map((item: {
        id: string;
        productId: string;
        productSizeId: string;
        colorId: string | null;
        nameSnapshot: string;
        variationLabel: string | null;
        imageUrl: string | null;
        quantity: number;
        unitPrice: { toNumber?: () => number } | number | string;
        subtotal: { toNumber?: () => number } | number | string;
      }) => ({
        id: item.id,
        productId: item.productId,
        productSizeId: item.productSizeId,
        colorId: item.colorId,
        name: item.nameSnapshot,
        variation: item.variationLabel,
        imageUrl: item.imageUrl,
        quantity: item.quantity,
        unitPrice: Number(item.unitPrice),
        subtotal: Number(item.subtotal),
      })),
    };
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private mapAdminOrder(order: any) {
    return {
      ...this.mapPublicOrder(order),
      warehouseId: order.warehouseId,
      cancelledAt: order.cancelledAt,
      updatedAt: order.updatedAt,
    };
  }
}

import { BadRequestException, Injectable, Logger, NotFoundException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { DatabaseService } from '@app/database';
import { Decimal } from '@prisma/client/runtime/library';
import { randomBytes } from 'crypto';

import { InvoicingClientService } from './invoicing-client.service';

const IGV_RATE = 0.18;
const DOCUMENT_TYPE = 'BOLETA';

interface BillingAddressJson {
  firstName?: string;
  lastName?: string;
  phone?: string;
}

function generateSaleCode(): string {
  return `EC-${randomBytes(8).toString('hex').toUpperCase()}`;
}

function roundMoney(value: number): number {
  return Math.round(value * 100) / 100;
}

function mapPaymentMethod(paymentMethodId: string): string {
  switch (paymentMethodId) {
    case 'culqi':
    case 'card':
      return 'CARD';
    case 'yape':
    case 'plin':
      return 'YAPE';
    default:
      return 'CASH';
  }
}

function mapSunatStatus(value?: string): string {
  switch ((value ?? '').toUpperCase()) {
    case 'ACCEPTED':
      return 'ACCEPTED';
    case 'REJECTED':
      return 'REJECTED';
    case 'PENDING':
      return 'PENDING';
    default:
      return 'PENDING_EMISSION';
  }
}

@Injectable()
export class EcommerceInvoicingService {
  private readonly logger = new Logger(EcommerceInvoicingService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly config: ConfigService,
    private readonly invoicingClient: InvoicingClientService,
  ) {}

  async emitForPaidOrder(orderId: string): Promise<void> {
    const order = await this.db.ecommerceOrder.findFirst({
      where: { id: orderId },
      include: { items: true },
    });

    if (!order || order.paymentStatus !== 'paid' || order.saleId) {
      return;
    }

    const fiscalEnabled = await this.isElectronicInvoicingEnabled(order.warehouseId);
    if (!fiscalEnabled) {
      return;
    }

    const systemUserId = await this.resolveSystemUserId(order.warehouseId);
    let saleId: string | null = null;

    try {
      saleId = await this.createSaleFromOrder(order, systemUserId);
    } catch (error) {
      this.logger.error(
        `No se pudo crear la venta fiscal para pedido ${order.orderNumber}`,
        error instanceof Error ? error.stack : String(error),
      );
      return;
    }

    if (!saleId) {
      return;
    }

    try {
      const result = await this.invoicingClient.sendInvoice(saleId);
      const sunatStatus = mapSunatStatus(result.sunatStatus);

      await this.db.$transaction([
        this.db.sale.update({
          where: { id: saleId },
          data: {
            sunatStatus,
            ...(result.fullInvoiceNumber
              ? { fullInvoiceNumber: result.fullInvoiceNumber }
              : {}),
          },
        }),
        this.db.ecommerceOrder.update({
          where: { id: order.id },
          data: {
            sunatStatus,
            ...(result.fullInvoiceNumber
              ? { fullInvoiceNumber: result.fullInvoiceNumber }
              : {}),
          },
        }),
      ]);
    } catch (error) {
      this.logger.warn(
        `Emisión SUNAT pendiente para pedido ${order.orderNumber}: ${error instanceof Error ? error.message : String(error)}`,
      );

      await this.db.ecommerceOrder.update({
        where: { id: order.id },
        data: { sunatStatus: 'PENDING_EMISSION' },
      });
    }
  }

  async getCustomerInvoicePdf(
    customerId: string,
    orderNumber: string,
  ): Promise<{ buffer: Buffer; filename: string }> {
    const order = await this.db.ecommerceOrder.findFirst({
      where: { customerId, orderNumber },
      select: {
        saleId: true,
        paymentStatus: true,
        fullInvoiceNumber: true,
        orderNumber: true,
      },
    });

    if (!order) {
      throw new NotFoundException('Pedido no encontrado.');
    }

    if (order.paymentStatus !== 'paid' || !order.saleId) {
      throw new BadRequestException('El comprobante aún no está disponible para este pedido.');
    }

    const pdf = await this.invoicingClient.fetchInvoicePdf(order.saleId);

    return {
      buffer: pdf.buffer,
      filename: order.fullInvoiceNumber
        ? `${order.fullInvoiceNumber}.pdf`
        : pdf.filename,
    };
  }

  private async isElectronicInvoicingEnabled(warehouseId: string): Promise<boolean> {
    const warehouse = await this.db.warehouse.findFirst({
      where: { id: warehouseId, isDeleted: false },
      include: { tenant: { include: { setting: true } } },
    });

    if (!warehouse) {
      return false;
    }

    const tenantEnabled = warehouse.tenant.setting?.electronicInvoicingEnabled ?? false;
    return tenantEnabled && warehouse.electronicInvoicingEnabled;
  }

  private async createSaleFromOrder(
    order: {
      id: string;
      orderNumber: string;
      warehouseId: string;
      paymentMethodId: string;
      shippingTotal: Decimal;
      couponDiscount: Decimal;
      total: Decimal;
      billingAddress: unknown;
      items: Array<{
        productSizeId: string;
        colorId: string | null;
        nameSnapshot: string;
        variationLabel: string | null;
        quantity: number;
        unitPrice: Decimal;
        subtotal: Decimal;
      }>;
    },
    createdById: string,
  ): Promise<string> {
    return this.db.$transaction(async (tx) => {
      const lockedOrder = await tx.ecommerceOrder.findFirst({
        where: { id: order.id },
        select: { id: true, saleId: true },
      });

      if (!lockedOrder || lockedOrder.saleId) {
        return lockedOrder?.saleId ?? '';
      }

      const series = await tx.documentSeries.findFirst({
        where: { warehouseId: order.warehouseId, documentType: DOCUMENT_TYPE },
      });

      if (!series) {
        throw new Error(`No hay serie BOLETA configurada para el almacén ${order.warehouseId}.`);
      }

      const correlativo = series.currentNumber;
      const serie = series.serie;
      const fullInvoiceNumber = `${serie}-${String(correlativo).padStart(8, '0')}`;
      const totalAmount = Number(order.total);
      const taxableBase = roundMoney(totalAmount / (1 + IGV_RATE));
      const igvAmount = roundMoney(totalAmount - taxableBase);
      const billing = (order.billingAddress ?? {}) as BillingAddressJson;
      const customerLabel = `${billing.firstName ?? ''} ${billing.lastName ?? ''}`.trim();

      const sale = await tx.sale.create({
        data: {
          code: generateSaleCode(),
          warehouseId: order.warehouseId,
          totalAmount: new Decimal(totalAmount).toDecimalPlaces(2).toNumber(),
          taxableBase: new Decimal(taxableBase).toDecimalPlaces(2).toNumber(),
          igv: new Decimal(igvAmount).toDecimalPlaces(2).toNumber(),
          paymentMethod: mapPaymentMethod(order.paymentMethodId),
          documentType: DOCUMENT_TYPE,
          serie,
          correlativo,
          fullInvoiceNumber,
          sunatStatus: 'PENDING',
          status: 'COMPLETED',
          notes: `Pedido ecommerce ${order.orderNumber}${customerLabel ? ` — ${customerLabel}` : ''}`,
          createdById,
        },
      });

      let remainingDiscount = Number(order.couponDiscount);

      for (const [index, item] of order.items.entries()) {
        const productSize = await tx.productSize.findFirst({
          where: { id: item.productSizeId },
          include: {
            size: { select: { description: true } },
          },
        });
        const color = item.colorId
          ? await tx.color.findFirst({
              where: { id: item.colorId },
              select: { description: true },
            })
          : null;

        let subtotal = Number(item.subtotal);
        if (remainingDiscount > 0) {
          const discountApplied = Math.min(subtotal, remainingDiscount);
          subtotal = roundMoney(subtotal - discountApplied);
          remainingDiscount = roundMoney(remainingDiscount - discountApplied);
        }

        if (index === order.items.length - 1 && remainingDiscount > 0) {
          subtotal = Math.max(0, roundMoney(subtotal - remainingDiscount));
          remainingDiscount = 0;
        }

        await tx.saleDetail.create({
          data: {
            saleId: sale.id,
            productSizeId: item.productSizeId,
            colorId: item.colorId,
            productNameSnapshot: item.nameSnapshot,
            sizeSnapshot: productSize?.size.description ?? item.variationLabel ?? '—',
            colorSnapshot: color?.description ?? item.variationLabel ?? undefined,
            quantity: item.quantity,
            unitPrice: new Decimal(Number(item.unitPrice)).toDecimalPlaces(2).toNumber(),
            subtotal: new Decimal(subtotal).toDecimalPlaces(2).toNumber(),
          },
        });
      }

      const shippingTotal = Number(order.shippingTotal);
      if (shippingTotal > 0 && order.items.length > 0) {
        const anchor = order.items[0]!;
        const productSize = await tx.productSize.findFirst({
          where: { id: anchor.productSizeId },
          include: { size: { select: { description: true } } },
        });

        await tx.saleDetail.create({
          data: {
            saleId: sale.id,
            productSizeId: anchor.productSizeId,
            colorId: anchor.colorId,
            productNameSnapshot: 'Costo de envío',
            sizeSnapshot: productSize?.size.description ?? '—',
            colorSnapshot: undefined,
            quantity: 1,
            unitPrice: new Decimal(shippingTotal).toDecimalPlaces(2).toNumber(),
            subtotal: new Decimal(shippingTotal).toDecimalPlaces(2).toNumber(),
          },
        });
      }

      await tx.salePayment.create({
        data: {
          saleId: sale.id,
          method: mapPaymentMethod(order.paymentMethodId),
          amount: new Decimal(totalAmount).toDecimalPlaces(2).toNumber(),
        },
      });

      await tx.documentSeries.update({
        where: {
          warehouseId_documentType_serie: {
            warehouseId: order.warehouseId,
            documentType: DOCUMENT_TYPE,
            serie,
          },
        },
        data: { currentNumber: { increment: 1 } },
      });

      await tx.ecommerceOrder.update({
        where: { id: order.id },
        data: {
          saleId: sale.id,
          documentType: DOCUMENT_TYPE,
          fullInvoiceNumber,
          sunatStatus: 'PENDING',
          taxAmount: new Decimal(igvAmount).toDecimalPlaces(2).toNumber(),
        },
      });

      return sale.id;
    });
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
      throw new Error('No hay usuario del sistema para registrar ventas ecommerce.');
    }

    return anyUser.id;
  }
}

import {
  BadRequestException,
  ForbiddenException,
  Injectable,
  NotFoundException,
  UnprocessableEntityException,
} from '@nestjs/common';
import { DatabaseService } from '@app/database';
import { getAvailableQuantity } from '@app/common/utils/product-inventory.util';
import type { AuthenticatedUser } from '@app/common/types/authenticated-user.type';
import { SunatService } from '../sunat/sunat.service';
import dayjs from 'dayjs';
import Decimal from 'decimal.js';
import { ExchangeSaleDto } from './dto/exchange-sale.dto';
import { UpdateSaleDto, UpdateSaleItemDto } from './dto/update-sale.dto';

type SaleDetailRecord = {
  id: string;
  productSizeId: string;
  colorId: string | null;
  quantity: number;
  unitPrice: unknown;
  subtotal: unknown;
  productNameSnapshot: string;
  sizeSnapshot: string;
  colorSnapshot: string | null;
};

export interface SalesFilters {
  warehouseId: string;
  dateFrom?: string;
  dateTo?: string;
  documentType?: string;
  status?: string;
  customerId?: string;
  search?: string;
  page?: number;
  perPage?: number;
}

/**
 * SalesService — Equivale a SaleService de Laravel.
 * Solo lecturas y operaciones post-checkout (anular, PDF, exchange).
 * La creación de ventas está en CheckoutService (proceso atómico).
 */
@Injectable()
export class SalesService {
  constructor(
    private readonly db: DatabaseService,
    private readonly sunat: SunatService,
  ) {}

  async findAll(filters: SalesFilters) {
    const { warehouseId, dateFrom, dateTo, documentType, status, search, page = 1, perPage = 20 } = filters;

    const where = {
      warehouseId,
      isDeleted: false,
      ...(documentType && { documentType }),
      ...(status && { status }),
      ...(search && {
        OR: [
          { fullInvoiceNumber: { contains: search } },
          { customer: { name: { contains: search, mode: 'insensitive' as const } } },
        ],
      }),
      ...(dateFrom || dateTo
        ? {
            createdAt: {
              ...(dateFrom && { gte: dayjs(dateFrom).startOf('day').toDate() }),
              ...(dateTo && { lte: dayjs(dateTo).endOf('day').toDate() }),
            },
          }
        : {}),
    };

    const [data, total] = await this.db.$transaction([
      this.db.sale.findMany({
        where,
        skip: (page - 1) * perPage,
        take: perPage,
        orderBy: { createdAt: 'desc' },
        include: {
          customer: { select: { id: true, name: true, documentNumber: true } },
          payments: { select: { method: true, amount: true } },
          _count: { select: { details: true } },
        },
      }),
      this.db.sale.count({ where }),
    ]);

    return { data, meta: { total, page, perPage, lastPage: Math.ceil(total / perPage) } };
  }

  async findById(id: string) {
    const sale = await this.db.sale.findFirst({
      where: { id, isDeleted: false },
      include: {
        customer: true,
        details: true,
        payments: true,
        electronicDocumentLogs: { orderBy: { createdAt: 'desc' }, take: 5 },
      },
    });
    if (!sale) throw new NotFoundException('Venta no encontrada.');
    return sale;
  }

  async delete(id: string, _deletedById: string) {
    const sale = await this.db.sale.findFirst({ where: { id, isDeleted: false } });
    if (!sale) throw new NotFoundException('Venta no encontrada.');

    // Si tiene documento SUNAT aceptado, intentar anulación
    if (sale.sunatStatus === 'ACCEPTED' && sale.documentType !== 'TICKET') {
      await this.sunat.void(id, 'Anulación desde sistema').catch(() => null);
    }

    await this.db.sale.update({
      where: { id },
      data: { isDeleted: true, status: 'CANCELLED' },
    });
  }

  async getMonthlyStats(warehouseId: string, month: string) {
    // month formato: 'YYYY-MM'
    const from = dayjs(month, 'YYYY-MM').startOf('month').toDate();
    const to   = dayjs(month, 'YYYY-MM').endOf('month').toDate();

    const [salesCount, revenue, byPaymentMethod, byDocumentType] = await Promise.all([
      this.db.sale.count({ where: { warehouseId, isDeleted: false, createdAt: { gte: from, lte: to } } }),

      this.db.sale.aggregate({
        where: { warehouseId, isDeleted: false, createdAt: { gte: from, lte: to } },
        _sum: { totalAmount: true },
      }),

      this.db.salePayment.groupBy({
        by: ['method'],
        where: { sale: { warehouseId, isDeleted: false, createdAt: { gte: from, lte: to } } },
        _sum: { amount: true },
      }),

      this.db.sale.groupBy({
        by: ['documentType'],
        where: { warehouseId, isDeleted: false, createdAt: { gte: from, lte: to } },
        _count: { id: true },
        _sum: { totalAmount: true },
      }),
    ]);

    return {
      month,
      totalSales: salesCount,
      totalRevenue: revenue._sum.totalAmount ?? 0,
      byPaymentMethod: Object.fromEntries(
        byPaymentMethod.map((r) => [r.method, r._sum.amount ?? 0]),
      ),
      byDocumentType: Object.fromEntries(
        byDocumentType.map((r) => [
          r.documentType ?? 'TICKET',
          { count: r._count.id, amount: r._sum.totalAmount ?? 0 },
        ]),
      ),
    };
  }

  /** Equivale a SaleService::processExchange() de Laravel. */
  async processExchange(dto: ExchangeSaleDto, user: AuthenticatedUser) {
    return this.db.$transaction(async (tx) => {
      const returnedDetail = await tx.saleDetail.findFirst({
        where: { id: dto.returned_detail_id },
        include: { sale: true },
      });

      if (!returnedDetail?.sale || returnedDetail.sale.isDeleted) {
        throw new NotFoundException('Detalle de venta no encontrado.');
      }

      const originalSale = returnedDetail.sale;
      if (originalSale.warehouseId !== user.warehouseId) {
        throw new ForbiddenException(
          'No puedes procesar un cambio de una venta que pertenece a otro almacén.',
        );
      }

      const qty = returnedDetail.quantity;
      const newPsId = dto.new_item.product_size_id;
      const newColorId = dto.new_item.color_id;
      const newPrice = new Decimal(dto.new_item.final_price).toDecimalPlaces(2).toNumber();

      const productSize = await tx.productSize.findFirst({
        where: { id: newPsId, isDeleted: false },
        include: {
          product: { select: { name: true, warehouseId: true } },
          size: { select: { description: true } },
        },
      });

      if (!productSize?.product || productSize.product.warehouseId !== user.warehouseId) {
        throw new BadRequestException('Talla nueva no encontrada.');
      }

      const balance = await tx.inventoryBalance.findFirst({
        where: {
          warehouseId: originalSale.warehouseId,
          productSizeId: newPsId,
          colorId: newColorId,
        },
      });

      if (!balance || getAvailableQuantity(balance) < qty) {
        throw new UnprocessableEntityException(
          `Stock insuficiente para el producto seleccionado (disponible: ${balance ? getAvailableQuantity(balance) : 0}, requerido: ${qty}).`,
        );
      }

      const color = await tx.color.findFirst({
        where: { id: newColorId },
        select: { description: true },
      });
      const newColorName = color?.description ?? 'Único';

      await tx.saleDetail.create({
        data: {
          saleId: originalSale.id,
          productSizeId: newPsId,
          colorId: newColorId,
          productNameSnapshot: productSize.product.name,
          sizeSnapshot: productSize.size?.description ?? '',
          colorSnapshot: newColorName,
          quantity: qty,
          unitPrice: newPrice,
          subtotal: new Decimal(newPrice).mul(qty).toDecimalPlaces(2).toNumber(),
        },
      });

      await tx.inventoryBalance.update({
        where: {
          warehouseId_productSizeId_colorId: {
            warehouseId: originalSale.warehouseId,
            productSizeId: newPsId,
            colorId: newColorId,
          },
        },
        data: { quantity: { decrement: qty } },
      });

      await tx.inventoryMovement.create({
        data: {
          warehouseId: originalSale.warehouseId,
          productSizeId: newPsId,
          colorId: newColorId,
          direction: 'OUT',
          quantity: qty,
          movementType: 'SALE',
          referenceId: originalSale.id,
          referenceType: 'SaleExchange',
          balanceAfter: Math.max(0, balance.quantity - qty),
          occurredAt: originalSale.createdAt,
          createdById: user.id,
        },
      });

      await tx.saleDetail.delete({ where: { id: returnedDetail.id } });

      const remainingDetails = await tx.saleDetail.findMany({
        where: { saleId: originalSale.id },
        select: { subtotal: true },
      });
      const newTotal = remainingDetails.reduce(
        (sum, detail) => sum.plus(detail.subtotal),
        new Decimal(0),
      ).toDecimalPlaces(2).toNumber();

      const paymentMethod = dto.payment_method ?? 'CASH';

      await tx.salePayment.deleteMany({ where: { saleId: originalSale.id } });
      await tx.salePayment.create({
        data: {
          saleId: originalSale.id,
          method: paymentMethod,
          amount: newTotal,
          reference: 'CAMBIO MERCADERÍA',
        },
      });

      const note = `CAMBIO: Entregó ${returnedDetail.productNameSnapshot}, Llevó ${productSize.product.name} (S/ ${newPrice}).`;
      const mergedNotes = `${originalSale.notes ?? ''} | ${note}`.slice(-250);

      await tx.sale.update({
        where: { id: originalSale.id },
        data: {
          totalAmount: newTotal,
          paymentMethod,
          notes: mergedNotes,
        },
      });

      if (dto.difference_amount > 0) {
        await tx.cashMovement.create({
          data: {
            warehouseId: originalSale.warehouseId,
            type: 'INCOME',
            amount: new Decimal(dto.difference_amount).toDecimalPlaces(2).toNumber(),
            category: 'STORE',
            paymentMethod,
            description: `Cambio Mercadería #${originalSale.code ?? originalSale.id} (Diferencia)`,
            date: new Date(),
            accountingMonth: dayjs().format('YYYY-MM'),
            createdById: user.id,
          },
        });
      }

      return {
        success: true,
        message: 'Cambio registrado correctamente',
      };
    });
  }

  /** Equivale a SaleService::update() de Laravel. */
  async update(id: string, dto: UpdateSaleDto, user: AuthenticatedUser) {
    return this.db.$transaction(async (tx) => {
      const sale = await tx.sale.findFirst({
        where: { id, isDeleted: false, warehouseId: user.warehouseId },
        include: { details: true },
      });

      if (!sale) {
        throw new NotFoundException('Venta no encontrada.');
      }

      if (['CANCELLED', 'CANCELED'].includes(sale.status.toUpperCase())) {
        throw new BadRequestException('No se puede editar una venta anulada.');
      }

      if (dto.creationTime) {
        const parsed = new Date(dto.creationTime);
        if (!Number.isNaN(parsed.getTime())) {
          await tx.sale.update({ where: { id }, data: { createdAt: parsed } });
        }
      }

      if (dto.items?.length) {
        const submittedIds = dto.items
          .map((item) => item.id)
          .filter((detailId): detailId is string => !!detailId);

        for (const detail of sale.details) {
          if (!submittedIds.includes(detail.id)) {
            await this.restoreDetailStock(tx, sale.warehouseId, detail, sale.id, user.id);
            await tx.saleDetail.delete({ where: { id: detail.id } });
          }
        }

        for (const itemData of dto.items) {
          if (!itemData.id) {
            await this.addDetailFromUpdate(tx, sale.id, sale.warehouseId, itemData, user.id);
            continue;
          }

          const detail = sale.details.find((row) => row.id === itemData.id);
          if (!detail) continue;

          await this.updateExistingDetail(
            tx,
            sale.id,
            sale.warehouseId,
            detail as SaleDetailRecord,
            itemData,
            user.id,
          );
        }

        const remainingDetails = await tx.saleDetail.findMany({
          where: { saleId: id },
          select: { subtotal: true },
        });
        const newTotal = remainingDetails
          .reduce((sum, row) => sum.plus(row.subtotal), new Decimal(0))
          .toDecimalPlaces(2)
          .toNumber();

        await tx.sale.update({ where: { id }, data: { totalAmount: newTotal } });
      }

      if (dto.payments?.length) {
        const current = await tx.sale.findUnique({ where: { id }, select: { totalAmount: true } });
        const totalAmount = Number(current?.totalAmount ?? 0);
        const paymentsTotal = dto.payments
          .reduce((sum, payment) => sum.plus(payment.amount), new Decimal(0))
          .toDecimalPlaces(2)
          .toNumber();

        if (Math.abs(paymentsTotal - totalAmount) > 0.01) {
          throw new BadRequestException('Los pagos no coinciden con el total de la venta.');
        }

        await tx.salePayment.deleteMany({ where: { saleId: id } });
        await tx.salePayment.createMany({
          data: dto.payments.map((payment) => ({
            saleId: id,
            method: payment.method,
            amount: new Decimal(payment.amount).toDecimalPlaces(2).toNumber(),
            reference: payment.reference ?? 'AJUSTE EN EDICIÓN',
          })),
        });

        const uniqueMethods = [...new Set(dto.payments.map((payment) => payment.method))];
        await tx.sale.update({
          where: { id },
          data: {
            paymentMethod: uniqueMethods.length > 1 ? 'MIXED' : uniqueMethods[0],
          },
        });
      }

      return { message: 'Sale updated.' };
    });
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private async resolveColorId(tx: any, colorId?: string | null): Promise<string> {
    if (colorId) return colorId;
    return this.getNoColorId(tx);
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private async getNoColorId(tx: any): Promise<string> {
    const color = await tx.color.findFirst({ where: { description: 'Sin color' } });
    if (!color) {
      throw new BadRequestException('Color "Sin color" no configurado en el catálogo.');
    }
    return color.id;
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private async buildDetailSnapshot(tx: any, productSizeId: string, colorId: string) {
    const productSize = await tx.productSize.findFirst({
      where: { id: productSizeId, isDeleted: false },
      include: {
        product: { select: { name: true } },
        size: { select: { description: true } },
      },
    });
    if (!productSize?.product) {
      throw new BadRequestException('Talla de producto no encontrada.');
    }

    const color = await tx.color.findFirst({
      where: { id: colorId },
      select: { description: true },
    });

    return {
      productNameSnapshot: productSize.product.name,
      sizeSnapshot: productSize.size?.description ?? '',
      colorSnapshot: color?.description ?? null,
    };
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private async deductStock(
    tx: any,
    warehouseId: string,
    productSizeId: string,
    colorId: string | null | undefined,
    quantity: number,
    saleId: string,
    userId: string,
  ) {
    if (quantity <= 0) return;

    const resolvedColorId = await this.resolveColorId(tx, colorId);
    const balance = await tx.inventoryBalance.findFirst({
      where: { warehouseId, productSizeId, colorId: resolvedColorId },
    });

    if (!balance || getAvailableQuantity(balance) < quantity) {
      throw new UnprocessableEntityException(
        `Stock insuficiente (disponible: ${balance ? getAvailableQuantity(balance) : 0}, requerido: ${quantity}).`,
      );
    }

    await tx.inventoryBalance.update({
      where: {
        warehouseId_productSizeId_colorId: {
          warehouseId,
          productSizeId,
          colorId: resolvedColorId,
        },
      },
      data: { quantity: { decrement: quantity } },
    });

    await tx.inventoryMovement.create({
      data: {
        warehouseId,
        productSizeId,
        colorId: resolvedColorId,
        direction: 'OUT',
        quantity,
        movementType: 'SALE',
        referenceId: saleId,
        referenceType: 'SaleUpdate',
        balanceAfter: Math.max(0, balance.quantity - quantity),
        occurredAt: new Date(),
        createdById: userId,
      },
    });
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private async restoreStock(
    tx: any,
    warehouseId: string,
    productSizeId: string,
    colorId: string | null | undefined,
    quantity: number,
    saleId: string,
    userId: string,
  ) {
    if (quantity <= 0) return;

    const resolvedColorId = await this.resolveColorId(tx, colorId);
    const balance = await tx.inventoryBalance.findFirst({
      where: { warehouseId, productSizeId, colorId: resolvedColorId },
    });

    if (balance) {
      await tx.inventoryBalance.update({
        where: {
          warehouseId_productSizeId_colorId: {
            warehouseId,
            productSizeId,
            colorId: resolvedColorId,
          },
        },
        data: { quantity: { increment: quantity } },
      });
    } else {
      await tx.inventoryBalance.create({
        data: {
          warehouseId,
          productSizeId,
          colorId: resolvedColorId,
          quantity,
        },
      });
    }

    await tx.inventoryMovement.create({
      data: {
        warehouseId,
        productSizeId,
        colorId: resolvedColorId,
        direction: 'IN',
        quantity,
        movementType: 'SALE',
        referenceId: saleId,
        referenceType: 'SaleUpdate',
        balanceAfter: (balance?.quantity ?? 0) + quantity,
        occurredAt: new Date(),
        createdById: userId,
      },
    });
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private async restoreDetailStock(
    tx: any,
    warehouseId: string,
    detail: SaleDetailRecord,
    saleId: string,
    userId: string,
  ) {
    await this.restoreStock(
      tx,
      warehouseId,
      detail.productSizeId,
      detail.colorId,
      detail.quantity,
      saleId,
      userId,
    );
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private async addDetailFromUpdate(
    tx: any,
    saleId: string,
    warehouseId: string,
    itemData: UpdateSaleItemDto,
    userId: string,
  ) {
    if (!itemData.product_size_id) {
      throw new BadRequestException('Debe seleccionar un producto para los ítems nuevos.');
    }

    const colorId = await this.resolveColorId(tx, itemData.color_id);
    const snapshot = await this.buildDetailSnapshot(tx, itemData.product_size_id, colorId);
    const unitPrice = new Decimal(itemData.unit_price).toDecimalPlaces(2).toNumber();
    const subtotal = new Decimal(unitPrice).mul(itemData.quantity).toDecimalPlaces(2).toNumber();

    await this.deductStock(
      tx,
      warehouseId,
      itemData.product_size_id,
      colorId,
      itemData.quantity,
      saleId,
      userId,
    );

    await tx.saleDetail.create({
      data: {
        saleId,
        productSizeId: itemData.product_size_id,
        colorId,
        ...snapshot,
        quantity: itemData.quantity,
        unitPrice,
        subtotal,
      },
    });
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private async updateExistingDetail(
    tx: any,
    saleId: string,
    warehouseId: string,
    detail: SaleDetailRecord,
    itemData: UpdateSaleItemDto,
    userId: string,
  ) {
    const newQty = itemData.quantity;
    const newPrice = new Decimal(itemData.unit_price).toDecimalPlaces(2).toNumber();
    const newPsId = itemData.product_size_id ?? detail.productSizeId;
    const newColorId = await this.resolveColorId(tx, itemData.color_id ?? detail.colorId);
    const currentColorId = await this.resolveColorId(tx, detail.colorId);

    const isExchange =
      newPsId !== detail.productSizeId || newColorId !== currentColorId;

    if (isExchange) {
      await this.restoreStock(
        tx,
        warehouseId,
        detail.productSizeId,
        detail.colorId,
        detail.quantity,
        saleId,
        userId,
      );
      await this.deductStock(tx, warehouseId, newPsId, newColorId, newQty, saleId, userId);
      const snapshot = await this.buildDetailSnapshot(tx, newPsId, newColorId);

      await tx.saleDetail.update({
        where: { id: detail.id },
        data: {
          productSizeId: newPsId,
          colorId: newColorId,
          ...snapshot,
          quantity: newQty,
          unitPrice: newPrice,
          subtotal: new Decimal(newPrice).mul(newQty).toDecimalPlaces(2).toNumber(),
        },
      });
      return;
    }

    const diff = newQty - detail.quantity;
    if (diff > 0) {
      await this.deductStock(
        tx,
        warehouseId,
        detail.productSizeId,
        detail.colorId,
        diff,
        saleId,
        userId,
      );
    } else if (diff < 0) {
      await this.restoreStock(
        tx,
        warehouseId,
        detail.productSizeId,
        detail.colorId,
        Math.abs(diff),
        saleId,
        userId,
      );
    }

    await tx.saleDetail.update({
      where: { id: detail.id },
      data: {
        quantity: newQty,
        unitPrice: newPrice,
        subtotal: new Decimal(newPrice).mul(newQty).toDecimalPlaces(2).toNumber(),
      },
    });
  }

  /** Equivale a SaleController@exchangeRate — tipo de cambio del día */
  async getExchangeRate(): Promise<{ compra: number; venta: number; fecha: string }> {
    try {
      const response = await fetch(
        'https://api.apis.net.pe/v1/tipo-cambio-sunat',
        { headers: { Accept: 'application/json' } },
      );
      if (!response.ok) throw new Error('API no disponible');
      return response.json() as Promise<{ compra: number; venta: number; fecha: string }>;
    } catch {
      // Fallback: retorna valores conservadores para no bloquear el POS
      return { compra: 3.70, venta: 3.73, fecha: dayjs().format('YYYY-MM-DD') };
    }
  }
}

import {
  Injectable,
  BadRequestException,
  UnprocessableEntityException,
} from '@nestjs/common';
import { DatabaseService } from '@app/database';
import { getAvailableQuantity } from '@app/common/utils/product-inventory.util';
import { CheckoutEventPublisher } from '@app/event-bus';
import { SunatService } from '../sunat/sunat.service';
import { DocumentSeriesService } from '../sunat/document-series.service';
import { FiscalConfigService } from '../fiscal/fiscal-config.service';
import { CheckoutDto, DocumentType } from './dto/checkout.dto';
import Decimal from 'decimal.js';
import { randomBytes } from 'crypto';

const IGV_RATE = 0.18;

function generateSaleCode(): string {
  return `V-${randomBytes(10).toString('hex').toUpperCase()}`;
}

export interface CheckoutResult {
  sale: { id: string; code: string; totalAmount: number };
  ticketUrl: string;
  sunat?: { status: string; invoiceNumber?: string };
}

/**
 * CheckoutService — Equivale a la lógica de PosController@checkout de Laravel.
 *
 * FLUJO ATÓMICO (igual que el original):
 *   1. Validar stock disponible para cada ítem
 *   2. Crear Sale + SaleDetails + SalePayments en una transacción
 *   3. Decrementar InventoryBalance dentro de la misma transacción
 *   4. Si documentType ≠ TICKET → llamar a SunatService (sidecar Laravel)
 *   5. Retornar la venta creada + URL del ticket
 *
 * DIFERENCIA vs Laravel:
 * - El descuento de inventario se hace directo en DB (misma TX).
 * - Efectos secundarios (alertas stock, reportes) se publican vía Redis Pub/Sub
 *   (`nm:events:checkout`) y los consume report-service de forma async.
 */
@Injectable()
export class CheckoutService {
  constructor(
    private readonly db: DatabaseService,
    private readonly sunat: SunatService,
    private readonly docSeries: DocumentSeriesService,
    private readonly fiscalConfig: FiscalConfigService,
    private readonly checkoutEvents: CheckoutEventPublisher,
  ) {}

  async process(dto: CheckoutDto, createdById: string): Promise<CheckoutResult> {
    const documentType = dto.documentType ?? DocumentType.TICKET;

    if (documentType !== DocumentType.TICKET) {
      const config = await this.fiscalConfig.getForWarehouse(dto.warehouseId);
      if (!config.electronicInvoicingEnabled) {
        throw new BadRequestException(
          'La facturación electrónica no está habilitada para esta tienda.',
        );
      }

      if (documentType === DocumentType.FACTURA) {
        if (!dto.customerId) {
          throw new BadRequestException(
            'Para emitir una Factura debe registrar un cliente con RUC.',
          );
        }

        const customer = await this.db.customer.findFirst({
          where: { id: dto.customerId },
        });
        const docType = customer?.documentType?.toUpperCase() ?? '';
        const docNumber = customer?.documentNumber?.trim() ?? '';
        if (docType !== 'RUC' || docNumber.length !== 11) {
          throw new BadRequestException(
            'Para emitir una Factura el cliente debe tener un RUC válido de 11 dígitos.',
          );
        }
      }
    }

    // ── 1. Validar totales de pagos ──────────────────────────────────────────
    const paymentsTotal = dto.payments.reduce((s, p) => s + p.amount, 0);
    const itemsTotal = dto.items.reduce((s, i) => s + i.unitPrice * i.quantity, 0);

    if (Math.abs(paymentsTotal - itemsTotal) > 0.01) {
      throw new BadRequestException(
        `El total de pagos (${paymentsTotal}) no coincide con el total de ítems (${itemsTotal}).`,
      );
    }

    // ── 2. Validar stock ─────────────────────────────────────────────────────
    await this.validateStock(dto);

    // ── 3. Calcular IGV (solo para BOLETA/FACTURA) ───────────────────────────
    const { taxableBase, igv } = this.calculateTax(
      itemsTotal,
      dto.documentType ?? DocumentType.TICKET,
    );

    // ── 4. Resolver serie+correlativo si aplica ───────────────────────────────
    let serie: string | null = null;
    let correlativo: number | null = null;
    let fullInvoiceNumber: string | null = null;

    if (dto.documentType && dto.documentType !== DocumentType.TICKET) {
      const series = await this.docSeries.getNextNumber(
        dto.warehouseId,
        dto.documentType,
      );
      serie = series.serie;
      correlativo = series.nextNumber;
      fullInvoiceNumber = `${serie}-${String(correlativo).padStart(8, '0')}`;
    }

    // ── 5. Transacción: Sale + Details + Payments + Stock ────────────────────
    const sale = await this.db.$transaction(async (tx) => {
      const created = await tx.sale.create({
        data: {
          code: generateSaleCode(),
          warehouseId: dto.warehouseId,
          customerId: dto.customerId,
          totalAmount: new Decimal(itemsTotal).toDecimalPlaces(2).toNumber(),
          taxableBase: taxableBase ? new Decimal(taxableBase).toDecimalPlaces(2).toNumber() : null,
          igv: igv ? new Decimal(igv).toDecimalPlaces(2).toNumber() : null,
          paymentMethod: dto.payments.length === 1
            ? dto.payments[0].method
            : 'MIXED',
          documentType: dto.documentType ?? DocumentType.TICKET,
          serie,
          correlativo,
          fullInvoiceNumber,
          sunatStatus:
            dto.documentType && dto.documentType !== DocumentType.TICKET
              ? 'PENDING'
              : null,
          status: 'COMPLETED',
          notes: dto.notes,
          createdById,
        },
      });

      // Detalles + descuento de stock en la misma TX
      for (const item of dto.items) {
        const ps = await tx.productSize.findFirst({
          where: { id: item.productSizeId },
          include: {
            product: { select: { name: true } },
            size: { select: { description: true } },
          },
        });
        const color = item.colorId
          ? await tx.color.findFirst({ where: { id: item.colorId }, select: { description: true } })
          : null;

        await tx.saleDetail.create({
          data: {
            saleId: created.id,
            productSizeId: item.productSizeId,
            colorId: item.colorId,
            productNameSnapshot: ps?.product.name ?? 'Producto',
            sizeSnapshot: ps?.size.description ?? '',
            colorSnapshot: color?.description,
            quantity: item.quantity,
            unitPrice: new Decimal(item.unitPrice).toDecimalPlaces(2).toNumber(),
            subtotal: new Decimal(item.unitPrice * item.quantity).toDecimalPlaces(2).toNumber(),
          },
        });

        // Descuento de inventario (equivale a InventoryMovementService en Laravel)
        if (item.colorId) {
          await tx.inventoryBalance.update({
            where: {
              warehouseId_productSizeId_colorId: {
                warehouseId: dto.warehouseId,
                productSizeId: item.productSizeId,
                colorId: item.colorId,
              },
            },
            data: { quantity: { decrement: item.quantity } },
          });
        }

        await tx.inventoryMovement.create({
          data: {
            warehouseId: dto.warehouseId,
            productSizeId: item.productSizeId,
            colorId: item.colorId ?? (await this.getNoColorId(tx)),
            direction: 'OUT',
            quantity: item.quantity,
            movementType: 'SALE',
            referenceId: created.id,
            referenceType: 'Sale',
            balanceAfter: 0, // actualizamos abajo en producción con select after update
            occurredAt: new Date(),
            createdById,
          },
        });
      }

      // Pagos
      await tx.salePayment.createMany({
        data: dto.payments.map((p) => ({
          saleId: created.id,
          method: p.method,
          amount: new Decimal(p.amount).toDecimalPlaces(2).toNumber(),
          reference: p.reference,
        })),
      });

      // Actualizar correlativo en serie de documentos
      if (serie && correlativo) {
        await this.docSeries.incrementNumber(tx, dto.warehouseId, dto.documentType!, serie);
      }

      return created;
    });

    // ── 6. Emitir documento SUNAT (fuera de TX — si falla no revierte la venta) ─
    let sunatResult: CheckoutResult['sunat'];
    if (dto.documentType && dto.documentType !== DocumentType.TICKET) {
      sunatResult = await this.sunat.emit(sale.id, dto.documentType).catch((err) => {
        // Log pero no falla el checkout (la venta ya está registrada)
        console.error(`[SUNAT] Emisión fallida para venta ${sale.id}:`, err?.message);
        return { status: 'PENDING_EMISSION' };
      });
    }

    const soldVariants = dto.items
      .filter((item) => item.colorId)
      .map((item) => ({
        productSizeId: item.productSizeId,
        colorId: item.colorId!,
      }));

    void this.checkoutEvents
      .publishPosCheckoutCompleted({
        warehouseId: dto.warehouseId,
        referenceId: sale.id,
        referenceLabel: sale.code ?? sale.fullInvoiceNumber ?? sale.id,
        totalAmount: Number(sale.totalAmount),
        variants: soldVariants,
      })
      .catch(() => undefined);

    return {
      sale: {
        id: sale.id,
        code: sale.code ?? sale.fullInvoiceNumber ?? `V-${sale.id.replace(/-/g, '').slice(0, 12).toUpperCase()}`,
        totalAmount: Number(sale.totalAmount),
      },
      ticketUrl: `/v1/tickets/${sale.id}`,
      ...(sunatResult && { sunat: sunatResult }),
    };
  }

  // ── Helpers privados ──────────────────────────────────────────────────────

  private async validateStock(dto: CheckoutDto) {
    for (const item of dto.items) {
      if (!item.colorId) continue;

      const balance = await this.db.inventoryBalance.findFirst({
        where: {
          warehouseId: dto.warehouseId,
          productSizeId: item.productSizeId,
          colorId: item.colorId,
        },
      });

      if (!balance || getAvailableQuantity(balance) < item.quantity) {
        throw new UnprocessableEntityException(
          `Stock insuficiente para el ítem ${item.productSizeId} ` +
            `(disponible: ${balance ? getAvailableQuantity(balance) : 0}, requerido: ${item.quantity}).`,
        );
      }
    }
  }

  private calculateTax(total: number, docType: DocumentType) {
    if (docType === DocumentType.TICKET) return { taxableBase: null, igv: null };
    const taxableBase = total / (1 + IGV_RATE);
    const igv = total - taxableBase;
    return { taxableBase, igv };
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private async getNoColorId(tx: any): Promise<string> {
    const c = await tx.color.findFirst({ where: { description: 'Sin color' } });
    return c?.id ?? 'NO_COLOR';
  }
}

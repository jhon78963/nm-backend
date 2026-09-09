import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { DatabaseService } from '@app/database';
import { EcommerceMailTemplate, MailClientService } from '@app/mail-client';

import type {
  LowStockAlertContext,
  LowStockAlertItem,
  LowStockVariantRef,
} from './low-stock-alerts.types';

@Injectable()
export class LowStockAlertsService {
  private readonly logger = new Logger(LowStockAlertsService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly mailClient: MailClientService,
    private readonly config: ConfigService,
  ) {}

  async runDailyDigest(): Promise<void> {
    if (!this.isEnabled()) {
      return;
    }

    const warehouses = await this.db.warehouse.findMany({
      where: { isDeleted: false },
      select: { id: true, name: true },
    });

    for (const warehouse of warehouses) {
      await this.sendWarehouseAlert(warehouse.id, { source: 'cron' }).catch((error) => {
        this.logger.warn(
          `No se pudo enviar digest de stock bajo para ${warehouse.name}`,
          error,
        );
      });
    }
  }

  async checkAfterSale(
    warehouseId: string,
    variants: LowStockVariantRef[],
    context: Omit<LowStockAlertContext, 'source'> & { source: 'pos_sale' | 'ecommerce_order' },
  ): Promise<void> {
    if (!this.isEnabled() || variants.length === 0) {
      return;
    }

    const uniqueVariants = this.dedupeVariants(variants);
    await this.sendWarehouseAlert(warehouseId, context, uniqueVariants).catch((error) => {
      this.logger.warn(
        `No se pudo evaluar alertas de stock bajo tras venta (${context.referenceLabel ?? warehouseId})`,
        error,
      );
    });
  }

  private async sendWarehouseAlert(
    warehouseId: string,
    context: LowStockAlertContext,
    variants?: LowStockVariantRef[],
  ): Promise<void> {
    const threshold = this.getThreshold();
    const candidates = await this.findLowStockItems(warehouseId, threshold, variants);
    if (candidates.length === 0) {
      return;
    }

    const items = await this.filterRecentlyNotified(candidates);
    if (items.length === 0) {
      return;
    }

    const recipient = this.getRecipientEmail();
    if (!recipient) {
      this.logger.warn('MAIL_LOW_STOCK_ALERT_EMAIL / MAIL_SUPPORT_EMAIL no configurado');
      return;
    }

    const warehouseName = items[0]?.warehouseName ?? 'Almacén';
    const sourceLabel =
      context.source === 'cron'
        ? 'Resumen diario'
        : context.source === 'pos_sale'
          ? 'Venta POS'
          : 'Pedido web';

    await this.mailClient.sendEcommerceMail({
      template: EcommerceMailTemplate.INVENTORY_LOW_STOCK,
      to: recipient,
      data: {
        warehouseName,
        threshold,
        source: context.source,
        sourceLabel,
        referenceLabel: context.referenceLabel,
        items: items.map((item) => ({
          productName: item.productName,
          sizeLabel: item.sizeLabel,
          colorLabel: item.colorLabel,
          quantity: item.quantity,
        })),
        erpInventoryUrl: this.getErpInventoryUrl(),
        storeUrl: this.getStoreUrl(),
      },
    });

    await this.recordNotifications(items);
    this.logger.log(
      `Alerta stock bajo enviada (${sourceLabel}) — ${warehouseName}: ${items.length} variante(s)`,
    );
  }

  private async findLowStockItems(
    warehouseId: string,
    threshold: number,
    variants?: LowStockVariantRef[],
  ): Promise<LowStockAlertItem[]> {
    const uniqueVariants = variants ? this.dedupeVariants(variants) : undefined;

    const balances = await this.db.inventoryBalance.findMany({
      where: {
        warehouseId,
        quantity: { gt: 0, lt: threshold },
        ...(uniqueVariants?.length
          ? {
              OR: uniqueVariants.map((variant) => ({
                productSizeId: variant.productSizeId,
                colorId: variant.colorId,
              })),
            }
          : {}),
      },
      include: {
        warehouse: { select: { name: true } },
        productSize: {
          include: {
            product: { select: { name: true } },
            size: { select: { description: true } },
          },
        },
        color: { select: { description: true } },
      },
      orderBy: [{ quantity: 'asc' }, { productSizeId: 'asc' }],
    });

    return balances.map((balance) => ({
      warehouseId: balance.warehouseId,
      warehouseName: balance.warehouse.name,
      productSizeId: balance.productSizeId,
      colorId: balance.colorId,
      productName: balance.productSize.product.name,
      sizeLabel: balance.productSize.size.description,
      colorLabel: balance.color.description,
      quantity: balance.quantity,
    }));
  }

  private async filterRecentlyNotified(items: LowStockAlertItem[]): Promise<LowStockAlertItem[]> {
    const cooldownHours = this.getCooldownHours();
    const cutoff = new Date(Date.now() - cooldownHours * 60 * 60 * 1000);

    const existing = await this.db.inventoryLowStockAlert.findMany({
      where: {
        OR: items.map((item) => ({
          warehouseId: item.warehouseId,
          productSizeId: item.productSizeId,
          colorId: item.colorId,
        })),
      },
    });

    const recentKeys = new Set(
      existing
        .filter((alert) => alert.lastNotifiedAt >= cutoff)
        .map((alert) => this.variantKey(alert.warehouseId, alert.productSizeId, alert.colorId)),
    );

    return items.filter(
      (item) =>
        !recentKeys.has(this.variantKey(item.warehouseId, item.productSizeId, item.colorId)),
    );
  }

  private async recordNotifications(items: LowStockAlertItem[]): Promise<void> {
    const now = new Date();

    await Promise.all(
      items.map((item) =>
        this.db.inventoryLowStockAlert.upsert({
          where: {
            warehouseId_productSizeId_colorId: {
              warehouseId: item.warehouseId,
              productSizeId: item.productSizeId,
              colorId: item.colorId,
            },
          },
          create: {
            warehouseId: item.warehouseId,
            productSizeId: item.productSizeId,
            colorId: item.colorId,
            lastNotifiedAt: now,
            lastQuantity: item.quantity,
          },
          update: {
            lastNotifiedAt: now,
            lastQuantity: item.quantity,
          },
        }),
      ),
    );
  }

  private dedupeVariants(variants: LowStockVariantRef[]): LowStockVariantRef[] {
    const seen = new Set<string>();
    return variants.filter((variant) => {
      const key = `${variant.productSizeId}:${variant.colorId}`;
      if (seen.has(key)) {
        return false;
      }
      seen.add(key);
      return true;
    });
  }

  private variantKey(warehouseId: string, productSizeId: string, colorId: string): string {
    return `${warehouseId}:${productSizeId}:${colorId}`;
  }

  private isEnabled(): boolean {
    return this.config.get<string>('LOW_STOCK_ALERTS_ENABLED', 'true') !== 'false';
  }

  private getThreshold(): number {
    const parsed = Number(this.config.get<string>('LOW_STOCK_THRESHOLD', '5'));
    return Number.isFinite(parsed) && parsed > 0 ? parsed : 5;
  }

  private getCooldownHours(): number {
    const parsed = Number(this.config.get<string>('LOW_STOCK_ALERT_COOLDOWN_HOURS', '24'));
    return Number.isFinite(parsed) && parsed > 0 ? parsed : 24;
  }

  private getRecipientEmail(): string | null {
    const email =
      this.config.get<string>('MAIL_LOW_STOCK_ALERT_EMAIL') ??
      this.config.get<string>('MAIL_SUPPORT_EMAIL') ??
      this.config.get<string>('MAIL_FROM_EMAIL');

    return email?.trim() || null;
  }

  private getErpInventoryUrl(): string {
    const base = (
      this.config.get<string>('ERP_PANEL_URL') ??
      this.config.get<string>('FRONTEND_URL', 'http://localhost:4200')
    ).replace(/\/$/, '');

    return `${base}/inventarios/productos`;
  }

  private getStoreUrl(): string {
    return this.config.get<string>(
      'ECOMMERCE_STORE_URL',
      this.config.get<string>('FRONTEND_URL', 'http://localhost:3001'),
    );
  }
}

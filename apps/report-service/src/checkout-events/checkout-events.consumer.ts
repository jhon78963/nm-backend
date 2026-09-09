import { Injectable, Logger } from '@nestjs/common';

import { LowStockAlertsService } from '@app/common/inventory/low-stock-alerts.service';
import {
  CHECKOUT_EVENT_TYPES,
  EventBusSubscriber,
  type CheckoutSideEffectEventData,
} from '@app/event-bus';

@Injectable()
export class CheckoutEventsConsumer {
  private readonly logger = new Logger(CheckoutEventsConsumer.name);

  constructor(
    subscriber: EventBusSubscriber,
    private readonly lowStockAlerts: LowStockAlertsService,
  ) {
    subscriber.registerHandler(CHECKOUT_EVENT_TYPES.POS_COMPLETED, (envelope) =>
      this.handleInventorySideEffects(
        envelope.data as CheckoutSideEffectEventData,
        'pos_sale',
      ),
    );
    subscriber.registerHandler(CHECKOUT_EVENT_TYPES.ECOMMERCE_ORDER_PAID, (envelope) =>
      this.handleInventorySideEffects(
        envelope.data as CheckoutSideEffectEventData,
        'ecommerce_order',
      ),
    );
  }

  private async handleInventorySideEffects(
    data: CheckoutSideEffectEventData,
    source: 'pos_sale' | 'ecommerce_order',
  ): Promise<void> {
    if (!data.warehouseId || data.variants.length === 0) {
      return;
    }

    await this.lowStockAlerts.checkAfterSale(data.warehouseId, data.variants, {
      source,
      referenceLabel: data.referenceLabel,
    });

    this.logger.debug(
      `Procesado evento checkout ${source} — ${data.referenceLabel} (${data.variants.length} variantes)`,
    );
  }
}

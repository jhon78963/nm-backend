import { Injectable } from '@nestjs/common';

import { CHECKOUT_EVENT_TYPES } from './checkout-event.constants';
import type { CheckoutSideEffectEventData } from './checkout-event.types';
import { EventBusPublisher } from '../event-bus.publisher';

@Injectable()
export class CheckoutEventPublisher {
  constructor(private readonly publisher: EventBusPublisher) {}

  publishPosCheckoutCompleted(data: CheckoutSideEffectEventData): Promise<void> {
    return this.publisher.publishCheckoutEvent(
      CHECKOUT_EVENT_TYPES.POS_COMPLETED,
      'pos-service',
      data,
    );
  }

  publishEcommerceOrderPaid(data: CheckoutSideEffectEventData): Promise<void> {
    return this.publisher.publishCheckoutEvent(
      CHECKOUT_EVENT_TYPES.ECOMMERCE_ORDER_PAID,
      'ecommerce-service',
      data,
    );
  }
}

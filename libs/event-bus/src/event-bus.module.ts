import { DynamicModule, Module } from '@nestjs/common';

import { CheckoutEventPublisher } from './checkout/checkout-event.publisher';
import { EventBusPublisher } from './event-bus.publisher';
import { EventBusSubscriber } from './event-bus.subscriber';

@Module({})
export class EventBusModule {
  /** Publica eventos post-checkout vía Redis Pub/Sub. */
  static forPublisher(): DynamicModule {
    return {
      module: EventBusModule,
      providers: [EventBusPublisher, CheckoutEventPublisher],
      exports: [CheckoutEventPublisher],
    };
  }

  /** Consume eventos checkout (reportes, alertas, mail async). */
  static forConsumer(): DynamicModule {
    return {
      module: EventBusModule,
      global: true,
      providers: [EventBusSubscriber],
      exports: [EventBusSubscriber],
    };
  }
}

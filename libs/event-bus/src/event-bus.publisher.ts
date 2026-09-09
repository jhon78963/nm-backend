import { Injectable, Logger, OnModuleDestroy, OnModuleInit } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { randomUUID } from 'crypto';
import type Redis from 'ioredis';

import { CHECKOUT_EVENTS_CHANNEL } from './checkout/checkout-event.constants';
import type { CheckoutEventType } from './checkout/checkout-event.constants';
import type { CheckoutEventEnvelope, CheckoutSideEffectEventData, NmEventEnvelope } from './checkout/checkout-event.types';
import { isEventBusEnabled, resolveEventBusRedisUrl } from './event-bus.config';

@Injectable()
export class EventBusPublisher implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(EventBusPublisher.name);
  private redis: Redis | null = null;
  private readonly enabled: boolean;

  constructor(private readonly config: ConfigService) {
    this.enabled = isEventBusEnabled(config);
  }

  async onModuleInit(): Promise<void> {
    if (!this.enabled) {
      this.logger.log('EVENT_BUS_ENABLED=false — publisher desactivado');
      return;
    }

    try {
      const { default: RedisClient } = await import('ioredis');
      this.redis = new RedisClient(resolveEventBusRedisUrl(this.config), {
        connectTimeout: Number(this.config.get<string>('REDIS_CONNECT_TIMEOUT_MS', '5000')),
        maxRetriesPerRequest: 1,
        lazyConnect: true,
      });
      await this.redis.connect();
      this.logger.log('Event bus publisher conectado a Redis');
    } catch (error) {
      this.logger.warn(
        `Event bus publisher no disponible: ${(error as Error).message}`,
      );
      this.redis = null;
    }
  }

  async publish<TType extends string, TData>(
    channel: string,
    envelope: NmEventEnvelope<TType, TData>,
  ): Promise<void> {
    if (!this.enabled || !this.redis) {
      return;
    }

    try {
      await this.redis.publish(channel, JSON.stringify(envelope));
    } catch (error) {
      this.logger.warn(
        `No se pudo publicar evento ${envelope.type}: ${(error as Error).message}`,
      );
    }
  }

  async publishCheckoutEvent(
    type: CheckoutEventType,
    source: string,
    data: CheckoutSideEffectEventData,
  ): Promise<void> {
    const envelope: CheckoutEventEnvelope = {
      id: randomUUID(),
      type,
      occurredAt: new Date().toISOString(),
      source,
      data,
    };

    await this.publish(CHECKOUT_EVENTS_CHANNEL, envelope);
  }

  async onModuleDestroy(): Promise<void> {
    if (this.redis) {
      await this.redis.quit();
      this.redis = null;
    }
  }
}

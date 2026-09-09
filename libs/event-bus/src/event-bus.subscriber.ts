import { Injectable, Logger, OnModuleDestroy, OnModuleInit } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import type Redis from 'ioredis';

import { CHECKOUT_EVENTS_CHANNEL } from './checkout/checkout-event.constants';
import type { NmEventEnvelope } from './checkout/checkout-event.types';
import { isEventBusEnabled, resolveEventBusRedisUrl } from './event-bus.config';

export type EventBusHandler = (envelope: NmEventEnvelope) => Promise<void>;

@Injectable()
export class EventBusSubscriber implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(EventBusSubscriber.name);
  private subscriber: Redis | null = null;
  private readonly handlers = new Map<string, EventBusHandler[]>();
  private readonly enabled: boolean;

  constructor(private readonly config: ConfigService) {
    this.enabled = isEventBusEnabled(config);
  }

  registerHandler(eventType: string, handler: EventBusHandler): void {
    const existing = this.handlers.get(eventType) ?? [];
    existing.push(handler);
    this.handlers.set(eventType, existing);
  }

  async onModuleInit(): Promise<void> {
    if (!this.enabled) {
      this.logger.log('EVENT_BUS_ENABLED=false — subscriber desactivado');
      return;
    }

    if (this.handlers.size === 0) {
      return;
    }

    try {
      const { default: RedisClient } = await import('ioredis');
      this.subscriber = new RedisClient(resolveEventBusRedisUrl(this.config), {
        connectTimeout: Number(this.config.get<string>('REDIS_CONNECT_TIMEOUT_MS', '5000')),
        maxRetriesPerRequest: null,
        lazyConnect: true,
      });
      await this.subscriber.connect();
      await this.subscriber.subscribe(CHECKOUT_EVENTS_CHANNEL);
      this.subscriber.on('message', (channel, message) => {
        void this.dispatch(channel, message);
      });
      this.logger.log(
        `Event bus subscriber escuchando ${CHECKOUT_EVENTS_CHANNEL} (${this.handlers.size} tipo(s))`,
      );
    } catch (error) {
      this.logger.warn(
        `Event bus subscriber no disponible: ${(error as Error).message}`,
      );
      this.subscriber = null;
    }
  }

  private async dispatch(channel: string, message: string): Promise<void> {
    if (channel !== CHECKOUT_EVENTS_CHANNEL) {
      return;
    }

    let envelope: NmEventEnvelope;
    try {
      envelope = JSON.parse(message) as NmEventEnvelope;
    } catch {
      this.logger.warn('Evento checkout con JSON inválido — ignorado');
      return;
    }

    const handlers = this.handlers.get(envelope.type) ?? [];
    if (handlers.length === 0) {
      return;
    }

    for (const handler of handlers) {
      try {
        await handler(envelope);
      } catch (error) {
        this.logger.warn(
          `Handler falló para ${envelope.type} (${envelope.id}): ${(error as Error).message}`,
        );
      }
    }
  }

  async onModuleDestroy(): Promise<void> {
    if (this.subscriber) {
      await this.subscriber.quit();
      this.subscriber = null;
    }
  }
}

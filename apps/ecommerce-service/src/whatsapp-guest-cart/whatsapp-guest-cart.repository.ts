import { Injectable, Logger, OnModuleDestroy } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

import { WHATSAPP_GUEST_CART_TTL_SECONDS } from './constants/whatsapp-guest-cart.constants';
import type { WhatsAppGuestCartPayload } from './interfaces/whatsapp-guest-cart.payload';
import { buildWhatsAppGuestCartRedisKey } from './utils/whatsapp-session-id.util';

interface MemoryEntry {
  value: string;
  expiresAt: number;
}

interface CartKvClient {
  get(key: string): Promise<string | null>;
  set(key: string, value: string, mode: 'EX', ttl: number): Promise<unknown>;
  del(key: string): Promise<number>;
  ttl(key: string): Promise<number>;
  quit(): Promise<string>;
}

@Injectable()
export class WhatsAppGuestCartRepository implements OnModuleDestroy {
  private readonly logger = new Logger(WhatsAppGuestCartRepository.name);
  private readonly memory = new Map<string, MemoryEntry>();
  private redis: CartKvClient | null = null;
  private readonly ttlSeconds: number;

  constructor(private readonly config: ConfigService) {
    this.ttlSeconds = Number(
      this.config.get('WHATSAPP_GUEST_CART_TTL_SECONDS', WHATSAPP_GUEST_CART_TTL_SECONDS),
    );
    void this.initRedis();
  }

  getDefaultTtlSeconds(): number {
    return this.ttlSeconds;
  }

  async get(sessionId: string): Promise<WhatsAppGuestCartPayload | null> {
    const key = buildWhatsAppGuestCartRedisKey(sessionId);
    const raw = await this.read(key);
    if (!raw) {
      return null;
    }

    try {
      return JSON.parse(raw) as WhatsAppGuestCartPayload;
    } catch {
      this.logger.warn('Invalid WhatsApp guest cart payload in store', { sessionId });
      await this.delete(sessionId);
      return null;
    }
  }

  async save(payload: WhatsAppGuestCartPayload, ttlSeconds?: number): Promise<void> {
    const key = buildWhatsAppGuestCartRedisKey(payload.sessionId);
    const ttl = ttlSeconds ?? this.ttlSeconds;
    await this.write(key, JSON.stringify(payload), ttl);
  }

  async delete(sessionId: string): Promise<void> {
    const key = buildWhatsAppGuestCartRedisKey(sessionId);
    if (this.redis) {
      await this.redis.del(key);
      return;
    }
    this.memory.delete(key);
  }

  async getTtl(sessionId: string): Promise<number> {
    const key = buildWhatsAppGuestCartRedisKey(sessionId);
    if (this.redis) {
      const ttl = await this.redis.ttl(key);
      return ttl > 0 ? ttl : 0;
    }

    const entry = this.memory.get(key);
    if (!entry) {
      return 0;
    }
    return Math.max(0, Math.ceil((entry.expiresAt - Date.now()) / 1000));
  }

  async onModuleDestroy(): Promise<void> {
    if (this.redis) {
      await this.redis.quit();
    }
  }

  private async initRedis(): Promise<void> {
    const redisUrl = this.config.get<string>('REDIS_URL');
    if (!redisUrl) {
      this.logger.warn('REDIS_URL not set — WhatsApp guest carts will use in-memory store');
      return;
    }

    try {
      const { default: Redis } = await import('ioredis');
      const client = new Redis(redisUrl, {
        maxRetriesPerRequest: 1,
        enableReadyCheck: true,
        lazyConnect: true,
      });
      await client.connect();
      this.redis = client;
      this.logger.log('WhatsApp guest cart connected to Redis');
    } catch (error) {
      this.logger.warn(
        `Redis unavailable for WhatsApp guest carts — falling back to memory: ${(error as Error).message}`,
      );
    }
  }

  private async read(key: string): Promise<string | null> {
    if (this.redis) {
      return this.redis.get(key);
    }

    const entry = this.memory.get(key);
    if (!entry) {
      return null;
    }
    if (Date.now() > entry.expiresAt) {
      this.memory.delete(key);
      return null;
    }
    return entry.value;
  }

  private async write(key: string, value: string, ttlSeconds: number): Promise<void> {
    if (this.redis) {
      await this.redis.set(key, value, 'EX', ttlSeconds);
      return;
    }

    this.memory.set(key, {
      value,
      expiresAt: Date.now() + ttlSeconds * 1000,
    });
  }
}

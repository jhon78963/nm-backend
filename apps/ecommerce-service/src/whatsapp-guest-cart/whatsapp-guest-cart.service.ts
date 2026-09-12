import { BadRequestException, Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

import {
  DEFAULT_WHATSAPP_HANDOFF_PHONE,
  DEFAULT_WHATSAPP_HANDOFF_TEXT,
  WHATSAPP_GUEST_CART_CURRENCY,
  WHATSAPP_GUEST_CART_TTL_SECONDS,
} from './constants/whatsapp-guest-cart.constants';
import type { UpsertWhatsAppGuestCartDto, WhatsAppGuestCartItemDto } from './dto/upsert-whatsapp-guest-cart.dto';
import type {
  WhatsAppGuestCartHandoffResult,
  WhatsAppGuestCartItem,
  WhatsAppGuestCartPayload,
  WhatsAppGuestCartSource,
} from './interfaces/whatsapp-guest-cart.payload';
import { buildWhatsAppHandoffDeepLink } from './utils/whatsapp-deep-link.util';
import { resolveWhatsAppHandoffPhone } from './utils/resolve-handoff-phone.util';
import {
  hashWhatsAppSessionId,
  isWhatsAppSessionId,
} from './utils/whatsapp-session-id.util';
import { WhatsAppGuestCartRepository } from './whatsapp-guest-cart.repository';

@Injectable()
export class WhatsAppGuestCartService {
  constructor(
    private readonly repository: WhatsAppGuestCartRepository,
    private readonly config: ConfigService,
  ) {}

  async upsert(dto: UpsertWhatsAppGuestCartDto): Promise<WhatsAppGuestCartHandoffResult> {
    const sessionId = this.hashSessionId(dto.customerPhone);
    const existing = dto.replace ? null : await this.repository.get(sessionId);
    const now = new Date();
    const ttlSeconds = this.repository.getDefaultTtlSeconds();
    const incoming = this.normalizeItems(dto.items);
    const items = existing ? this.mergeItems(existing.items, incoming) : incoming;
    const totals = this.computeTotals(items);

    const payload: WhatsAppGuestCartPayload = {
      sessionId,
      warehouseId: dto.warehouseId ?? existing?.warehouseId ?? this.defaultWarehouseId(),
      channel: 'whatsapp',
      currency: WHATSAPP_GUEST_CART_CURRENCY,
      items,
      ...totals,
      source: dto.source ?? existing?.source ?? 'bot',
      createdAt: existing?.createdAt ?? now.toISOString(),
      updatedAt: now.toISOString(),
      expiresAt: new Date(now.getTime() + ttlSeconds * 1000).toISOString(),
    };

    await this.repository.save(payload, ttlSeconds);
    return this.toHandoffResult(payload, ttlSeconds);
  }

  async getBySessionId(sessionId: string): Promise<WhatsAppGuestCartHandoffResult> {
    if (!isWhatsAppSessionId(sessionId)) {
      throw new BadRequestException('Session ID de carrito WhatsApp inválido.');
    }

    const cart = await this.repository.get(sessionId.toLowerCase());
    const ttlSeconds = this.repository.getDefaultTtlSeconds();

    if (cart) {
      const refreshed: WhatsAppGuestCartPayload = {
        ...cart,
        expiresAt: new Date(Date.now() + ttlSeconds * 1000).toISOString(),
        updatedAt: cart.updatedAt,
      };
      await this.repository.save(refreshed, ttlSeconds);
      return this.toHandoffResult(refreshed, ttlSeconds);
    }

    return this.toHandoffResult(cart, ttlSeconds, sessionId.toLowerCase());
  }

  async getHandoff(params: {
    customerPhone?: string;
    sessionId?: string;
  }): Promise<WhatsAppGuestCartHandoffResult> {
    const sessionId = params.sessionId
      ? params.sessionId.toLowerCase()
      : params.customerPhone
        ? this.hashSessionId(params.customerPhone)
        : null;

    if (!sessionId) {
      throw new BadRequestException('Debe enviar customerPhone o sessionId.');
    }

    return this.getBySessionId(sessionId);
  }

  async clear(sessionId: string): Promise<void> {
    if (!isWhatsAppSessionId(sessionId)) {
      throw new BadRequestException('Session ID de carrito WhatsApp inválido.');
    }
    await this.repository.delete(sessionId.toLowerCase());
  }

  buildHandoffUrl(sessionId: string): string {
    return buildWhatsAppHandoffDeepLink({
      advisorPhone: resolveWhatsAppHandoffPhone(this.config),
      sessionId,
      textTemplate: this.config.get<string>(
        'WHATSAPP_HANDOFF_TEXT',
        DEFAULT_WHATSAPP_HANDOFF_TEXT,
      ),
    });
  }

  private hashSessionId(phone: string): string {
    return hashWhatsAppSessionId(phone, this.sessionSalt());
  }

  private sessionSalt(): string {
    const salt =
      this.config.get<string>('WHATSAPP_CART_SESSION_SALT')
      || this.config.get<string>('INTERNAL_SERVICE_KEY', '');
    if (!salt.trim()) {
      throw new BadRequestException(
        'Configure WHATSAPP_CART_SESSION_SALT o INTERNAL_SERVICE_KEY para hashear el carrito.',
      );
    }
    return salt;
  }

  private defaultWarehouseId(): string {
    return this.config.get<string>(
      'STORE_WAREHOUSE_ID',
      '46ea2f24-30d2-59a3-8790-8670a0105280',
    );
  }

  private normalizeItems(items: WhatsAppGuestCartItemDto[]): WhatsAppGuestCartItem[] {
    return items.map((item) => {
      const quantity = item.quantity;
      const unitPrice = Number(item.unitPrice ?? 0);
      return {
        ...(item.productId ? { productId: item.productId } : {}),
        ...(item.productSizeId ? { productSizeId: item.productSizeId } : {}),
        ...(item.colorId ? { colorId: item.colorId } : {}),
        ...(item.productIdPrefix ? { productIdPrefix: item.productIdPrefix } : {}),
        ...(item.sku ? { sku: item.sku } : {}),
        name: item.name.trim(),
        ...(item.variation ? { variation: item.variation } : {}),
        ...(item.imageUrl ? { imageUrl: item.imageUrl } : {}),
        ...(item.productUrl ? { productUrl: item.productUrl } : {}),
        quantity,
        unitPrice,
        lineTotal: Number((quantity * unitPrice).toFixed(2)),
      };
    });
  }

  private mergeItems(
    existing: WhatsAppGuestCartItem[],
    incoming: WhatsAppGuestCartItem[],
  ): WhatsAppGuestCartItem[] {
    const merged = new Map<string, WhatsAppGuestCartItem>();

    for (const item of [...existing, ...incoming]) {
      const key = this.itemKey(item);
      const current = merged.get(key);
      if (!current) {
        merged.set(key, { ...item });
        continue;
      }

      const quantity = current.quantity + item.quantity;
      const unitPrice = item.unitPrice || current.unitPrice;
      merged.set(key, {
        ...current,
        ...item,
        quantity,
        unitPrice,
        lineTotal: Number((quantity * unitPrice).toFixed(2)),
      });
    }

    return [...merged.values()];
  }

  private itemKey(item: WhatsAppGuestCartItem): string {
    if (item.productId || item.productSizeId || item.colorId) {
      return `id:${item.productId ?? ''}:${item.productSizeId ?? ''}:${item.colorId ?? ''}`;
    }
    if (item.sku) {
      return `sku:${item.sku}`;
    }
    if (item.productIdPrefix) {
      return `pid:${item.productIdPrefix}`;
    }
    return `name:${item.name.toLowerCase()}:${item.variation ?? ''}`;
  }

  private computeTotals(items: WhatsAppGuestCartItem[]): {
    itemCount: number;
    subtotal: number;
    total: number;
  } {
    const itemCount = items.reduce((sum, item) => sum + item.quantity, 0);
    const subtotal = Number(items.reduce((sum, item) => sum + item.lineTotal, 0).toFixed(2));
    return { itemCount, subtotal, total: subtotal };
  }

  private toHandoffResult(
    cart: WhatsAppGuestCartPayload | null,
    ttlSeconds: number,
    sessionId = cart?.sessionId,
  ): WhatsAppGuestCartHandoffResult {
    const resolvedSessionId = sessionId ?? cart?.sessionId;
    if (!resolvedSessionId) {
      throw new BadRequestException('No se pudo resolver el Session ID del carrito.');
    }

    return {
      sessionId: resolvedSessionId,
      cart,
      handoffUrl: this.buildHandoffUrl(resolvedSessionId),
      ttlSeconds: ttlSeconds || WHATSAPP_GUEST_CART_TTL_SECONDS,
    };
  }
}

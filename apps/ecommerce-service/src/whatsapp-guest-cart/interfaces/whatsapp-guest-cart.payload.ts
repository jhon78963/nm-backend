export type WhatsAppGuestCartSource = 'pdp' | 'bot' | 'handoff';

export interface WhatsAppGuestCartItem {
  productId?: string;
  productSizeId?: string;
  colorId?: string;
  productIdPrefix?: string;
  sku?: string;
  name: string;
  variation?: string;
  imageUrl?: string;
  productUrl?: string;
  quantity: number;
  unitPrice: number;
  lineTotal: number;
}

export interface WhatsAppGuestCartPayload {
  sessionId: string;
  warehouseId: string;
  channel: 'whatsapp';
  currency: 'PEN';
  items: WhatsAppGuestCartItem[];
  itemCount: number;
  subtotal: number;
  total: number;
  source: WhatsAppGuestCartSource;
  createdAt: string;
  updatedAt: string;
  expiresAt: string;
}

export interface WhatsAppGuestCartHandoffResult {
  sessionId: string;
  cart: WhatsAppGuestCartPayload | null;
  handoffUrl: string;
  ttlSeconds: number;
}

import { createHmac } from 'node:crypto';
import { BadRequestException } from '@nestjs/common';

import {
  WHATSAPP_GUEST_CART_KEY_PREFIX,
  WHATSAPP_SESSION_ID_HEX_LENGTH,
} from '../constants/whatsapp-guest-cart.constants';

export function normalizeWhatsAppPhone(raw: string): string {
  const digits = raw.replace(/\D/g, '');
  if (digits.length < 8) {
    throw new BadRequestException('Número de WhatsApp inválido.');
  }
  return digits;
}

export function isWhatsAppSessionId(value: string): boolean {
  return new RegExp(`^[a-f0-9]{${WHATSAPP_SESSION_ID_HEX_LENGTH}}$`, 'i').test(value);
}

export function hashWhatsAppSessionId(phone: string, salt: string): string {
  if (!salt.trim()) {
    throw new BadRequestException('Salt de sesión WhatsApp no configurado.');
  }

  return createHmac('sha256', salt)
    .update(normalizeWhatsAppPhone(phone))
    .digest('hex')
    .slice(0, WHATSAPP_SESSION_ID_HEX_LENGTH);
}

export function buildWhatsAppGuestCartRedisKey(sessionId: string): string {
  return `${WHATSAPP_GUEST_CART_KEY_PREFIX}${sessionId.toLowerCase()}`;
}

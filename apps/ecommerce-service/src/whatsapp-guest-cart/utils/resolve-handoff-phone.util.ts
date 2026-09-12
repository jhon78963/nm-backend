import { ConfigService } from '@nestjs/config';

import { DEFAULT_WHATSAPP_HANDOFF_PHONE } from '../constants/whatsapp-guest-cart.constants';
import { toWaMePhone } from './whatsapp-deep-link.util';

/**
 * Número público para enlaces wa.me (handoff / segundo contacto).
 * Meta entrega PHONE_NUMBER_ID (API) y aparte el display phone en Business Suite.
 */
export function resolveWhatsAppHandoffPhone(config: ConfigService): string {
  const explicit = config.get<string>('WHATSAPP_HANDOFF_PHONE')?.trim();
  if (explicit) {
    return toWaMePhone(explicit);
  }

  const fromMetaDisplay = config.get<string>('META_WHATSAPP_DISPLAY_PHONE')?.trim();
  if (fromMetaDisplay) {
    return toWaMePhone(fromMetaDisplay);
  }

  return DEFAULT_WHATSAPP_HANDOFF_PHONE;
}

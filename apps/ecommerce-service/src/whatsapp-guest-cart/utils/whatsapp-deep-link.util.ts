import {
  DEFAULT_WHATSAPP_HANDOFF_PHONE,
  DEFAULT_WHATSAPP_HANDOFF_TEXT,
} from '../constants/whatsapp-guest-cart.constants';

export function toWaMePhone(raw: string): string {
  const digits = raw.replace(/\D/g, '').replace(/^0+/, '');
  return digits || DEFAULT_WHATSAPP_HANDOFF_PHONE;
}

export function buildWhatsAppHandoffDeepLink(params: {
  advisorPhone: string;
  sessionId: string;
  textTemplate?: string;
}): string {
  const phone = toWaMePhone(params.advisorPhone);
  const template = params.textTemplate?.trim() || DEFAULT_WHATSAPP_HANDOFF_TEXT;
  const text = template.replaceAll('{sessionId}', params.sessionId);
  return `https://wa.me/${phone}?text=${encodeURIComponent(text)}`;
}

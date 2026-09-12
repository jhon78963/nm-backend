import { hashWhatsAppSessionId, isWhatsAppSessionId, normalizeWhatsAppPhone } from './whatsapp-session-id.util';

describe('whatsapp-session-id.util', () => {
  const salt = 'test-salt';

  it('normaliza E.164 y dígitos al mismo valor', () => {
    expect(normalizeWhatsAppPhone('+51 915 213 408')).toBe('51915213408');
    expect(normalizeWhatsAppPhone('51915213408')).toBe('51915213408');
  });

  it('genera un Session ID HMAC de 32 hex estable por teléfono + salt', () => {
    const first = hashWhatsAppSessionId('+51915213408', salt);
    const second = hashWhatsAppSessionId('51915213408', salt);

    expect(first).toHaveLength(32);
    expect(first).toBe(second);
    expect(isWhatsAppSessionId(first)).toBe(true);
  });

  it('cambia el hash si cambia el salt', () => {
    const a = hashWhatsAppSessionId('51915213408', 'salt-a');
    const b = hashWhatsAppSessionId('51915213408', 'salt-b');
    expect(a).not.toBe(b);
  });
});

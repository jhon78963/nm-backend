import { ConfigService } from '@nestjs/config';

import { resolveWhatsAppHandoffPhone } from './resolve-handoff-phone.util';

describe('resolveWhatsAppHandoffPhone', () => {
  it('prefers WHATSAPP_HANDOFF_PHONE', () => {
    const config = {
      get: jest.fn((key: string) => {
        if (key === 'WHATSAPP_HANDOFF_PHONE') return '+51 999 888 777';
        if (key === 'META_WHATSAPP_DISPLAY_PHONE') return '51911111111';
        return undefined;
      }),
    } as unknown as ConfigService;

    expect(resolveWhatsAppHandoffPhone(config)).toBe('51999888777');
  });

  it('falls back to META_WHATSAPP_DISPLAY_PHONE', () => {
    const config = {
      get: jest.fn((key: string) => {
        if (key === 'META_WHATSAPP_DISPLAY_PHONE') return '+51 915 213 408';
        return undefined;
      }),
    } as unknown as ConfigService;

    expect(resolveWhatsAppHandoffPhone(config)).toBe('51915213408');
  });
});

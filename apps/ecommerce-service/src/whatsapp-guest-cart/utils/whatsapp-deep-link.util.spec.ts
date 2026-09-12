import { buildWhatsAppHandoffDeepLink, toWaMePhone } from './whatsapp-deep-link.util';

describe('whatsapp-deep-link.util', () => {
  it('normaliza el teléfono a dígitos para wa.me', () => {
    expect(toWaMePhone('+51 915 213 408')).toBe('51915213408');
  });

  it('construye el deep link con el Session ID hasheado', () => {
    const sessionId = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    const url = buildWhatsAppHandoffDeepLink({
      advisorPhone: '51915213408',
      sessionId,
    });

    expect(url.startsWith('https://wa.me/51915213408?text=')).toBe(true);
    expect(url).toContain(encodeURIComponent(`[${sessionId}]`));
    expect(decodeURIComponent(url)).toContain(
      'Hola, vengo del bot. Quiero concretar el pedido de mi carrito',
    );
  });
});

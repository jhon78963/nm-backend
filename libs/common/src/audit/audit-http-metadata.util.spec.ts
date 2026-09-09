import { buildHttpAuditMetadata, sanitizeHttpPayload } from './audit-http-metadata.util';

describe('audit-http-metadata.util', () => {
  it('redacta campos sensibles del payload', () => {
    const sanitized = sanitizeHttpPayload({
      username: 'juan',
      password: 'secret',
      items: [{ productSizeId: 'uuid-1' }],
    });

    expect(sanitized).toEqual({
      username: 'juan',
      password: '[redacted]',
      items: [{ productSizeId: 'uuid-1' }],
    });
  });

  it('arma metadata tipo network para checkout', () => {
    const metadata = buildHttpAuditMetadata({
      req: {
        method: 'POST',
        url: '/api/v1/checkout',
        headers: {
          host: 'api.novedadesmaritex.net.pe',
          'x-forwarded-proto': 'https',
          'user-agent': 'Mozilla/5.0 (iPad)',
          referer: 'https://app.novedadesmaritex.net.pe/pos',
        },
        body: {
          items: [{ productSizeId: 'size-1', colorId: '0', quantity: 1, unitPrice: 10 }],
          payments: [{ method: 'CASH', amount: 10 }],
        },
        ip: '172.19.0.20',
      } as never,
      statusCode: 400,
      durationMs: 244,
      requestId: 'req-1',
      responseBody: JSON.stringify({
        statusCode: 400,
        message: 'Error de validación.',
        errors: { validation: ['colorId must be a UUID'] },
      }),
      responseHeaders: new Headers({
        'content-type': 'application/json',
        'referrer-policy': 'strict-origin-when-cross-origin',
      }),
    });

    expect(metadata.request).toMatchObject({
      url: 'https://api.novedadesmaritex.net.pe/api/v1/checkout',
      method: 'POST',
      payload: {
        items: [{ productSizeId: 'size-1', colorId: '0', quantity: 1, unitPrice: 10 }],
      },
      product_size_ids: ['size-1'],
      color_ids: ['0'],
    });
    expect(metadata.response).toMatchObject({
      status_code: 400,
      preview: {
        message: 'Error de validación.',
      },
    });
    expect(metadata.network).toMatchObject({
      remote_address: '172.19.0.20',
      referrer_policy: 'strict-origin-when-cross-origin',
    });
    expect(metadata.timing).toEqual({
      duration_ms: 244,
      logged_at: expect.any(String),
    });
  });
});

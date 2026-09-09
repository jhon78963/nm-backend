import type { FastifyRequest } from 'fastify';

import { attachRequestId, resolveRequestId } from './request-id.util';

describe('request-id.util', () => {
  it('reutiliza x-request-id válido del cliente', () => {
    const request = {
      headers: { 'x-request-id': 'client-req-12345678' },
    } as unknown as FastifyRequest;

    expect(resolveRequestId(request)).toBe('client-req-12345678');
  });

  it('genera UUID cuando no hay header', () => {
    const request = { headers: {} } as unknown as FastifyRequest;

    expect(resolveRequestId(request)).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
    );
  });

  it('adjunta requestId al request Fastify', () => {
    const request = { headers: { 'x-request-id': 'trace-abc-12345678' } } as unknown as FastifyRequest;

    const requestId = attachRequestId(request);

    expect(requestId).toBe('trace-abc-12345678');
    expect(request.requestId).toBe('trace-abc-12345678');
  });
});

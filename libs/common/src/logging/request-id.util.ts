import { randomUUID } from 'crypto';
import type { FastifyRequest } from 'fastify';

import './request-id.types';

export const REQUEST_ID_HEADER = 'x-request-id';

const OPAQUE_REQUEST_ID_PATTERN = /^[\w.-]{8,64}$/i;

function readHeaderValue(value: string | string[] | undefined): string | undefined {
  if (Array.isArray(value)) {
    return value[0]?.trim() || undefined;
  }

  return value?.trim() || undefined;
}

export function resolveRequestId(
  request: Pick<FastifyRequest, 'headers' | 'requestId'>,
): string {
  if (request.requestId?.trim()) {
    return request.requestId.trim();
  }

  const incoming = readHeaderValue(request.headers[REQUEST_ID_HEADER]);
  if (incoming && OPAQUE_REQUEST_ID_PATTERN.test(incoming)) {
    return incoming;
  }

  return randomUUID();
}

export function attachRequestId(request: FastifyRequest): string {
  const requestId = resolveRequestId(request);
  request.requestId = requestId;
  return requestId;
}

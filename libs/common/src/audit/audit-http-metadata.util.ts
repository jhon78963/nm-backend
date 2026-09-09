import type { FastifyRequest } from 'fastify';

const SENSITIVE_KEYS = new Set([
  'password',
  'token',
  'authorization',
  'secret',
  'apikey',
  'api_key',
  'access_token',
  'refresh_token',
]);

const MAX_JSON_LENGTH = 8_000;
const MAX_PREVIEW_LENGTH = 2_000;

type JsonRecord = Record<string, unknown>;

function asRecord(value: unknown): JsonRecord {
  return value && typeof value === 'object' && !Array.isArray(value)
    ? (value as JsonRecord)
    : {};
}

function truncate(value: string, max = MAX_JSON_LENGTH): string {
  if (value.length <= max) return value;
  return `${value.slice(0, max)}…`;
}

export function resolveClientAddress(req: FastifyRequest): string | null {
  const forwarded = req.headers['x-forwarded-for'];
  if (typeof forwarded === 'string' && forwarded.trim()) {
    return forwarded.split(',')[0]?.trim() ?? null;
  }

  if (typeof req.headers['x-real-ip'] === 'string' && req.headers['x-real-ip'].trim()) {
    return req.headers['x-real-ip'].trim();
  }

  const socket = (req as FastifyRequest & { socket?: { remoteAddress?: string } }).socket;
  return socket?.remoteAddress ?? req.ip ?? null;
}

export function buildRequestUrl(req: FastifyRequest): string {
  const path = req.url ?? '/';
  const protoHeader = req.headers['x-forwarded-proto'];
  const hostHeader = req.headers['x-forwarded-host'] ?? req.headers.host;
  const proto = typeof protoHeader === 'string' ? protoHeader.split(',')[0]?.trim() : 'https';
  const host = typeof hostHeader === 'string' ? hostHeader.split(',')[0]?.trim() : 'unknown-host';

  return `${proto}://${host}${path.startsWith('/') ? path : `/${path}`}`;
}

function redactValue(key: string, value: unknown): unknown {
  if (SENSITIVE_KEYS.has(key.toLowerCase())) {
    return '[redacted]';
  }

  if (Array.isArray(value)) {
    return value.map((item) =>
      item && typeof item === 'object' ? redactObject(asRecord(item)) : item,
    );
  }

  if (value && typeof value === 'object') {
    return redactObject(value as JsonRecord);
  }

  return value;
}

function redactObject(value: JsonRecord): JsonRecord {
  const output: JsonRecord = {};
  for (const [key, entry] of Object.entries(value)) {
    output[key] = redactValue(key, entry);
  }
  return output;
}

export function sanitizeHttpPayload(value: unknown): unknown {
  if (value == null) return null;
  if (typeof value === 'string') {
    try {
      return sanitizeHttpPayload(JSON.parse(value));
    } catch {
      return truncate(value);
    }
  }

  if (Array.isArray(value)) {
    return value.map((item) => sanitizeHttpPayload(item));
  }

  if (typeof value === 'object') {
    return redactObject(value as JsonRecord);
  }

  return value;
}

export function buildResponsePreview(
  body: string | null | undefined,
  contentType?: string | null,
): unknown {
  if (!body) return null;

  const type = (contentType ?? '').toLowerCase();
  if (!type.includes('json')) {
    return truncate(body, MAX_PREVIEW_LENGTH);
  }

  try {
    return sanitizeHttpPayload(JSON.parse(body));
  } catch {
    return truncate(body, MAX_PREVIEW_LENGTH);
  }
}

function extractProductIdFromPath(path: string): string | null {
  const match = path.match(/\/products\/([0-9a-f-]{36})/i);
  return match?.[1] ?? null;
}

function extractCheckoutIdentifiers(payload: unknown): {
  productIds: string[];
  productSizeIds: string[];
  colorIds: string[];
} {
  const record = asRecord(payload);
  const items = Array.isArray(record.items) ? record.items : [];
  const productSizeIds = new Set<string>();
  const colorIds = new Set<string>();

  for (const item of items) {
    const row = asRecord(item);
    const productSizeId = row.productSizeId ?? row.product_size_id;
    const colorId = row.colorId ?? row.color_id;

    if (typeof productSizeId === 'string' && productSizeId.trim()) {
      productSizeIds.add(productSizeId.trim());
    }
    if (typeof colorId === 'string' && colorId.trim()) {
      colorIds.add(colorId.trim());
    }
  }

  return {
    productIds: [],
    productSizeIds: [...productSizeIds],
    colorIds: [...colorIds],
  };
}

export function buildHttpAuditMetadata(input: {
  req: FastifyRequest;
  statusCode: number;
  durationMs: number;
  requestId?: string | null;
  responseBody?: string | null;
  responseHeaders?: Headers | null;
}): Record<string, unknown> {
  const path = (input.req.url ?? '').split('?')[0];
  const payload = sanitizeHttpPayload(input.req.body);
  const productIdFromPath = extractProductIdFromPath(path);
  const checkoutIds =
    path.includes('/checkout') ? extractCheckoutIdentifiers(payload) : null;

  const responseHeaders: JsonRecord = {};
  const referrerPolicy = input.responseHeaders?.get('referrer-policy');
  if (referrerPolicy) {
    responseHeaders.referrer_policy = referrerPolicy;
  }

  const metadata: Record<string, unknown> = {
    request: {
      url: buildRequestUrl(input.req),
      method: input.req.method,
      path,
      headers: {
        user_agent: input.req.headers['user-agent'] ?? null,
        referer: input.req.headers.referer ?? null,
        content_type: input.req.headers['content-type'] ?? null,
      },
      payload,
      product_id: productIdFromPath,
      product_size_ids: checkoutIds?.productSizeIds ?? [],
      color_ids: checkoutIds?.colorIds ?? [],
    },
    response: {
      status_code: input.statusCode,
      preview: buildResponsePreview(
        input.responseBody,
        input.responseHeaders?.get('content-type'),
      ),
      headers: responseHeaders,
    },
    network: {
      remote_address: resolveClientAddress(input.req),
      referrer_policy: referrerPolicy ?? null,
    },
    timing: {
      duration_ms: input.durationMs,
      logged_at: new Date().toISOString(),
    },
    request_id: input.requestId ?? null,
  };

  return metadata;
}

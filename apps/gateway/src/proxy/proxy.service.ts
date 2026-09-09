import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import {
  resolveHttpAction,
  resolveHttpDescription,
  shouldLogHttpRequest,
} from '@app/common/audit/audit-http.util';
import { buildHttpAuditMetadata } from '@app/common/audit/audit-http-metadata.util';
import { UserActionLogWriter } from '@app/common/audit/user-action-log.writer';
import { attachRequestId, REQUEST_ID_HEADER } from '@app/common/logging/request-id.util';
import { writeStructuredLog } from '@app/common/logging/structured-log.util';
import type { AuthenticatedUser } from '@app/common/types/authenticated-user.type';
import { FastifyRequest, FastifyReply } from 'fastify';

type RequestWithUser = FastifyRequest & { user?: AuthenticatedUser };

type ServiceName =
  | 'auth'
  | 'catalog'
  | 'inventory'
  | 'pos'
  | 'finance'
  | 'hr'
  | 'report'
  | 'ai-proxy'
  | 'storage'
  | 'ecommerce'
  | 'chatbot';

/**
 * ProxyService — BFF (Backend for Frontend) del Gateway.
 *
 * ESTRATEGIA DE ROUTING (equivale a routes/api.php de Laravel):
 *   /api/v1/auth/*        → auth-service      :3001
 *   /api/v1/products/*    → catalog-service   :3002
 *   /api/v1/inventory/*   → inventory-service :3003
 *   /api/v1/checkout/*    → pos-service       :3004
 *   /api/v1/pos/*         → pos-service       :3004
 *   /api/v1/sales/*       → pos-service       :3004
   *   /api/v1/cashflow/daily|monthly|admin/*|accumulated/* → report-service :3007
   *   /api/v1/cashflow/*    → finance-service   :3005 (CRUD)
   *   /api/v1/financial-summary → report-service :3007
 *   /api/v1/teams/*       → hr-service        :3006
 *   /api/v1/customers/*   → hr-service        :3006
 *   /api/v1/dashboard/*   → report-service    :3007
 *   /api/v1/reports/*     → report-service    :3007
 *   /api/v1/ai/*          → report-service    :3007 (AI proxy)
   *   /api/v1/storage/*     → storage-service   :3008  (rutas con /v1, igual que auth)
   *   /api/v1/ecommerce/*   → ecommerce-service :3012
   *   /api/v1/chatbot/*     → chatbot-service     :8090 (JWT propio del chatbot)
 *
 * El JWT ya fue validado en JwtAuthGuard antes de llegar aquí.
 * El gateway reenvía el header Authorization al servicio destino.
 */
@Injectable()
export class ProxyService {
  private readonly logger = new Logger(ProxyService.name);
  private readonly serviceUrls: Record<ServiceName, string>;

  constructor(
    private readonly config: ConfigService,
    private readonly actionLogWriter: UserActionLogWriter,
  ) {
    this.serviceUrls = {
      'auth':      config.get('AUTH_SERVICE_URL',      'http://localhost:3001'),
      'catalog':   config.get('CATALOG_SERVICE_URL',   'http://localhost:3002'),
      'inventory': config.get('INVENTORY_SERVICE_URL', 'http://localhost:3003'),
      'pos':       config.get('POS_SERVICE_URL',       'http://localhost:3004'),
      'finance':   config.get('FINANCE_SERVICE_URL',   'http://localhost:3005'),
      'hr':        config.get('HR_SERVICE_URL',        'http://localhost:3006'),
      'report':    config.get('REPORT_SERVICE_URL',    'http://localhost:3007'),
      'ai-proxy':  config.get('REPORT_SERVICE_URL',    'http://localhost:3007'),
      'storage':   config.get('STORAGE_SERVICE_URL',   'http://localhost:3008'),
      'ecommerce': config.get('ECOMMERCE_SERVICE_URL', 'http://localhost:3012'),
      'chatbot':   config.get('CHATBOT_SERVICE_URL',   'http://localhost:8090'),
    };
  }

  resolveService(path: string): ServiceName {
    if (path.startsWith('/api/v1/auth'))           return 'auth';
    if (path.startsWith('/api/v1/users'))          return 'auth';
    if (path.startsWith('/api/v1/roles'))          return 'auth';
    if (path.startsWith('/api/v1/warehouses'))     return 'auth';
    if (path.startsWith('/api/v1/tenants'))        return 'auth';
    if (path.startsWith('/api/v1/user-action-logs')) return 'auth';
    if (path.startsWith('/api/v1/profile'))        return 'auth';
    if (path.startsWith('/api/v1/products'))       return 'catalog';
    if (path.startsWith('/api/v1/product-sizes')) return 'catalog';
    if (path.startsWith('/api/v1/colors'))    return 'catalog';
    if (path.startsWith('/api/v1/sizes'))     return 'catalog';
    if (path.startsWith('/api/v1/genders'))   return 'catalog';
    if (path.startsWith('/api/v1/inventory')) return 'inventory';
    if (path.startsWith('/api/v1/purchases')) return 'inventory';
    if (path.startsWith('/api/v1/kardex'))    return 'inventory';
    if (path.startsWith('/api/v1/checkout'))  return 'pos';
    if (path.startsWith('/api/v1/pos'))       return 'pos';
    if (path.startsWith('/api/v1/sales'))     return 'pos';
    if (path.startsWith('/api/v1/tickets'))   return 'pos';
    if (path.startsWith('/api/v1/sunat'))     return 'pos';
    if (path.startsWith('/api/v1/cashflow/daily')) return 'report';
    if (path.startsWith('/api/v1/cashflow/monthly')) return 'report';
    if (path.startsWith('/api/v1/cashflow/admin/monthly')) return 'report';
    if (path.startsWith('/api/v1/cashflow/accumulated/monthly')) return 'report';
    if (path.startsWith('/api/v1/financial-summary')) return 'report';
    if (path.startsWith('/api/v1/cashflow'))  return 'finance';
    if (path.startsWith('/api/v1/financial')) return 'finance';
    if (path.startsWith('/api/v1/accumulated')) return 'finance';
    if (path.startsWith('/api/v1/teams'))     return 'hr';
    if (path.startsWith('/api/v1/customers')) return 'hr';
    if (path.startsWith('/api/v1/vendors'))   return 'hr';
    if (path.startsWith('/api/v1/attendance')) return 'hr';
    if (path.startsWith('/api/v1/payments'))  return 'hr';
    if (path.startsWith('/api/v1/dashboard')) return 'report';
    if (path.startsWith('/api/v1/reports'))   return 'report';
    if (path.startsWith('/api/v1/ai'))        return 'ai-proxy';
    if (path.startsWith('/api/v1/storage'))   return 'storage';
    if (path.startsWith('/api/v1/ecommerce')) return 'ecommerce';
    if (path.startsWith('/api/v1/chatbot')) return 'chatbot';
    return 'auth'; // fallback
  }

  async forward(req: FastifyRequest, reply: FastifyReply) {
    const service = this.resolveService(req.url);
    const targetUrl = this.buildTargetUrl(this.serviceUrls[service], req.url, service);
    const requestId = attachRequestId(req);
    const startedAt = Date.now();

    writeStructuredLog(this.logger, 'debug', {
      event: 'gateway.proxy.start',
      requestId,
      method: req.method,
      path: req.url,
      targetService: service,
      targetUrl,
    });

    const isMultipart = (req.headers['content-type'] ?? '').includes('multipart/form-data');

    try {
      let fetchBody: BodyInit | undefined;
      const headers: Record<string, string> = {
        accept: 'application/json',
        [REQUEST_ID_HEADER]: requestId,
      };

      if (isMultipart) {
        // El addContentTypeParser del gateway almacena el raw buffer en req.body.
        headers['content-type'] = req.headers['content-type'] as string;
        const rawBody = req.body as Buffer | undefined;
        if (rawBody && Buffer.isBuffer(rawBody)) {
          fetchBody = rawBody as unknown as BodyInit;
          headers['content-length'] = String(rawBody.length);
        }
      } else {
        headers['content-type'] = 'application/json';
        fetchBody = ['GET', 'DELETE', 'HEAD'].includes(req.method)
          ? undefined
          : JSON.stringify(req.body);
      }

      // Propagar auth, warehouse y tenant headers
      if (req.headers.authorization) headers['authorization'] = req.headers.authorization;
      if (req.headers['x-warehouse-id']) headers['x-warehouse-id'] = req.headers['x-warehouse-id'] as string;
      if (req.headers['x-tenant-id']) headers['x-tenant-id'] = req.headers['x-tenant-id'] as string;

      const response = await fetch(targetUrl, {
        method: req.method,
        headers,
        body: fetchBody,
        // @ts-expect-error Node 18+ soporta duplex para streams
        duplex: isMultipart ? 'half' : undefined,
        signal: AbortSignal.timeout(60_000),
      });

      const contentType = response.headers.get('content-type') ?? 'application/json';
      const durationMs = Date.now() - startedAt;

      // Archivos binarios: reenviar como stream
      if (
        contentType.startsWith('image/')
        || contentType === 'application/pdf'
        || contentType === 'application/octet-stream'
        || contentType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
      ) {
        this.logHttpActivity(req, response.status, durationMs, null, response.headers);
        writeStructuredLog(this.logger, 'log', {
          event: 'gateway.proxy.completed',
          requestId,
          method: req.method,
          path: req.url,
          targetService: service,
          statusCode: response.status,
          durationMs,
        });
        const buffer = Buffer.from(await response.arrayBuffer());
        void reply
          .status(response.status)
          .header('content-type', contentType)
          .send(buffer);
        return;
      }

      const body = await response.text();
      this.logHttpActivity(req, response.status, durationMs, body, response.headers);
      writeStructuredLog(this.logger, 'log', {
        event: 'gateway.proxy.completed',
        requestId,
        method: req.method,
        path: req.url,
        targetService: service,
        statusCode: response.status,
        durationMs,
      });
      void reply
        .status(response.status)
        .header('content-type', contentType)
        .send(body);
    } catch (err) {
      writeStructuredLog(this.logger, 'error', {
        event: 'gateway.proxy.failed',
        requestId,
        method: req.method,
        path: req.url,
        targetService: service,
        durationMs: Date.now() - startedAt,
        error: (err as Error).message,
      });
      void reply.status(503).send({
        statusCode: 503,
        message: `Servicio ${service} temporalmente no disponible.`,
        requestId,
        timestamp: new Date().toISOString(),
      });
    }
  }

  /**
   * auth-service usa URI versioning (/v1/*). El resto de microservicios expone rutas sin /v1.
   */
  private logHttpActivity(
    req: FastifyRequest,
    statusCode: number,
    durationMs: number,
    responseBody?: string | null,
    responseHeaders?: Headers | null,
  ): void {
    const path = (req.url ?? '').split('?')[0];
    const actor = (req as RequestWithUser).user;
    if (!actor || !shouldLogHttpRequest(req.method, path)) {
      return;
    }

    void this.actionLogWriter.logSafely({
      action: resolveHttpAction(req.method, path),
      description: resolveHttpDescription(req.method, path),
      metadata: buildHttpAuditMetadata({
        req,
        statusCode,
        durationMs,
        requestId: req.requestId ?? null,
        responseBody,
        responseHeaders,
      }),
      ipAddress: req.ip ?? null,
      userId: actor.id,
      tenantId: actor.tenantId,
      warehouseId: actor.warehouseId || null,
    });
  }

  private buildTargetUrl(
    serviceUrl: string,
    originalUrl: string,
    service: ServiceName,
  ): string {
    // Chatbot: /api/v1/chatbot/* → chatbot /api/v1/* (o /health)
    if (service === 'chatbot') {
      const [pathOnly, query = ''] = originalUrl.split('?');
      const querySuffix = query ? `?${query}` : '';
      if (pathOnly === '/api/v1/chatbot/health') {
        return `${serviceUrl}/health${querySuffix}`;
      }
      const chatPath = pathOnly.replace(/^\/api\/v1\/chatbot/, '/api/v1');
      return `${serviceUrl}${chatPath}${querySuffix}`;
    }

    // auth-service y storage-service usan URI versioning (/v1/*).
    if (service === 'auth' || service === 'storage') {
      const path = originalUrl.startsWith('/api/')
        ? originalUrl.replace(/^\/api/, '')
        : originalUrl;
      return `${serviceUrl}${path}`;
    }

    const path = originalUrl.startsWith('/api/v1/')
      ? originalUrl.replace(/^\/api\/v1/, '')
      : originalUrl.startsWith('/api/')
        ? originalUrl.replace(/^\/api/, '')
        : originalUrl;
    return `${serviceUrl}${path}`;
  }
}

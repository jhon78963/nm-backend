import {
  CallHandler,
  ExecutionContext,
  Injectable,
  Logger,
  NestInterceptor,
} from '@nestjs/common';
import { Observable, finalize } from 'rxjs';
import { FastifyReply, FastifyRequest } from 'fastify';

import { attachRequestId, REQUEST_ID_HEADER } from '../logging/request-id.util';
import { writeStructuredLog } from '../logging/structured-log.util';

/**
 * LoggingInterceptor — HTTP access log estructurado con request ID.
 * Asigna/propaga X-Request-ID y emite JSON parseable para Loki/CloudWatch.
 */
@Injectable()
export class LoggingInterceptor implements NestInterceptor {
  private readonly logger = new Logger('HTTP');

  intercept(context: ExecutionContext, next: CallHandler): Observable<unknown> {
    const request = context.switchToHttp().getRequest<FastifyRequest>();
    const reply = context.switchToHttp().getResponse<FastifyReply>();
    const requestId = attachRequestId(request);
    const startedAt = Date.now();

    void reply.header(REQUEST_ID_HEADER, requestId);

    return next.handle().pipe(
      finalize(() => {
        writeStructuredLog(this.logger, 'log', {
          event: 'http.request.completed',
          requestId,
          method: request.method,
          path: request.url,
          statusCode: reply.statusCode ?? 200,
          durationMs: Date.now() - startedAt,
          ip: request.ip,
        });
      }),
    );
  }
}

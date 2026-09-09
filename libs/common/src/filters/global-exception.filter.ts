import {
  ArgumentsHost,
  Catch,
  ExceptionFilter,
  HttpException,
  HttpStatus,
  Logger,
} from '@nestjs/common';
import { FastifyReply, FastifyRequest } from 'fastify';

import { writeStructuredLog } from '../logging/structured-log.util';
import { captureServerException } from '../sentry/init-sentry';

/**
 * GlobalExceptionFilter — Equivale al Handler::render() de Laravel.
 * Unifica el formato de error de toda la API y redacta datos sensibles
 * en producción (equivale a SafeLogContextTest de Pest).
 */
@Catch()
export class GlobalExceptionFilter implements ExceptionFilter {
  private readonly logger = new Logger(GlobalExceptionFilter.name);

  catch(exception: unknown, host: ArgumentsHost) {
    const ctx = host.switchToHttp();
    const reply = ctx.getResponse<FastifyReply>();
    const request = ctx.getRequest<FastifyRequest>();

    let status = HttpStatus.INTERNAL_SERVER_ERROR;
    let message: string | string[] = 'Error interno del servidor.';
    let errors: Record<string, string[]> | undefined;

    if (exception instanceof HttpException) {
      status = exception.getStatus();
      const response = exception.getResponse();

      if (typeof response === 'string') {
        message = response;
      } else if (typeof response === 'object' && response !== null) {
        const r = response as Record<string, unknown>;
        message = (r['message'] as string | string[]) ?? message;
        if (Array.isArray(message)) {
          // class-validator devuelve un array de mensajes
          errors = { validation: message as string[] };
          message = 'Error de validación.';
        }
      }
    }

    // En producción no exponer stack traces ni detalles internos
    const isProduction = process.env.NODE_ENV === 'production';
    if (!isProduction && status >= 500) {
      writeStructuredLog(this.logger, 'error', {
        event: 'http.request.failed',
        requestId: request.requestId,
        method: request.method,
        path: request.url,
        statusCode: status,
        error: exception instanceof Error ? exception.message : String(exception),
        stack: exception instanceof Error ? exception.stack : undefined,
      });
    }

    if (status >= 500) {
      captureServerException(exception, {
        statusCode: status,
        method: request.method,
        url: request.url,
      });
    }

    void reply.status(status).send({
      statusCode: status,
      message,
      ...(errors && { errors }),
      ...(request.requestId && { requestId: request.requestId }),
      timestamp: new Date().toISOString(),
      path: request.url,
    });
  }
}

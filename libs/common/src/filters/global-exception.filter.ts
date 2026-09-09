import {
  ArgumentsHost,
  Catch,
  ExceptionFilter,
  HttpException,
  HttpStatus,
  Logger,
} from '@nestjs/common';
import { FastifyReply, FastifyRequest } from 'fastify';

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
      this.logger.error(
        `${request.method} ${request.url} → ${status}`,
        exception instanceof Error ? exception.stack : String(exception),
      );
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
      timestamp: new Date().toISOString(),
      path: request.url,
    });
  }
}

import { Logger } from '@nestjs/common';

export type StructuredLogLevel = 'log' | 'warn' | 'error' | 'debug';

export type StructuredLogPayload = Record<string, unknown> & {
  event: string;
};

export function writeStructuredLog(
  logger: Logger,
  level: StructuredLogLevel,
  payload: StructuredLogPayload,
): void {
  const entry = {
    timestamp: new Date().toISOString(),
    level,
    service: process.env.SERVICE_NAME?.trim() || 'nm-backend',
    ...payload,
  };

  logger[level](JSON.stringify(entry));
}

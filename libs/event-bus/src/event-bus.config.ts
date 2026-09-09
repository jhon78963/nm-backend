import { ConfigService } from '@nestjs/config';

export function resolveEventBusRedisUrl(config: ConfigService): string {
  return config.get<string>('REDIS_URL', 'redis://localhost:6379');
}

export function isEventBusEnabled(config: ConfigService): boolean {
  const value = config.get<string>('EVENT_BUS_ENABLED');
  if (value === undefined || value === '') {
    return true;
  }

  return value !== 'false';
}

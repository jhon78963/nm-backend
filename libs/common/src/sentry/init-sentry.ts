import * as Sentry from '@sentry/node';

function parseSampleRate(raw: string | undefined, fallback: number): number {
  if (raw === undefined || raw.trim() === '') {
    return fallback;
  }

  const parsed = Number(raw);
  if (!Number.isFinite(parsed) || parsed < 0 || parsed > 1) {
    return fallback;
  }

  return parsed;
}

export function initSentry(): boolean {
  const dsn = process.env.SENTRY_DSN?.trim();
  if (!dsn || process.env.SENTRY_ENABLED === 'false') {
    return false;
  }

  Sentry.init({
    dsn,
    environment:
      process.env.SENTRY_ENVIRONMENT?.trim() ||
      process.env.NODE_ENV ||
      'development',
    release: process.env.SENTRY_RELEASE?.trim() || undefined,
    tracesSampleRate: parseSampleRate(process.env.SENTRY_TRACES_SAMPLE_RATE, 0.1),
    initialScope: {
      tags: {
        service: process.env.SERVICE_NAME?.trim() || 'nm-backend',
      },
    },
  });

  return true;
}

export function captureServerException(
  exception: unknown,
  context?: {
    statusCode?: number;
    method?: string;
    url?: string;
  },
): void {
  if (!process.env.SENTRY_DSN?.trim() || process.env.SENTRY_ENABLED === 'false') {
    return;
  }

  Sentry.withScope((scope) => {
    if (context?.statusCode !== undefined) {
      scope.setTag('http.status_code', String(context.statusCode));
    }

    if (context?.method || context?.url) {
      scope.setContext('request', {
        method: context.method,
        url: context.url,
      });
    }

    if (exception instanceof Error) {
      Sentry.captureException(exception);
      return;
    }

    Sentry.captureException(new Error(String(exception)));
  });
}

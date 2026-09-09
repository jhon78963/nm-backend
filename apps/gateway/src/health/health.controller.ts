import { Controller, Get } from '@nestjs/common';
import { ApiTags, ApiOperation } from '@nestjs/swagger';
import { ConfigService } from '@nestjs/config';
import { Public } from '@app/common/decorators/public.decorator';

interface ServiceTarget {
  name: string;
  url: string;
  path: string;
}

@ApiTags('Health')
@Controller('health')
export class HealthController {
  private readonly services: ServiceTarget[];

  constructor(config: ConfigService) {
    this.services = [
      { name: 'auth',      url: config.get('AUTH_SERVICE_URL',      'http://localhost:3001'), path: '/health' },
      { name: 'catalog',   url: config.get('CATALOG_SERVICE_URL',   'http://localhost:3002'), path: '/health' },
      { name: 'inventory', url: config.get('INVENTORY_SERVICE_URL', 'http://localhost:3003'), path: '/health' },
      { name: 'pos',       url: config.get('POS_SERVICE_URL',       'http://localhost:3004'), path: '/health' },
      { name: 'finance',   url: config.get('FINANCE_SERVICE_URL',   'http://localhost:3005'), path: '/health' },
      { name: 'hr',        url: config.get('HR_SERVICE_URL',        'http://localhost:3006'), path: '/health' },
      { name: 'report',    url: config.get('REPORT_SERVICE_URL',    'http://localhost:3007'), path: '/health' },
      { name: 'document',  url: config.get('DOCUMENT_SERVICE_URL',  'http://localhost:3011'), path: '/health' },
      { name: 'storage',   url: config.get('STORAGE_SERVICE_URL',   'http://localhost:3010'), path: '/health' },
      { name: 'mail',      url: config.get('MAIL_SERVICE_URL',      'http://localhost:3013'), path: '/health' },
      { name: 'ecommerce', url: config.get('ECOMMERCE_SERVICE_URL', 'http://localhost:3012'), path: '/health' },
      { name: 'chatbot',   url: config.get('CHATBOT_SERVICE_URL',   'http://localhost:8090'), path: '/health' },
      { name: 'ai-engine', url: config.get('AI_ENGINE_URL',         'http://localhost:3008'), path: '/health' },
      { name: 'invoicing', url: config.get('INVOICING_SERVICE_URL', 'http://localhost:3009'), path: '/api/health' },
    ];
  }

  @Public()
  @Get()
  @ApiOperation({ summary: 'Health check del gateway' })
  check() {
    return {
      status: 'ok',
      timestamp: new Date().toISOString(),
      services: this.services.map(({ name }) => name),
    };
  }

  /**
   * GET /health/services — Verifica la disponibilidad de cada microservicio.
   * Útil para dashboards de monitoreo y CI/CD.
   */
  @Public()
  @Get('services')
  @ApiOperation({ summary: 'Estado de todos los microservicios' })
  async checkServices() {
    const results = await Promise.allSettled(
      this.services.map(async ({ name, url, path }) => {
        const start = Date.now();
        const response = await fetch(`${url}${path}`, {
          signal: AbortSignal.timeout(3000),
        }).catch(() => null);
        return {
          name,
          status: response?.ok ? 'up' : 'down',
          latencyMs: Date.now() - start,
          url,
          path,
        };
      }),
    );

    const services = results.map((r) =>
      r.status === 'fulfilled' ? r.value : { name: 'unknown', status: 'down', latencyMs: 0, url: '', path: '' },
    );

    const allUp = services.every((s) => s.status === 'up');

    return {
      gateway: 'up',
      timestamp: new Date().toISOString(),
      healthy: allUp,
      services,
    };
  }
}

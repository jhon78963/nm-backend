import { Test, TestingModule } from '@nestjs/testing';
import { ConfigService } from '@nestjs/config';

import { UserActionLogWriter } from '@app/common/audit/user-action-log.writer';

import { ProxyService } from './proxy.service';

function buildConfig() {
  return {
    get: jest.fn((key: string, fallback?: string) => {
      const urls: Record<string, string> = {
        AUTH_SERVICE_URL: 'http://auth.test',
        ECOMMERCE_SERVICE_URL: 'http://ecommerce.test',
        REPORT_SERVICE_URL: 'http://report.test',
        POS_SERVICE_URL: 'http://pos.test',
        FINANCE_SERVICE_URL: 'http://finance.test',
      };

      return urls[key] ?? fallback;
    }),
  };
}

describe('ProxyService', () => {
  let service: ProxyService;
  const fetchMock = jest.fn();

  beforeEach(async () => {
    fetchMock.mockReset();
    global.fetch = fetchMock as typeof fetch;

    const module: TestingModule = await Test.createTestingModule({
      providers: [
        ProxyService,
        { provide: ConfigService, useValue: buildConfig() },
        { provide: UserActionLogWriter, useValue: { logSafely: jest.fn() } },
      ],
    }).compile();

    service = module.get(ProxyService);
  });

  describe('resolveService', () => {
    it.each([
      ['/api/v1/ecommerce/orders', 'ecommerce'],
      ['/api/v1/reports/sales/monthly/pdf', 'report'],
      ['/api/v1/dashboard/metrics', 'report'],
      ['/api/v1/sales', 'pos'],
      ['/api/v1/cashflow/daily', 'report'],
      ['/api/v1/cashflow', 'finance'],
      ['/api/v1/auth/login', 'auth'],
    ])('resuelve %s hacia servicio %s', (path, expected) => {
      expect(service.resolveService(path)).toBe(expected);
    });
  });

  describe('forward', () => {
    it('proxifica GET ecommerce al URL interno del servicio', async () => {
      fetchMock.mockResolvedValue({
        status: 200,
        headers: { get: () => 'application/json' },
        text: async () => '{"ok":true}',
      });

      const send = jest.fn().mockResolvedValue(undefined);
      const reply = {
        status: jest.fn().mockReturnThis(),
        header: jest.fn().mockReturnThis(),
        send,
      };

      await service.forward(
        {
          method: 'GET',
          url: '/api/v1/ecommerce/orders/admin',
          headers: { authorization: 'Bearer token' },
          body: undefined,
        } as never,
        reply as never,
      );

      expect(fetchMock).toHaveBeenCalledWith(
        'http://ecommerce.test/ecommerce/orders/admin',
        expect.objectContaining({
          method: 'GET',
          headers: expect.objectContaining({
            authorization: 'Bearer token',
          }),
        }),
      );
      expect(reply.status).toHaveBeenCalledWith(200);
      expect(send).toHaveBeenCalledWith('{"ok":true}');
    });

    it('devuelve 503 cuando el servicio destino no responde', async () => {
      fetchMock.mockRejectedValue(new Error('ECONNREFUSED'));

      const send = jest.fn().mockResolvedValue(undefined);
      const reply = {
        status: jest.fn().mockReturnThis(),
        header: jest.fn().mockReturnThis(),
        send,
      };

      await service.forward(
        {
          method: 'GET',
          url: '/api/v1/reports/sales/daily',
          headers: {},
          body: undefined,
        } as never,
        reply as never,
      );

      expect(reply.status).toHaveBeenCalledWith(503);
      expect(send).toHaveBeenCalledWith(
        expect.objectContaining({
          statusCode: 503,
          message: expect.stringContaining('report'),
        }),
      );
    });
  });
});

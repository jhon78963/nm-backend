import { UnauthorizedException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { ExecutionContext } from '@nestjs/common';

import { MailServiceKeyGuard } from './mail-service-key.guard';

function buildContext(serviceKey?: string): ExecutionContext {
  return {
    switchToHttp: () => ({
      getRequest: () => ({
        headers: serviceKey ? { 'x-service-key': serviceKey } : {},
      }),
    }),
  } as ExecutionContext;
}

describe('MailServiceKeyGuard', () => {
  it('permite acceso con service key válida', () => {
    const guard = new MailServiceKeyGuard({
      get: jest.fn(() => 'secret-key'),
    } as unknown as ConfigService);

    expect(guard.canActivate(buildContext('secret-key'))).toBe(true);
  });

  it('rechaza acceso sin service key', () => {
    const guard = new MailServiceKeyGuard({
      get: jest.fn(() => 'secret-key'),
    } as unknown as ConfigService);

    expect(() => guard.canActivate(buildContext())).toThrow(UnauthorizedException);
  });

  it('rechaza acceso con service key incorrecta', () => {
    const guard = new MailServiceKeyGuard({
      get: jest.fn(() => 'secret-key'),
    } as unknown as ConfigService);

    expect(() => guard.canActivate(buildContext('wrong-key'))).toThrow(
      UnauthorizedException,
    );
  });
});

import { initSentry } from './init-sentry';

describe('initSentry', () => {
  const originalEnv = process.env;

  beforeEach(() => {
    process.env = { ...originalEnv };
    delete process.env.SENTRY_DSN;
    delete process.env.SENTRY_ENABLED;
  });

  afterAll(() => {
    process.env = originalEnv;
  });

  it('no inicializa Sentry sin SENTRY_DSN', () => {
    expect(initSentry()).toBe(false);
  });

  it('no inicializa Sentry cuando SENTRY_ENABLED=false', () => {
    process.env.SENTRY_DSN = 'https://example@sentry.io/1';
    process.env.SENTRY_ENABLED = 'false';

    expect(initSentry()).toBe(false);
  });
});

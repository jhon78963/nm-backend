import { Logger } from '@nestjs/common';

import { writeStructuredLog } from './structured-log.util';

describe('structured-log.util', () => {
  it('serializa payload JSON con metadatos base', () => {
    const logger = {
      log: jest.fn(),
      warn: jest.fn(),
      error: jest.fn(),
      debug: jest.fn(),
    } as unknown as Logger;

    process.env.SERVICE_NAME = 'gateway';

    writeStructuredLog(logger, 'log', {
      event: 'http.request.completed',
      requestId: 'req-1',
      statusCode: 200,
    });

    expect(logger.log).toHaveBeenCalledWith(
      expect.stringContaining('"event":"http.request.completed"'),
    );
    expect(logger.log).toHaveBeenCalledWith(
      expect.stringContaining('"service":"gateway"'),
    );
  });
});

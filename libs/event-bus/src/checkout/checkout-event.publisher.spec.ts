import { ConfigService } from '@nestjs/config';

import { CHECKOUT_EVENT_TYPES } from './checkout-event.constants';
import { CheckoutEventPublisher } from './checkout-event.publisher';
import { EventBusPublisher } from '../event-bus.publisher';

describe('CheckoutEventPublisher', () => {
  it('publica pos.checkout.completed en el canal checkout', async () => {
    const publishCheckoutEvent = jest.fn().mockResolvedValue(undefined);
    const publisher = {
      publishCheckoutEvent,
    } as unknown as EventBusPublisher;
    const service = new CheckoutEventPublisher(publisher);

    await service.publishPosCheckoutCompleted({
      warehouseId: 'wh-1',
      referenceId: 'sale-1',
      referenceLabel: 'V-123',
      totalAmount: 99.9,
      variants: [{ productSizeId: 'ps-1', colorId: 'c-1' }],
    });

    expect(publishCheckoutEvent).toHaveBeenCalledWith(
      CHECKOUT_EVENT_TYPES.POS_COMPLETED,
      'pos-service',
      expect.objectContaining({
        warehouseId: 'wh-1',
        referenceLabel: 'V-123',
      }),
    );
  });
});

describe('EventBusPublisher', () => {
  it('no publica cuando EVENT_BUS_ENABLED=false', async () => {
    const config = {
      get: jest.fn((key: string, defaultValue?: string) => {
        if (key === 'EVENT_BUS_ENABLED') return 'false';
        return defaultValue;
      }),
    } as unknown as ConfigService;

    const publisher = new EventBusPublisher(config);
    await publisher.onModuleInit();

    await publisher.publishCheckoutEvent(
      CHECKOUT_EVENT_TYPES.POS_COMPLETED,
      'pos-service',
      {
        warehouseId: 'wh-1',
        referenceId: 'sale-1',
        referenceLabel: 'V-123',
        variants: [],
      },
    );

    expect(config.get).toHaveBeenCalled();
  });
});

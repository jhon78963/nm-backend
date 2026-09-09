import { ConfigService } from '@nestjs/config';

import { MailClientService } from '@app/mail-client';

import { LowStockAlertsService } from './low-stock-alerts.service';

describe('LowStockAlertsService', () => {
  const mockDb = {
    warehouse: { findMany: jest.fn() },
    inventoryBalance: { findMany: jest.fn() },
    inventoryLowStockAlert: { findMany: jest.fn(), upsert: jest.fn() },
  };

  const mockMailClient = {
    sendEcommerceMail: jest.fn().mockResolvedValue(undefined),
  };

  const mockConfig = {
    get: jest.fn((key: string, defaultValue?: string) => {
      const values: Record<string, string> = {
        LOW_STOCK_ALERTS_ENABLED: 'true',
        LOW_STOCK_THRESHOLD: '5',
        LOW_STOCK_ALERT_COOLDOWN_HOURS: '24',
        MAIL_LOW_STOCK_ALERT_EMAIL: 'inventario@test.com',
        ERP_PANEL_URL: 'http://erp.test',
      };
      return values[key] ?? defaultValue;
    }),
  };

  let service: LowStockAlertsService;

  beforeEach(() => {
    jest.clearAllMocks();
    service = new LowStockAlertsService(
      mockDb as never,
      mockMailClient as unknown as MailClientService,
      mockConfig as unknown as ConfigService,
    );
  });

  it('envía digest cuando hay variantes bajo umbral sin cooldown reciente', async () => {
    mockDb.warehouse.findMany.mockResolvedValue([{ id: 'wh-1', name: 'Tienda Lima' }]);
    mockDb.inventoryBalance.findMany.mockResolvedValue([
      {
        warehouseId: 'wh-1',
        productSizeId: 'ps-1',
        colorId: 'c-1',
        quantity: 2,
        warehouse: { name: 'Tienda Lima' },
        productSize: {
          product: { name: 'Polo' },
          size: { description: 'M' },
        },
        color: { description: 'Azul' },
      },
    ]);
    mockDb.inventoryLowStockAlert.findMany.mockResolvedValue([]);

    await service.runDailyDigest();

    expect(mockMailClient.sendEcommerceMail).toHaveBeenCalledWith(
      expect.objectContaining({
        to: 'inventario@test.com',
        template: 'inventory.low-stock',
      }),
    );
    expect(mockDb.inventoryLowStockAlert.upsert).toHaveBeenCalled();
  });

  it('omite envío si todas las variantes están en cooldown', async () => {
    mockDb.inventoryBalance.findMany.mockResolvedValue([
      {
        warehouseId: 'wh-1',
        productSizeId: 'ps-1',
        colorId: 'c-1',
        quantity: 1,
        warehouse: { name: 'Tienda Lima' },
        productSize: {
          product: { name: 'Polo' },
          size: { description: 'M' },
        },
        color: { description: 'Azul' },
      },
    ]);
    mockDb.inventoryLowStockAlert.findMany.mockResolvedValue([
      {
        warehouseId: 'wh-1',
        productSizeId: 'ps-1',
        colorId: 'c-1',
        lastNotifiedAt: new Date(),
      },
    ]);

    await service.checkAfterSale(
      'wh-1',
      [{ productSizeId: 'ps-1', colorId: 'c-1' }],
      { source: 'pos_sale', referenceLabel: 'V-123' },
    );

    expect(mockMailClient.sendEcommerceMail).not.toHaveBeenCalled();
  });
});

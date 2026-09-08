import { Test, TestingModule } from '@nestjs/testing';
import { InventoryController } from './inventory.controller';
import { InventoryBalanceService } from './inventory-balance.service';
import { StockAdjustmentDto } from './dto/stock-adjustment.dto';
import type { AuthenticatedUser } from '@app/common/types/authenticated-user.type';

const mockUser: AuthenticatedUser = {
  id: 'user-uuid',
  username: 'admin',
  tenantId: 'tenant-uuid',
  warehouseId: 'warehouse-uuid',
  roles: ['Admin'],
  permissions: [],
  mustChangePassword: false,
};

const mockBalance = {
  id: 'balance-uuid',
  warehouseId: 'warehouse-uuid',
  productSizeId: 'ps-uuid',
  colorId: 'color-uuid',
  quantity: 20,
  productSize: {
    product: { id: 'product-uuid', name: 'Polo básico' },
    size: { id: 'size-uuid', description: 'M' },
  },
  color: { id: 'color-uuid', description: 'Rojo', hash: '#FF0000' },
};

const mockAdjustedBalance = { ...mockBalance, quantity: 17 };

describe('InventoryController', () => {
  let controller: InventoryController;
  let service: jest.Mocked<InventoryBalanceService>;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [InventoryController],
      providers: [
        {
          provide: InventoryBalanceService,
          useValue: {
            getStockSummary: jest.fn(),
            adjust: jest.fn(),
          },
        },
      ],
    }).compile();

    controller = module.get<InventoryController>(InventoryController);
    service = module.get(InventoryBalanceService);
  });

  it('should be defined', () => {
    expect(controller).toBeDefined();
  });

  describe('getStockSummary', () => {
    it('should return full stock for warehouse without product filter', async () => {
      service.getStockSummary.mockResolvedValue([mockBalance] as any);
      const result = await controller.getStockSummary(mockUser, undefined);
      expect(service.getStockSummary).toHaveBeenCalledWith('warehouse-uuid', undefined);
      expect(result).toHaveLength(1);
      expect(result[0]).toMatchObject({ quantity: 20 });
    });

    it('should forward productId filter to service', async () => {
      service.getStockSummary.mockResolvedValue([mockBalance] as any);
      await controller.getStockSummary(mockUser, 'product-uuid');
      expect(service.getStockSummary).toHaveBeenCalledWith('warehouse-uuid', 'product-uuid');
    });
  });

  describe('adjust', () => {
    it('should apply stock adjustment and return updated balance (201)', async () => {
      const dto: StockAdjustmentDto = {
        warehouseId: 'warehouse-uuid',
        productSizeId: 'ps-uuid',
        colorId: 'color-uuid',
        delta: -3,
      };
      service.adjust.mockResolvedValue(mockAdjustedBalance as any);
      const result = await controller.adjust(dto, mockUser);
      expect(service.adjust).toHaveBeenCalledWith({
        warehouseId:   'warehouse-uuid',
        productSizeId: 'ps-uuid',
        colorId:       'color-uuid',
        delta:         -3,
        movementType:  'ADJUSTMENT',
        referenceId:   undefined,
        referenceType: undefined,
        createdById:   'user-uuid',
      });
      expect(result).toMatchObject({ quantity: 17 });
    });

    it('should use provided movementType when specified', async () => {
      const dto: StockAdjustmentDto = {
        warehouseId: 'warehouse-uuid',
        productSizeId: 'ps-uuid',
        colorId: 'color-uuid',
        delta: 5,
        movementType: 'RETURN',
        referenceId: 'sale-uuid',
        referenceType: 'Sale',
      };
      service.adjust.mockResolvedValue({ ...mockBalance, quantity: 25 } as any);
      await controller.adjust(dto, mockUser);
      expect(service.adjust).toHaveBeenCalledWith(
        expect.objectContaining({ movementType: 'RETURN', referenceId: 'sale-uuid' }),
      );
    });
  });
});

import { Test, TestingModule } from '@nestjs/testing';
import { KardexController } from './kardex.controller';
import { KardexService } from './kardex.service';
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

const mockMovementRow = {
  id: 'movement-uuid',
  date: new Date('2026-08-25T10:00:00Z'),
  product: { id: 'product-uuid', name: 'Polo básico', barcode: '1234567890' },
  size: { id: 'size-uuid', description: 'M' },
  color: { id: 'color-uuid', description: 'Rojo', hash: '#FF0000' },
  direction: 'IN',
  quantity: 10,
  movementType: 'PURCHASE',
  balanceAfter: 10,
  referenceType: 'Purchase',
  referenceId: 'purchase-uuid',
  registeredBy: { id: 'user-uuid', username: 'admin' },
};

const mockKardexResult = {
  data: [mockMovementRow],
  meta: { total: 1, page: 1, perPage: 50, lastPage: 1 },
};

const mockSnapshot = [
  {
    id: 'balance-uuid',
    quantity: 10,
    productSize: {
      size: { id: 'size-uuid', description: 'M' },
    },
    color: { id: 'color-uuid', description: 'Rojo', hash: '#FF0000' },
  },
];

describe('KardexController', () => {
  let controller: KardexController;
  let service: jest.Mocked<KardexService>;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [KardexController],
      providers: [
        {
          provide: KardexService,
          useValue: {
            getKardex: jest.fn(),
            getProductStockSnapshot: jest.fn(),
          },
        },
      ],
    }).compile();

    controller = module.get<KardexController>(KardexController);
    service = module.get(KardexService);
  });

  it('should be defined', () => {
    expect(controller).toBeDefined();
  });

  describe('getKardex', () => {
    it('should return paginated kardex for warehouse without filters', async () => {
      service.getKardex.mockResolvedValue(mockKardexResult as any);
      const result = await controller.getKardex(mockUser);
      expect(service.getKardex).toHaveBeenCalledWith({
        warehouseId: 'warehouse-uuid',
        productId:    undefined,
        productSizeId: undefined,
        colorId:      undefined,
        movementType: undefined,
        dateFrom:     undefined,
        dateTo:       undefined,
        page:         1,
        perPage:      50,
      });
      expect(result).toMatchObject({ meta: { total: 1 } });
      expect(result.data[0]).toMatchObject({ movementType: 'PURCHASE', direction: 'IN' });
    });

    it('should forward all optional filters to service', async () => {
      service.getKardex.mockResolvedValue(mockKardexResult as any);
      await controller.getKardex(
        mockUser,
        'product-uuid',
        'ps-uuid',
        'color-uuid',
        'PURCHASE',
        '2026-08-01',
        '2026-08-31',
        2,
        25,
      );
      expect(service.getKardex).toHaveBeenCalledWith({
        warehouseId:   'warehouse-uuid',
        productId:     'product-uuid',
        productSizeId: 'ps-uuid',
        colorId:       'color-uuid',
        movementType:  'PURCHASE',
        dateFrom:      '2026-08-01',
        dateTo:        '2026-08-31',
        page:          2,
        perPage:       25,
      });
    });

    it('should return empty data with correct meta when no movements found', async () => {
      service.getKardex.mockResolvedValue({ data: [], meta: { total: 0, page: 1, perPage: 50, lastPage: 0 } } as any);
      const result = await controller.getKardex(mockUser);
      expect(result.data).toHaveLength(0);
      expect(result.meta.total).toBe(0);
    });
  });

  describe('getSnapshot', () => {
    it('should return stock snapshot for a product', async () => {
      service.getProductStockSnapshot.mockResolvedValue(mockSnapshot as any);
      const result = await controller.getSnapshot('product-uuid', mockUser);
      expect(service.getProductStockSnapshot).toHaveBeenCalledWith('product-uuid', 'warehouse-uuid');
      expect(result).toHaveLength(1);
      expect(result[0]).toMatchObject({ quantity: 10 });
    });
  });
});

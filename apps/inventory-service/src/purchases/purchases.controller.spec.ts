import { Test, TestingModule } from '@nestjs/testing';
import {
  NotFoundException,
  BadRequestException,
} from '@nestjs/common';
import { PurchasesController } from './purchases.controller';
import { PurchasesService } from './purchases.service';
import { RegisterBulkPurchaseDto, PurchaseCurrency } from './dto/register-bulk-purchase.dto';
import { CancelPurchaseDto } from './dto/cancel-purchase.dto';
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

const mockPurchaseSummary = {
  id: 'purchase-uuid',
  warehouseId: 'warehouse-uuid',
  vendorId: 'vendor-uuid',
  supplierName: null,
  currency: 'PEN',
  totalAmount: 500,
  status: 'ACTIVE',
  purchaseDate: new Date('2026-08-25'),
  vendor: { id: 'vendor-uuid', name: 'Distribuidora Norte' },
  _count: { lines: 3 },
};

const mockPurchaseDetail = {
  ...mockPurchaseSummary,
  lines: [
    {
      id: 'line-uuid',
      productId: 'product-uuid',
      sizeId: 'size-uuid',
      purchasePrice: 50,
      salePrice: 100,
      quantity: 10,
      hasColorBreakdown: false,
      product: { id: 'product-uuid', name: 'Polo básico' },
      size: { id: 'size-uuid', description: 'M' },
      colorDeltas: [],
    },
  ],
};

const mockPaginatedResult = {
  data: [mockPurchaseSummary],
  meta: { total: 1, page: 1, perPage: 20, lastPage: 1 },
};

describe('PurchasesController', () => {
  let controller: PurchasesController;
  let service: jest.Mocked<PurchasesService>;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [PurchasesController],
      providers: [
        {
          provide: PurchasesService,
          useValue: {
            findAll: jest.fn(),
            findById: jest.fn(),
            registerBulk: jest.fn(),
            cancel: jest.fn(),
          },
        },
      ],
    }).compile();

    controller = module.get<PurchasesController>(PurchasesController);
    service = module.get(PurchasesService);
  });

  it('should be defined', () => {
    expect(controller).toBeDefined();
  });

  describe('findAll', () => {
    it('should return paginated purchases for the warehouse', async () => {
      service.findAll.mockResolvedValue(mockPaginatedResult as any);
      const result = await controller.findAll(mockUser, 1, 20);
      expect(service.findAll).toHaveBeenCalledWith('warehouse-uuid', 1, 20);
      expect(result).toMatchObject({ meta: { total: 1, page: 1 } });
    });

    it('should use page and perPage query params', async () => {
      service.findAll.mockResolvedValue({ ...mockPaginatedResult, meta: { total: 50, page: 2, perPage: 10, lastPage: 5 } } as any);
      await controller.findAll(mockUser, 2, 10);
      expect(service.findAll).toHaveBeenCalledWith('warehouse-uuid', 2, 10);
    });
  });

  describe('findById', () => {
    it('should return full purchase detail', async () => {
      service.findById.mockResolvedValue(mockPurchaseDetail as any);
      const result = await controller.findById('purchase-uuid');
      expect(service.findById).toHaveBeenCalledWith('purchase-uuid');
      expect(result).toMatchObject({ status: 'ACTIVE' });
      expect(result.lines).toHaveLength(1);
    });

    it('should propagate NotFoundException', async () => {
      service.findById.mockRejectedValue(new NotFoundException());
      await expect(controller.findById('bad-id')).rejects.toBeInstanceOf(NotFoundException);
    });
  });

  describe('registerBulk', () => {
    it('should register bulk purchase and return 201', async () => {
      const dto: RegisterBulkPurchaseDto = {
        warehouseId: 'warehouse-uuid',
        vendorId: 'vendor-uuid',
        currency: PurchaseCurrency.PEN,
        lines: [
          {
            productId: 'product-uuid',
            sizeId: 'size-uuid',
            purchasePrice: 50,
            quantity: 10,
          },
        ],
      };
      service.registerBulk.mockResolvedValue(mockPurchaseDetail as any);
      const result = await controller.registerBulk(dto, mockUser);
      expect(service.registerBulk).toHaveBeenCalledWith(dto, 'user-uuid');
      expect(result).toMatchObject({ status: 'ACTIVE' });
    });

    it('should propagate BadRequestException when colorDeltas total mismatch', async () => {
      const dto: RegisterBulkPurchaseDto = {
        warehouseId: 'warehouse-uuid',
        lines: [{ productId: 'p', sizeId: 's', purchasePrice: 10, quantity: 5, colorDeltas: [{ colorId: 'c', quantity: 3 }] }],
      };
      service.registerBulk.mockRejectedValue(new BadRequestException());
      await expect(controller.registerBulk(dto, mockUser)).rejects.toBeInstanceOf(BadRequestException);
    });
  });

  describe('cancel', () => {
    it('should cancel purchase and return updated record', async () => {
      const dto: CancelPurchaseDto = { reason: 'Error en precios' };
      const cancelled = { ...mockPurchaseSummary, status: 'CANCELLED', cancelReason: 'Error en precios' };
      service.cancel.mockResolvedValue(cancelled as any);
      const result = await controller.cancel('purchase-uuid', dto, mockUser);
      expect(service.cancel).toHaveBeenCalledWith('purchase-uuid', 'Error en precios', 'user-uuid');
      expect(result).toMatchObject({ status: 'CANCELLED' });
    });

    it('should propagate NotFoundException for non-existing purchase', async () => {
      service.cancel.mockRejectedValue(new NotFoundException());
      await expect(
        controller.cancel('bad-id', { reason: 'test' }, mockUser),
      ).rejects.toBeInstanceOf(NotFoundException);
    });

    it('should propagate BadRequestException when already cancelled', async () => {
      service.cancel.mockRejectedValue(new BadRequestException());
      await expect(
        controller.cancel('purchase-uuid', { reason: 'dupe' }, mockUser),
      ).rejects.toBeInstanceOf(BadRequestException);
    });
  });
});

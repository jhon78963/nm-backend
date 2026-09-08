import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { VendorsController } from './vendors.controller';
import { VendorsService } from './vendors.service';
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

const mockVendor = {
  id: 'vendor-uuid',
  name: 'Distribuidora Norte S.A.C.',
  address: 'Av. Industrial 123',
  local: 'Tienda 1',
  balance: 0,
  phone: '999888777',
  warehouseId: 'warehouse-uuid',
  isDeleted: false,
};

describe('VendorsController', () => {
  let controller: VendorsController;
  let service: jest.Mocked<VendorsService>;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [VendorsController],
      providers: [
        {
          provide: VendorsService,
          useValue: {
            findAll: jest.fn(),
            findById: jest.fn(),
            create: jest.fn(),
            update: jest.fn(),
            remove: jest.fn(),
          },
        },
      ],
    }).compile();

    controller = module.get<VendorsController>(VendorsController);
    service = module.get(VendorsService);
  });

  it('should be defined', () => {
    expect(controller).toBeDefined();
  });

  describe('findAll', () => {
    it('should return paginated vendors for the authenticated warehouse', async () => {
      const paginated = {
        data: [mockVendor],
        paginate: { total: 1, pages: 1 },
      };
      service.findAll.mockResolvedValue(paginated as any);
      const result = await controller.findAll(mockUser, { page: '1', limit: '10' });
      expect(service.findAll).toHaveBeenCalledWith('warehouse-uuid', { page: '1', limit: '10' });
      expect(result).toEqual(paginated);
    });

    it('should forward search param to service', async () => {
      service.findAll.mockResolvedValue({ data: [], paginate: { total: 0, pages: 0 } });
      await controller.findAll(mockUser, { page: '1', limit: '10', search: 'Norte' });
      expect(service.findAll).toHaveBeenCalledWith('warehouse-uuid', {
        page: '1',
        limit: '10',
        search: 'Norte',
      });
    });
  });

  describe('findById', () => {
    it('should return a single vendor', async () => {
      service.findById.mockResolvedValue(mockVendor as any);
      const result = await controller.findById('vendor-uuid');
      expect(result).toEqual(mockVendor);
    });

    it('should propagate NotFoundException', async () => {
      service.findById.mockRejectedValue(new NotFoundException());
      await expect(controller.findById('bad-id')).rejects.toBeInstanceOf(NotFoundException);
    });
  });

  describe('create', () => {
    it('should create and return new vendor with status 201', async () => {
      const dto = {
        name: 'Distribuidora Norte S.A.C.',
        address: 'Av. Industrial 123',
        local: 'Galería 1',
        phone: '999888777',
      };
      const response = { message: 'Proveedor creado correctamente.', data: mockVendor };
      service.create.mockResolvedValue(response as any);
      const result = await controller.create(dto, mockUser);
      expect(service.create).toHaveBeenCalledWith(dto, 'warehouse-uuid');
      expect(result).toEqual(response);
    });
  });

  describe('update', () => {
    it('should update vendor data', async () => {
      const response = { message: 'Proveedor actualizado correctamente.' };
      service.update.mockResolvedValue(response as any);
      const result = await controller.update('vendor-uuid', {
        name: 'Distribuidora Norte S.A.C.',
        phone: '111222333',
        local: 'Galería 2',
      });
      expect(service.update).toHaveBeenCalledWith('vendor-uuid', {
        name: 'Distribuidora Norte S.A.C.',
        phone: '111222333',
        local: 'Galería 2',
      });
      expect(result).toEqual(response);
    });

    it('should propagate NotFoundException', async () => {
      service.update.mockRejectedValue(new NotFoundException());
      await expect(controller.update('bad-id', {})).rejects.toBeInstanceOf(NotFoundException);
    });
  });

  describe('remove', () => {
    it('should call remove and return undefined (204)', async () => {
      service.remove.mockResolvedValue(undefined);
      const result = await controller.remove('vendor-uuid');
      expect(service.remove).toHaveBeenCalledWith('vendor-uuid');
      expect(result).toBeUndefined();
    });

    it('should propagate NotFoundException', async () => {
      service.remove.mockRejectedValue(new NotFoundException());
      await expect(controller.remove('bad-id')).rejects.toBeInstanceOf(NotFoundException);
    });
  });
});

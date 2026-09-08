import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { CustomersController } from './customers.controller';
import { CustomersService } from './customers.service';
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

const mockCustomer = {
  id: 'customer-uuid',
  name: 'María García',
  documentType: 'DNI',
  documentNumber: '87654321',
  warehouseId: 'warehouse-uuid',
  isDeleted: false,
};

describe('CustomersController', () => {
  let controller: CustomersController;
  let service: jest.Mocked<CustomersService>;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [CustomersController],
      providers: [
        {
          provide: CustomersService,
          useValue: {
            findAll: jest.fn(),
            findById: jest.fn(),
            create: jest.fn(),
            update: jest.fn(),
            remove: jest.fn(),
            searchForPos: jest.fn(),
          },
        },
      ],
    }).compile();

    controller = module.get<CustomersController>(CustomersController);
    service = module.get(CustomersService);
  });

  it('should be defined', () => {
    expect(controller).toBeDefined();
  });

  describe('findAll', () => {
    it('should return paginated customers for the authenticated warehouse', async () => {
      const paginated = {
        data: [mockCustomer],
        paginate: { total: 1, pages: 1 },
      };
      service.findAll.mockResolvedValue(paginated as any);
      const result = await controller.findAll(mockUser, { page: '1', limit: '10' });
      expect(service.findAll).toHaveBeenCalledWith('warehouse-uuid', { page: '1', limit: '10' });
      expect(result).toEqual(paginated);
    });

    it('should forward search param to service', async () => {
      service.findAll.mockResolvedValue({ data: [], paginate: { total: 0, pages: 0 } });
      await controller.findAll(mockUser, { page: '1', limit: '10', search: 'María' });
      expect(service.findAll).toHaveBeenCalledWith('warehouse-uuid', {
        page: '1',
        limit: '10',
        search: 'María',
      });
    });
  });

  describe('searchForPos', () => {
    it('should return a single customer for POS search', async () => {
      const posCustomer = {
        id: 'customer-uuid',
        dni: '87654321',
        name: 'María García',
        document_type: 'DNI',
        document_number: '87654321',
      };
      service.searchForPos.mockResolvedValue(posCustomer as any);
      const result = await controller.searchForPos(mockUser, '87654321');
      expect(service.searchForPos).toHaveBeenCalledWith('87654321', 'warehouse-uuid');
      expect(result).toEqual(posCustomer);
    });
  });

  describe('findById', () => {
    it('should return a single customer', async () => {
      service.findById.mockResolvedValue(mockCustomer as any);
      const result = await controller.findById('customer-uuid');
      expect(result).toEqual(mockCustomer);
    });

    it('should propagate NotFoundException', async () => {
      service.findById.mockRejectedValue(new NotFoundException());
      await expect(controller.findById('bad-id')).rejects.toBeInstanceOf(NotFoundException);
    });
  });

  describe('create', () => {
    it('should create and return new customer', async () => {
      const dto = { dni: '87654321', name: 'María', surname: 'García' };
      const response = { message: 'Cliente creado correctamente.', data: mockCustomer };
      service.create.mockResolvedValue(response as any);
      const result = await controller.create(dto, mockUser);
      expect(service.create).toHaveBeenCalledWith(dto, 'warehouse-uuid');
      expect(result).toEqual(response);
    });
  });

  describe('update', () => {
    it('should update customer data', async () => {
      const response = { message: 'Cliente actualizado correctamente.' };
      service.update.mockResolvedValue(response as any);
      const result = await controller.update('customer-uuid', {
        dni: '87654321',
        name: 'María',
        surname: 'Updated',
      });
      expect(service.update).toHaveBeenCalledWith('customer-uuid', {
        dni: '87654321',
        name: 'María',
        surname: 'Updated',
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
      const result = await controller.remove('customer-uuid');
      expect(service.remove).toHaveBeenCalledWith('customer-uuid');
      expect(result).toBeUndefined();
    });

    it('should propagate NotFoundException', async () => {
      service.remove.mockRejectedValue(new NotFoundException());
      await expect(controller.remove('bad-id')).rejects.toBeInstanceOf(NotFoundException);
    });
  });
});

import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException, ConflictException } from '@nestjs/common';
import { TeamsController } from './teams.controller';
import { TeamsService } from './teams.service';
import { CreateTeamDto } from './dto/create-team.dto';
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

const mockTeam = {
  id: 'team-uuid',
  name: 'Juan',
  surname: 'Pérez',
  dni: '12345678',
  salary: 1200,
  warehouseId: 'warehouse-uuid',
  isDeleted: false,
  user: null,
  _count: { attendances: 5, payments: 2 },
};

describe('TeamsController', () => {
  let controller: TeamsController;
  let service: jest.Mocked<TeamsService>;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [TeamsController],
      providers: [
        {
          provide: TeamsService,
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

    controller = module.get<TeamsController>(TeamsController);
    service = module.get(TeamsService);
  });

  it('should be defined', () => {
    expect(controller).toBeDefined();
  });

  describe('findAll', () => {
    it('should return paginated list of team members for authenticated warehouse', async () => {
      const paginated = {
        data: [mockTeam],
        paginate: { total: 1, pages: 1 },
      };
      service.findAll.mockResolvedValue(paginated as any);
      const result = await controller.findAll(mockUser, { page: '1', limit: '10' });
      expect(service.findAll).toHaveBeenCalledWith('warehouse-uuid', { page: '1', limit: '10' });
      expect(result).toEqual(paginated);
    });
  });

  describe('findById', () => {
    it('should return a single team member by id', async () => {
      service.findById.mockResolvedValue(mockTeam as any);
      const result = await controller.findById('team-uuid');
      expect(service.findById).toHaveBeenCalledWith('team-uuid');
      expect(result).toEqual(mockTeam);
    });

    it('should propagate NotFoundException when member does not exist', async () => {
      service.findById.mockRejectedValue(new NotFoundException());
      await expect(controller.findById('nonexistent')).rejects.toBeInstanceOf(NotFoundException);
    });
  });

  describe('create', () => {
    it('should create a team member and return 201', async () => {
      const dto: CreateTeamDto = {
        dni: '12345678',
        name: 'Juan',
        surname: 'Pérez',
        salary: 1200,
        warehouseId: 'warehouse-uuid',
      };
      service.create.mockResolvedValue({ ...mockTeam, ...dto } as any);
      const result = await controller.create(dto);
      expect(service.create).toHaveBeenCalledWith(dto);
      expect(result).toMatchObject({ name: 'Juan' });
    });

    it('should propagate ConflictException when DNI already exists', async () => {
      const dto: CreateTeamDto = {
        dni: '12345678',
        name: 'Juan',
        surname: 'Pérez',
        salary: 1200,
        warehouseId: 'warehouse-uuid',
      };
      service.create.mockRejectedValue(new ConflictException());
      await expect(controller.create(dto)).rejects.toBeInstanceOf(ConflictException);
    });
  });

  describe('update', () => {
    it('should update and return updated team member', async () => {
      const updated = { ...mockTeam, salary: 1500 };
      service.update.mockResolvedValue(updated as any);
      const result = await controller.update('team-uuid', { salary: 1500 });
      expect(service.update).toHaveBeenCalledWith('team-uuid', { salary: 1500 });
      expect(result).toMatchObject({ salary: 1500 });
    });

    it('should propagate NotFoundException for non-existing member', async () => {
      service.update.mockRejectedValue(new NotFoundException());
      await expect(controller.update('bad-id', {})).rejects.toBeInstanceOf(NotFoundException);
    });
  });

  describe('remove', () => {
    it('should call remove and return undefined (204)', async () => {
      service.remove.mockResolvedValue(undefined);
      const result = await controller.remove('team-uuid');
      expect(service.remove).toHaveBeenCalledWith('team-uuid');
      expect(result).toBeUndefined();
    });

    it('should propagate NotFoundException when member does not exist', async () => {
      service.remove.mockRejectedValue(new NotFoundException());
      await expect(controller.remove('bad-id')).rejects.toBeInstanceOf(NotFoundException);
    });
  });
});

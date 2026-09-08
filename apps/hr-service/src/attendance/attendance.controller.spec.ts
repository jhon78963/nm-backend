import { Test, TestingModule } from '@nestjs/testing';
import { AttendanceController } from './attendance.controller';
import { AttendanceService } from './attendance.service';
import { AttendanceStatus, RecordAttendanceDto } from './dto/record-attendance.dto';
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

const mockAttendance = {
  id: 'attendance-uuid',
  teamId: 'team-uuid',
  date: new Date('2026-08-25'),
  status: AttendanceStatus.PUNTUAL,
  checkIn: new Date('2026-08-25T09:00:00'),
  checkOut: null,
  delayMinutes: 0,
  notes: null,
};

const mockDailySummary = [
  {
    id: 'team-uuid',
    name: 'Juan',
    surname: 'Pérez',
    attendance: mockAttendance,
    status: AttendanceStatus.PUNTUAL,
  },
];

const mockMonthlySummary = [
  {
    teamId: 'team-uuid',
    name: 'Juan Pérez',
    month: '2026-08',
    presentDays: 20,
    absentDays: 1,
    totalDelayMinutes: 15,
    attendances: [mockAttendance],
  },
];

describe('AttendanceController', () => {
  let controller: AttendanceController;
  let service: jest.Mocked<AttendanceService>;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [AttendanceController],
      providers: [
        {
          provide: AttendanceService,
          useValue: {
            record: jest.fn(),
            getDailySummary: jest.fn(),
            getByMonth: jest.fn(),
            getByMonthForTeam: jest.fn(),
          },
        },
      ],
    }).compile();

    controller = module.get<AttendanceController>(AttendanceController);
    service = module.get(AttendanceService);
  });

  it('should be defined', () => {
    expect(controller).toBeDefined();
  });

  describe('record', () => {
    it('should record attendance and return 201', async () => {
      const dto: RecordAttendanceDto = {
        teamId: 'team-uuid',
        date: '2026-08-25',
        status: AttendanceStatus.PUNTUAL,
        checkIn: '09:00',
      };
      service.record.mockResolvedValue(mockAttendance as any);
      const result = await controller.record(dto);
      expect(service.record).toHaveBeenCalledWith(dto);
      expect(result).toMatchObject({ status: AttendanceStatus.PUNTUAL });
    });

    it('should handle upsert (update existing attendance)', async () => {
      const dto: RecordAttendanceDto = {
        teamId: 'team-uuid',
        date: '2026-08-25',
        status: AttendanceStatus.TARDE,
        delayMinutes: 10,
      };
      const updated = { ...mockAttendance, status: AttendanceStatus.TARDE, delayMinutes: 10 };
      service.record.mockResolvedValue(updated as any);
      const result = await controller.record(dto);
      expect(result).toMatchObject({ status: AttendanceStatus.TARDE, delayMinutes: 10 });
    });
  });

  describe('getDailySummary', () => {
    it('should return daily attendance summary for the warehouse', async () => {
      service.getDailySummary.mockResolvedValue(mockDailySummary as any);
      const result = await controller.getDailySummary(mockUser, '2026-08-25');
      expect(service.getDailySummary).toHaveBeenCalledWith('warehouse-uuid', '2026-08-25');
      expect(result).toHaveLength(1);
      expect(result[0]).toHaveProperty('status', AttendanceStatus.PUNTUAL);
    });
  });

  describe('getByMonth', () => {
    it('should return monthly attendance summary for warehouse', async () => {
      service.getByMonth.mockResolvedValue(mockMonthlySummary as any);
      const result = await controller.getByMonth(mockUser, '2026-08');
      expect(service.getByMonth).toHaveBeenCalledWith('warehouse-uuid', '2026-08');
      const rows = result as typeof mockMonthlySummary;
      expect(rows).toHaveLength(1);
      expect(rows[0]).toMatchObject({ presentDays: 20, absentDays: 1 });
    });

    it('should return team month records when teamId is provided', async () => {
      const teamMonth = {
        data: {
          '2026-08-25': {
            status: AttendanceStatus.PUNTUAL,
            checkInTime: '08:00',
            checkOutTime: null,
            delayMinutes: 0,
            notes: null,
          },
        },
      };
      service.getByMonthForTeam.mockResolvedValue(teamMonth as any);
      const result = await controller.getByMonth(mockUser, '2026-08', 'team-uuid');
      expect(service.getByMonthForTeam).toHaveBeenCalledWith(
        'warehouse-uuid',
        'team-uuid',
        '2026-08',
      );
      expect(result).toEqual(teamMonth);
    });
  });
});

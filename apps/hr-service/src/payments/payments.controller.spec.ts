import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { PaymentsController } from './payments.controller';
import { PaymentsService } from './payments.service';
import {
  CreatePaymentDto,
  PaymentType,
  PaymentMethod,
} from './dto/create-payment.dto';
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

const mockPayment = {
  id: 'payment-uuid',
  teamId: 'team-uuid',
  type: PaymentType.PAYMENT,
  amount: 1200,
  date: new Date('2026-08-25'),
  payrollPeriod: 'q2',
  accountingMonth: '2026-08',
  paymentMethod: PaymentMethod.CASH,
  cashMovementId: null,
  team: { id: 'team-uuid', name: 'Juan', surname: 'Pérez' },
};

const mockPayroll = {
  success: true,
  data: {
    team: { id: 'team-uuid', name: 'Juan', surname: 'Pérez', dni: '123', salary: 1200 },
    estimates: { estimadoAPagarFinMes: 1200 },
  },
};

describe('PaymentsController', () => {
  let controller: PaymentsController;
  let service: jest.Mocked<PaymentsService>;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [PaymentsController],
      providers: [
        {
          provide: PaymentsService,
          useValue: {
            create: jest.fn(),
            update: jest.fn(),
            remove: jest.fn(),
            getByMonth: jest.fn(),
            getPayrollForTeam: jest.fn(),
          },
        },
      ],
    }).compile();

    controller = module.get<PaymentsController>(PaymentsController);
    service = module.get(PaymentsService);
  });

  it('should be defined', () => {
    expect(controller).toBeDefined();
  });

  describe('getByMonth', () => {
    it('should return payments for the month', async () => {
      service.getByMonth.mockResolvedValue([mockPayment] as any);
      const result = await controller.getByMonth(mockUser, '2026-08');
      expect(service.getByMonth).toHaveBeenCalledWith('warehouse-uuid', '2026-08');
      expect(result).toHaveLength(1);
    });
  });

  describe('getPayroll', () => {
    it('should return payroll view for the team', async () => {
      service.getPayrollForTeam.mockResolvedValue(mockPayroll as any);
      const result = await controller.getPayroll(mockUser, 'team-uuid', '7', '2026', 'full');
      expect(service.getPayrollForTeam).toHaveBeenCalledWith(
        'warehouse-uuid',
        'team-uuid',
        7,
        2026,
        'full',
      );
      expect(result).toMatchObject({ success: true });
    });
  });

  describe('create', () => {
    it('should create a payment and return 201', async () => {
      const dto: CreatePaymentDto = {
        teamId: 'team-uuid',
        type: PaymentType.PAYMENT,
        amount: 1200,
        date: '2026-08-25',
        accountingMonth: '2026-08',
        paymentMethod: PaymentMethod.CASH,
      };
      service.create.mockResolvedValue(mockPayment as any);
      const result = await controller.create(dto, mockUser);
      expect(service.create).toHaveBeenCalledWith(dto, 'user-uuid');
      expect(result).toMatchObject({ type: PaymentType.PAYMENT });
    });

    it('should propagate NotFoundException when team member not found', async () => {
      const dto: CreatePaymentDto = {
        teamId: 'nonexistent',
        type: PaymentType.PAYMENT,
        amount: 1200,
        date: '2026-08-25',
        accountingMonth: '2026-08',
        paymentMethod: PaymentMethod.CASH,
      };
      service.create.mockRejectedValue(new NotFoundException());
      await expect(controller.create(dto, mockUser)).rejects.toBeInstanceOf(NotFoundException);
    });
  });

  describe('update', () => {
    it('should update payment data', async () => {
      const updated = { ...mockPayment, amount: 1500 };
      service.update.mockResolvedValue(updated as any);
      const result = await controller.update('payment-uuid', { amount: 1500 });
      expect(service.update).toHaveBeenCalledWith('payment-uuid', { amount: 1500 });
      expect(result).toMatchObject({ amount: 1500 });
    });

    it('should propagate NotFoundException', async () => {
      service.update.mockRejectedValue(new NotFoundException());
      await expect(controller.update('bad-id', {})).rejects.toBeInstanceOf(NotFoundException);
    });
  });

  describe('remove', () => {
    it('should delete payment and return undefined (204)', async () => {
      service.remove.mockResolvedValue(undefined);
      const result = await controller.remove('payment-uuid');
      expect(service.remove).toHaveBeenCalledWith('payment-uuid');
      expect(result).toBeUndefined();
    });

    it('should propagate NotFoundException', async () => {
      service.remove.mockRejectedValue(new NotFoundException());
      await expect(controller.remove('bad-id')).rejects.toBeInstanceOf(NotFoundException);
    });
  });
});

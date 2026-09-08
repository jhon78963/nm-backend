import { Test, TestingModule } from '@nestjs/testing';
import { ServiceUnavailableException } from '@nestjs/common';
import { AiProxyController } from './ai-proxy.controller';
import { AiProxyService } from './ai-proxy.service';
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

const mockProductContext = {
  product_id: 'product-uuid',
  product_name: 'Polo básico',
  current_cost: 25,
  category: 'Hombre',
  sales_last_month: 12,
  current_stock: 20,
  sale_price: 45,
  can_view_cost: true,
  product_age_days: 90,
  days_since_last_sale: 5,
  total_sales_all_time: 120,
  is_dead_stock: false,
  dead_stock_tier: 'none',
  dead_stock_label: '',
};

const mockPricePrediction = {
  product_id: 'product-uuid',
  suggested_price: 95.5,
  minimum_price: 30,
  expected_margin_increase: 12.5,
  markup_over_cost_percent: 45,
  recommendation_summary: 'Rotación saludable',
};

const mockDemandPrediction = {
  product_id: 'product-uuid',
  projected_sales: 45,
  suggested_purchase_quantity: 25,
};

const mockInventoryReport = {
  success: true,
  data: {
    products: [
      {
        id: 'product-uuid',
        name: 'Polo básico',
        sizes: [],
        ai: {
          suggested_price: 95.5,
          suggested_min_price: 30,
          suggested_purchase_quantity: 10,
          projected_sales: 20,
          is_dead_stock: false,
          price_error: null,
          demand_error: null,
        },
      },
    ],
    horizon_days: 30,
    ai_summary: {
      processed: 1,
      errors: 0,
      dead_stock_count: 0,
    },
  },
};

describe('AiProxyController', () => {
  let controller: AiProxyController;
  let service: jest.Mocked<AiProxyService>;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [AiProxyController],
      providers: [
        {
          provide: AiProxyService,
          useValue: {
            getProductContext: jest.fn(),
            predictPrice: jest.fn(),
            predictDemand: jest.fn(),
            getProductsInventoryReport: jest.fn(),
          },
        },
      ],
    }).compile();

    controller = module.get<AiProxyController>(AiProxyController);
    service = module.get(AiProxyService);
  });

  it('should be defined', () => {
    expect(controller).toBeDefined();
  });

  describe('getProductContext', () => {
    it('should return product context from AI engine', async () => {
      service.getProductContext.mockResolvedValue(mockProductContext as any);
      const result = await controller.getProductContext(mockUser, 'product-uuid');
      expect(service.getProductContext).toHaveBeenCalledWith(
        'product-uuid',
        'warehouse-uuid',
        true,
      );
      expect(result).toMatchObject({ product_id: 'product-uuid', sale_price: 45 });
    });

    it('should propagate ServiceUnavailableException when AI engine is down', async () => {
      service.getProductContext.mockRejectedValue(new ServiceUnavailableException());
      await expect(controller.getProductContext(mockUser, 'product-uuid'))
        .rejects.toBeInstanceOf(ServiceUnavailableException);
    });
  });

  describe('predictPrice', () => {
    it('should forward product_id to service', async () => {
      const body = { product_id: 'product-uuid' };
      service.predictPrice.mockResolvedValue(mockPricePrediction as any);
      const result = await controller.predictPrice(mockUser, body);
      expect(service.predictPrice).toHaveBeenCalledWith(
        'product-uuid',
        'warehouse-uuid',
        true,
      );
      expect(result).toMatchObject({ suggested_price: 95.5 });
    });
  });

  describe('predictDemand', () => {
    it('should forward product_id and horizon_days to service', async () => {
      const body = { product_id: 'product-uuid', horizon_days: 30 };
      service.predictDemand.mockResolvedValue(mockDemandPrediction as any);
      const result = await controller.predictDemand(mockUser, body);
      expect(service.predictDemand).toHaveBeenCalledWith(
        'product-uuid',
        'warehouse-uuid',
        true,
        30,
      );
      expect(result).toMatchObject({ projected_sales: 45 });
    });
  });

  describe('getProductsInventoryReport', () => {
    it('should call service with warehouseId from JWT and return AI report', async () => {
      service.getProductsInventoryReport.mockResolvedValue(mockInventoryReport as any);
      const result = await controller.getProductsInventoryReport(mockUser, '30');
      expect(service.getProductsInventoryReport).toHaveBeenCalledWith(
        'warehouse-uuid',
        30,
        true,
      );
      expect(result).toMatchObject({ success: true });
      expect(result.data.products).toHaveLength(1);
    });
  });
});

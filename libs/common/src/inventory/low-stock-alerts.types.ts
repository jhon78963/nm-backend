export type LowStockAlertSource = 'cron' | 'pos_sale' | 'ecommerce_order';

export type LowStockVariantRef = {
  productSizeId: string;
  colorId: string;
};

export type LowStockAlertItem = {
  warehouseId: string;
  warehouseName: string;
  productSizeId: string;
  colorId: string;
  productName: string;
  sizeLabel: string;
  colorLabel: string;
  quantity: number;
};

export type LowStockAlertContext = {
  source: LowStockAlertSource;
  referenceLabel?: string;
};

export type EcommerceOrderEventSource =
  | 'checkout'
  | 'culqi_charge'
  | 'culqi_webhook'
  | 'admin'
  | 'customer';

export type OrderEventOrder = {
  id: string;
  orderNumber: string;
  email: string;
  customerId: string | null;
  status: string;
  paymentStatus: string;
  shippingAddress: unknown;
  shippingMethodTitle: string;
  paymentMethodTitle: string;
  subtotal: number | { toNumber?: () => number };
  shippingTotal: number | { toNumber?: () => number };
  couponDiscount: number | { toNumber?: () => number };
  total: number | { toNumber?: () => number };
  items: Array<{
    nameSnapshot: string;
    variationLabel?: string | null;
    quantity: number;
    unitPrice: number | { toNumber?: () => number };
    subtotal: number | { toNumber?: () => number };
  }>;
};

export type OrderUpdatedEvent = {
  previous: Pick<OrderEventOrder, 'status' | 'paymentStatus' | 'orderNumber' | 'email' | 'shippingAddress' | 'total'>;
  current: OrderEventOrder;
  source: EcommerceOrderEventSource;
  silent?: boolean;
};

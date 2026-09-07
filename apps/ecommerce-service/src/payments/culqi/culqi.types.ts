export interface CulqiChargeResource {
  id: string;
  amount: number;
  currency_code: string;
  email?: string | null;
  outcome?: {
    type?: string;
    merchant_message?: string;
    user_message?: string;
  };
  metadata?: Record<string, string | number | boolean | null>;
}

export interface CulqiOrderResource {
  id: string;
  amount: number;
  currency_code: string;
  order_number?: string;
  state?: string;
  paid_at?: number | null;
  metadata?: Record<string, string | number | boolean | null>;
}

export interface CulqiEventPayload {
  object?: string;
  id?: string;
  type?: string;
  data?: CulqiChargeResource | CulqiOrderResource | Record<string, unknown> | string;
}

export interface CulqiCreateChargeParams {
  amount: number;
  currencyCode: string;
  email: string;
  sourceId: string;
  metadata?: Record<string, string>;
  description?: string;
}

export interface CulqiCreateOrderParams {
  amount: number;
  currencyCode: string;
  description: string;
  orderNumber: string;
  email: string;
  firstName: string;
  lastName: string;
  phoneNumber: string;
  metadata?: Record<string, string>;
}

export interface CulqiCheckoutSession {
  culqiOrderId: string;
  amountInCentimos: number;
  rsaId: string;
  rsaPublicKey: string;
}

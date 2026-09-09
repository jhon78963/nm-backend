import type { CheckoutEventType } from './checkout-event.constants';

export type CheckoutVariantRef = {
  productSizeId: string;
  colorId: string;
};

export type CheckoutSideEffectEventData = {
  warehouseId: string;
  referenceId: string;
  referenceLabel: string;
  totalAmount?: number;
  variants: CheckoutVariantRef[];
};

export type NmEventEnvelope<TType extends string = string, TData = unknown> = {
  id: string;
  type: TType;
  occurredAt: string;
  source: string;
  data: TData;
};

export type CheckoutEventEnvelope = NmEventEnvelope<
  CheckoutEventType,
  CheckoutSideEffectEventData
>;

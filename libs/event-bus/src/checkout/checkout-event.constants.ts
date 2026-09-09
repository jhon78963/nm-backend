export const CHECKOUT_EVENTS_CHANNEL = 'nm:events:checkout';

export const CHECKOUT_EVENT_TYPES = {
  POS_COMPLETED: 'pos.checkout.completed',
  ECOMMERCE_ORDER_PAID: 'ecommerce.order.paid',
} as const;

export type CheckoutEventType =
  (typeof CHECKOUT_EVENT_TYPES)[keyof typeof CHECKOUT_EVENT_TYPES];

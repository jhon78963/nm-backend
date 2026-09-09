/** Plantillas de correo ecommerce soportadas por mail-service. */
export enum EcommerceMailTemplate {
  CUSTOMER_WELCOME = 'customer.welcome',
  CUSTOMER_PASSWORD_RESET = 'customer.password-reset',
  ORDER_CONFIRMATION = 'order.confirmation',
  ORDER_STATUS_UPDATE = 'order.status-update',
  ORDER_DELIVERED = 'order.delivered',
  ORDER_CANCELLED = 'order.cancelled',
  REFUND_STATUS_UPDATE = 'refund.status-update',
  REVIEW_APPROVED = 'review.approved',
  REVIEW_REJECTED = 'review.rejected',
  ORDER_PAYMENT_RECEIVED = 'order.payment-received',
  NEWSLETTER_SUBSCRIBED = 'newsletter.subscribed',
  NEWSLETTER_CAMPAIGN = 'newsletter.campaign',
  INSTITUTIONAL_INQUIRY = 'institutional.inquiry',
}

export interface OrderMailItem {
  name: string;
  variationLabel?: string;
  quantity: number;
  unitPrice: number;
  subtotal: number;
}

export interface OrderMailAddress {
  firstName?: string;
  lastName?: string;
  address1?: string;
  address2?: string;
  city?: string;
  state?: string;
  postcode?: string;
  country?: string;
  phone?: string;
}

export type EcommerceMailData = {
  [EcommerceMailTemplate.CUSTOMER_WELCOME]: {
    customerName: string;
    storeUrl: string;
    welcomeCouponCode?: string;
    welcomeCouponDescription?: string | null;
    welcomeCouponDiscountType?: 'percentage' | 'fixed';
    welcomeCouponDiscountValue?: number;
  };
  [EcommerceMailTemplate.CUSTOMER_PASSWORD_RESET]: {
    customerName?: string;
    resetUrl: string;
    expiresInMinutes: number;
  };
  [EcommerceMailTemplate.ORDER_CONFIRMATION]: {
    customerName: string;
    orderNumber: string;
    items: OrderMailItem[];
    subtotal: number;
    shippingTotal: number;
    couponDiscount?: number;
    total: number;
    shippingMethodTitle: string;
    paymentMethodTitle: string;
    shippingAddress: OrderMailAddress;
    trackUrl: string;
    storeUrl: string;
  };
  [EcommerceMailTemplate.ORDER_STATUS_UPDATE]: {
    customerName: string;
    orderNumber: string;
    status: string;
    statusLabel: string;
    trackingNumber?: string;
    trackingUrl?: string;
    trackUrl: string;
    storeUrl: string;
  };
  [EcommerceMailTemplate.ORDER_DELIVERED]: {
    customerName: string;
    orderNumber: string;
    reviewUrl: string;
    storeUrl: string;
  };
  [EcommerceMailTemplate.ORDER_CANCELLED]: {
    customerName: string;
    orderNumber: string;
    reason?: string;
    storeUrl: string;
  };
  [EcommerceMailTemplate.REFUND_STATUS_UPDATE]: {
    customerName: string;
    orderNumber: string;
    status: string;
    statusLabel: string;
    amount?: number;
    adminNotes?: string;
    storeUrl: string;
  };
  [EcommerceMailTemplate.REVIEW_APPROVED]: {
    customerName: string;
    productName: string;
    productUrl: string;
    storeUrl: string;
  };
  [EcommerceMailTemplate.REVIEW_REJECTED]: {
    customerName: string;
    productName: string;
    reason?: string;
    storeUrl: string;
  };
  [EcommerceMailTemplate.ORDER_PAYMENT_RECEIVED]: {
    customerName: string;
    orderNumber: string;
    total: number;
    trackUrl: string;
    storeUrl: string;
  };
  [EcommerceMailTemplate.NEWSLETTER_SUBSCRIBED]: {
    storeUrl: string;
  };
  [EcommerceMailTemplate.NEWSLETTER_CAMPAIGN]: {
    title: string;
    body: string;
    previewText?: string;
    ctaUrl?: string;
    ctaLabel?: string;
    storeUrl: string;
  };
  [EcommerceMailTemplate.INSTITUTIONAL_INQUIRY]: {
    formTitle: string;
    customerName: string;
    customerEmail: string;
    customerPhone?: string;
    subject?: string;
    message: string;
    metadata?: Record<string, string>;
    storeUrl: string;
  };
};

export interface SendEcommerceMailPayload<T extends EcommerceMailTemplate = EcommerceMailTemplate> {
  template: T;
  to: string;
  subject?: string;
  data: EcommerceMailData[T];
}

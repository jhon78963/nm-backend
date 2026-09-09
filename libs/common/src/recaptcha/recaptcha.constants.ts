export const RECAPTCHA_ACTIONS = {
  customerRegister: 'customer_register',
  customerLogin: 'customer_login',
  checkoutOrder: 'checkout_order',
  orderTrack: 'order_track',
  newsletterSubscribe: 'newsletter_subscribe',
  contactForm: 'contact_form',
  libroReclamaciones: 'libro_reclamaciones',
} as const;

export type RecaptchaAction = (typeof RECAPTCHA_ACTIONS)[keyof typeof RECAPTCHA_ACTIONS];

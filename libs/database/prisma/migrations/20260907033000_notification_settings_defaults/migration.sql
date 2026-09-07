-- Preferencias de notificación: solo pedidos activos por defecto.
ALTER TABLE "ecommerce_customer_notification_settings"
  ALTER COLUMN "promotions" SET DEFAULT false;

ALTER TABLE "ecommerce_customer_notification_settings"
  ALTER COLUMN "newsletter" SET DEFAULT false;

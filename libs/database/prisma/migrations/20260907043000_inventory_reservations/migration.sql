-- Reservas de stock para pedidos ecommerce con pago pendiente
ALTER TABLE "inventory_balances"
ADD COLUMN IF NOT EXISTS "reserved_quantity" INTEGER NOT NULL DEFAULT 0;

ALTER TABLE "ecommerce_orders"
ADD COLUMN IF NOT EXISTS "stock_reserved_at" TIMESTAMP(3);

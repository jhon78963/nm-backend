ALTER TABLE "ecommerce_orders" ADD COLUMN "culqi_order_id" VARCHAR(64);

CREATE INDEX "ecommerce_orders_culqi_order_id_idx" ON "ecommerce_orders"("culqi_order_id");

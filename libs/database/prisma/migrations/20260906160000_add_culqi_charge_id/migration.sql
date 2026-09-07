ALTER TABLE "ecommerce_orders" ADD COLUMN "culqi_charge_id" VARCHAR(64);

CREATE INDEX "ecommerce_orders_culqi_charge_id_idx" ON "ecommerce_orders"("culqi_charge_id");

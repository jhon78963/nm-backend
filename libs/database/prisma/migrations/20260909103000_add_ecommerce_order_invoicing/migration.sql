ALTER TABLE "ecommerce_orders"
ADD COLUMN "sale_id" UUID,
ADD COLUMN "document_type" VARCHAR(10),
ADD COLUMN "full_invoice_number" VARCHAR(20),
ADD COLUMN "sunat_status" VARCHAR(20);

CREATE INDEX "ecommerce_orders_sale_id_idx" ON "ecommerce_orders"("sale_id");

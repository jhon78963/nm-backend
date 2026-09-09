CREATE TABLE "inventory_low_stock_alerts" (
    "id" UUID NOT NULL,
    "warehouse_id" UUID NOT NULL,
    "product_size_id" UUID NOT NULL,
    "color_id" UUID NOT NULL,
    "last_notified_at" TIMESTAMP(3) NOT NULL,
    "last_quantity" INTEGER NOT NULL,

    CONSTRAINT "inventory_low_stock_alerts_pkey" PRIMARY KEY ("id")
);

CREATE UNIQUE INDEX "inventory_low_stock_alerts_warehouse_id_product_size_id_color_id_key"
ON "inventory_low_stock_alerts"("warehouse_id", "product_size_id", "color_id");

CREATE INDEX "inventory_low_stock_alerts_last_notified_at_idx"
ON "inventory_low_stock_alerts"("last_notified_at");

-- CreateTable
CREATE TABLE "ecommerce_carts" (
    "id" TEXT NOT NULL,
    "customer_id" TEXT NOT NULL,
    "warehouse_id" TEXT NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "ecommerce_carts_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "ecommerce_cart_items" (
    "id" TEXT NOT NULL,
    "cart_id" TEXT NOT NULL,
    "product_id" TEXT NOT NULL,
    "product_size_id" TEXT NOT NULL,
    "color_id" TEXT,
    "quantity" INTEGER NOT NULL,
    "name_snapshot" VARCHAR(255) NOT NULL,
    "variation_label" VARCHAR(255),
    "image_url" VARCHAR(500),
    "unit_price" DECIMAL(12, 2) NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "ecommerce_cart_items_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "ecommerce_carts_customer_id_key" ON "ecommerce_carts"("customer_id");

-- CreateIndex
CREATE INDEX "ecommerce_cart_items_cart_id_idx" ON "ecommerce_cart_items"("cart_id");

-- CreateIndex
CREATE UNIQUE INDEX "ecommerce_cart_items_cart_id_product_size_id_color_id_key" ON "ecommerce_cart_items"("cart_id", "product_size_id", "color_id");

-- AddForeignKey
ALTER TABLE "ecommerce_carts" ADD CONSTRAINT "ecommerce_carts_customer_id_fkey" FOREIGN KEY ("customer_id") REFERENCES "ecommerce_customers"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "ecommerce_cart_items" ADD CONSTRAINT "ecommerce_cart_items_cart_id_fkey" FOREIGN KEY ("cart_id") REFERENCES "ecommerce_carts"("id") ON DELETE CASCADE ON UPDATE CASCADE;

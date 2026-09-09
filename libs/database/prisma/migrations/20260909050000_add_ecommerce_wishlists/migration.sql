-- CreateTable
CREATE TABLE "ecommerce_wishlists" (
    "id" TEXT NOT NULL,
    "customer_id" TEXT NOT NULL,
    "warehouse_id" TEXT NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "ecommerce_wishlists_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "ecommerce_wishlist_items" (
    "id" TEXT NOT NULL,
    "wishlist_id" TEXT NOT NULL,
    "product_id" TEXT NOT NULL,
    "product_size_id" TEXT,
    "color_id" TEXT,
    "name_snapshot" VARCHAR(255) NOT NULL,
    "variation_label" VARCHAR(255),
    "image_url" VARCHAR(500),
    "unit_price" DECIMAL(12, 2) NOT NULL,
    "added_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "ecommerce_wishlist_items_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "ecommerce_wishlists_customer_id_key" ON "ecommerce_wishlists"("customer_id");

-- CreateIndex
CREATE INDEX "ecommerce_wishlist_items_wishlist_id_idx" ON "ecommerce_wishlist_items"("wishlist_id");

-- CreateIndex
CREATE UNIQUE INDEX "ecommerce_wishlist_items_wishlist_id_product_id_key" ON "ecommerce_wishlist_items"("wishlist_id", "product_id");

-- AddForeignKey
ALTER TABLE "ecommerce_wishlists" ADD CONSTRAINT "ecommerce_wishlists_customer_id_fkey" FOREIGN KEY ("customer_id") REFERENCES "ecommerce_customers"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "ecommerce_wishlist_items" ADD CONSTRAINT "ecommerce_wishlist_items_wishlist_id_fkey" FOREIGN KEY ("wishlist_id") REFERENCES "ecommerce_wishlists"("id") ON DELETE CASCADE ON UPDATE CASCADE;

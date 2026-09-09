import { buildProductSlug } from '@app/common/utils/product-slug.util';

export const PLACEHOLDER_PRODUCT_IMAGE_URL = '/placeholder-product.svg';

export interface PublicProductColorItem {
  id: string;
  label: string;
  hex: string;
  stock: number;
}

export interface PublicProductSizeItem {
  id: string;
  label: string;
  stock: number;
  salePrice: number;
  colors: PublicProductColorItem[];
}

export interface PublicProductItem {
  id: string;
  name: string;
  slug: string;
  imageUrl: string;
  galleryImageUrls: string[];
  price: number;
  salePrice: number;
  discount: number;
  stockStatus: 'in_stock' | 'out_of_stock';
  ratingCount: number | null;
  reviewsCount: number;
  shortDescription: string | null;
  description: string | null;
  additionalInfo: string | null;
  barcode: string | null;
  isFeatured: boolean;
  isOnSale: boolean;
  isNew: boolean;
  percentageDiscount: string | null;
  cashDiscount: number | null;
  genderLabel: string | null;
  sizes: PublicProductSizeItem[];
}

export interface PublicProductsResponse {
  products: PublicProductItem[];
}

export interface PublicProductStockColorItem {
  id: string;
  stock: number;
}

export interface PublicProductStockSizeItem {
  id: string;
  stock: number;
  colors: PublicProductStockColorItem[];
}

export interface PublicProductStockResponse {
  productId: string;
  stockStatus: 'in_stock' | 'out_of_stock';
  sizes: PublicProductStockSizeItem[];
}

type StockProductSizeRow = {
  id: string;
  isDeleted: boolean;
  size?: {
    id: string;
    description: string;
    isDeleted: boolean;
  };
  productSizeColors?: ProductSizeColorLink[];
};

export function mapProductStockResponse(
  productSizes: StockProductSizeRow[],
  stockByProductSizeId: Map<string, number>,
  stockByProductSizeColorId: Map<string, number>,
): Omit<PublicProductStockResponse, 'productId'> {
  const sizes = productSizes
    .filter((productSize) => !productSize.isDeleted && productSize.size && !productSize.size.isDeleted)
    .map((productSize) => {
      const colors = (productSize.productSizeColors ?? [])
        .filter((link) => link.color && !link.color.isDeleted)
        .map((link) => ({
          id: link.color.id,
          stock: stockByProductSizeColorId.get(`${productSize.id}:${link.colorId}`) ?? 0,
        }));

      return {
        id: productSize.id,
        stock: stockByProductSizeId.get(productSize.id) ?? 0,
        colors,
      };
    });

  return {
    stockStatus: sizes.some((size) => size.stock > 0 || size.colors.some((color) => color.stock > 0))
      ? 'in_stock'
      : 'out_of_stock',
    sizes,
  };
}

type ProductColorRow = {
  id: string;
  description: string;
  hash: string | null;
  isDeleted: boolean;
};

type ProductSizeColorLink = {
  colorId: string;
  color: ProductColorRow;
};

type ProductSizeRow = {
  id: string;
  salePrice: { toNumber?: () => number } | number | string;
  isDeleted: boolean;
  size?: {
    id: string;
    description: string;
    isDeleted: boolean;
  };
  productSizeColors?: ProductSizeColorLink[];
};

type ProductMediaRow = {
  url: string;
  isCover: boolean;
  sortOrder: number;
};

export type PublicCatalogProduct = {
  id: string;
  name: string;
  description: string | null;
  shortDescription: string | null;
  additionalInfo: string | null;
  barcode: string | null;
  isOnSale: boolean;
  isFeatured: boolean;
  isNew: boolean;
  percentageDiscount: string | null;
  cashDiscount: number | null;
  offerPrice?: { toNumber?: () => number } | number | string | null;
  gender?: { name: string } | null;
  productSizes: ProductSizeRow[];
  media: ProductMediaRow[];
};

export function mapCatalogProductToPublicItem(
  product: PublicCatalogProduct,
  stockByProductSizeId: Map<string, number>,
  stockByProductSizeColorId: Map<string, number> = new Map(),
  reviewStats?: { averageRating: number; reviewsCount: number },
): PublicProductItem {
  const sizes = mapProductSizes(
    product.productSizes,
    stockByProductSizeId,
    stockByProductSizeColorId,
  );
  const offerPrice = resolveOfferPrice(product.offerPrice);
  const sizeSalePrices = sizes.map((size) => size.salePrice).filter((price) => price > 0);

  const price = sizeSalePrices.length > 0 ? Math.max(...sizeSalePrices) : offerPrice ?? 0;
  const salePrice =
    offerPrice ?? (sizeSalePrices.length > 0 ? Math.min(...sizeSalePrices) : 0);
  const displaySizes =
    offerPrice != null
      ? sizes.map((size) => ({ ...size, salePrice: offerPrice }))
      : sizes;
  const discount =
    (product.isOnSale || offerPrice != null) && price > salePrice
      ? Math.round(((price - salePrice) / price) * 100)
      : product.percentageDiscount
        ? Math.max(0, Math.round(Number(product.percentageDiscount)) || 0)
        : 0;

  const galleryImageUrls = product.media
    .slice()
    .sort((a, b) => Number(b.isCover) - Number(a.isCover) || a.sortOrder - b.sortOrder)
    .map((item) => item.url)
    .filter(Boolean);

  const imageUrl = galleryImageUrls[0] ?? PLACEHOLDER_PRODUCT_IMAGE_URL;

  const hasStock = sizes.some((size) => size.stock > 0);

  return {
    id: product.id,
    name: product.name,
    slug: buildProductSlug(product.name, product.id),
    imageUrl,
    galleryImageUrls: galleryImageUrls.length > 0 ? galleryImageUrls : [imageUrl],
    price,
    salePrice,
    discount,
    stockStatus: hasStock ? 'in_stock' : 'out_of_stock',
    ratingCount: reviewStats && reviewStats.reviewsCount > 0 ? reviewStats.averageRating : null,
    reviewsCount: reviewStats?.reviewsCount ?? 0,
    shortDescription: product.shortDescription,
    description: product.description,
    additionalInfo: product.additionalInfo,
    barcode: product.barcode,
    isFeatured: product.isFeatured,
    isOnSale: product.isOnSale,
    isNew: product.isNew,
    percentageDiscount: product.percentageDiscount,
    cashDiscount: product.cashDiscount,
    genderLabel: product.gender?.name ?? null,
    sizes: displaySizes,
  };
}

function resolveOfferPrice(
  value: PublicCatalogProduct['offerPrice'],
): number | null {
  if (value == null) {
    return null;
  }

  const parsed = toNumber(value);
  return parsed > 0 ? parsed : null;
}

function mapProductSizes(
  productSizes: ProductSizeRow[],
  stockByProductSizeId: Map<string, number>,
  stockByProductSizeColorId: Map<string, number>,
): PublicProductSizeItem[] {
  return productSizes
    .filter((productSize) => !productSize.isDeleted && productSize.size && !productSize.size.isDeleted)
    .map((productSize) => {
      const colors = (productSize.productSizeColors ?? [])
        .filter((link) => link.color && !link.color.isDeleted)
        .map((link) => ({
          id: link.color.id,
          label: link.color.description,
          hex: link.color.hash?.trim() || '#CCCCCC',
          stock: stockByProductSizeColorId.get(`${productSize.id}:${link.colorId}`) ?? 0,
        }))
        .sort((a, b) => a.label.localeCompare(b.label, 'es', { numeric: true }));

      return {
        id: productSize.id,
        label: productSize.size!.description,
        stock: stockByProductSizeId.get(productSize.id) ?? 0,
        salePrice: toNumber(productSize.salePrice),
        colors,
      };
    })
    .sort((a, b) => a.label.localeCompare(b.label, 'es', { numeric: true }));
}

function toNumber(value: ProductSizeRow['salePrice']): number {
  if (typeof value === 'number') {
    return value;
  }

  if (typeof value === 'string') {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  if (value && typeof value.toNumber === 'function') {
    return value.toNumber();
  }

  return 0;
}

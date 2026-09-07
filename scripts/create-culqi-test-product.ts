/**
 * Crea o actualiza el producto "test" para pruebas Culqi dev:
 *   - Talla ESTÁNDAR, color Negro, stock 1, precio S/ 10.00
 *
 * Uso (API local en marcha):
 *   cd nm-backend-v3
 *   npx ts-node --project tsconfig.migration.json scripts/create-culqi-test-product.ts
 */
import * as dotenv from 'dotenv';
import jwt from 'jsonwebtoken';

dotenv.config();

const API_BASE_URL = (process.env.API_BASE_URL ?? 'http://localhost:3000/api/v1').replace(/\/$/, '');
const JWT_SECRET = process.env.JWT_SECRET;
if (!JWT_SECRET) {
  throw new Error('Falta JWT_SECRET en .env (debe coincidir con el gateway en Docker).');
}

const WAREHOUSE_ID = process.env.STORE_WAREHOUSE_ID ?? '46ea2f24-30d2-59a3-8790-8670a0105280';
const GENDER_ID = process.env.BULK_IMPORT_GENDER_ID ?? '7e4a0a3d-b04d-5e42-a962-ba8b9343033c';
const SIZE_ID = process.env.BULK_IMPORT_SIZE_ID ?? 'fcf42fb4-6ca3-5f03-b2b6-8fc9ee088a9e';
const COLOR_NEGRO_ID = process.env.BULK_IMPORT_DEFAULT_COLOR_ID ?? '8a704560-ed0d-565e-8cc1-160ffa6369f1';
const USER_ID = process.env.BULK_IMPORT_USER_ID ?? '482677ee-baca-51c8-a57e-409eec50bbba';
const USERNAME = process.env.BULK_IMPORT_USERNAME ?? 'zero';
const TENANT_ID = process.env.ECOMMERCE_TENANT_ID ?? 'b14b2a6d-ff01-57e4-9004-7ece99dc46d9';

const PRODUCT_NAME = 'test';
const SALE_PRICE = 10;
const STOCK = 1;

function createAccessToken(): string {
  return jwt.sign(
    {
      sub: USER_ID,
      username: USERNAME,
      tenantId: TENANT_ID,
      warehouseId: WAREHOUSE_ID,
      roles: ['Admin'],
      permissions: [],
    },
    JWT_SECRET,
    { expiresIn: '1h' },
  );
}

async function api<T>(token: string, method: string, path: string, body?: unknown): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    method,
    headers: {
      Authorization: `Bearer ${token}`,
      ...(body ? { 'Content-Type': 'application/json' } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  const text = await response.text();
  if (!response.ok) {
    throw new Error(`${method} ${path} → ${response.status}: ${text}`);
  }

  return text ? (JSON.parse(text) as T) : (null as T);
}

interface ProductListItem {
  id: string;
  name: string;
  productSizes?: Array<{
    id: string;
    sizeId: string;
    salePrice: number | string;
    size?: { description?: string };
    productSizeColors?: Array<{ colorId: string; color?: { description?: string } }>;
  }>;
}

async function main(): Promise<void> {
  const token = createAccessToken();

  const list = await api<{ data: ProductListItem[] }>(
    token,
    'GET',
    `/products?search=${encodeURIComponent(PRODUCT_NAME)}&perPage=20`,
  );

  let product = list.data?.find((item) => item.name.toLowerCase() === PRODUCT_NAME);

  if (!product) {
    const created = await api<{ productId: string }>(token, 'POST', '/products', {
      name: PRODUCT_NAME,
      shortDescription: 'Producto de prueba Culqi dev',
      genderId: GENDER_ID,
      warehouseId: WAREHOUSE_ID,
      status: 'active',
      wooStatus: 'publish',
      sizes: [
        {
          sizeId: SIZE_ID,
          purchasePrice: 5,
          salePrice: SALE_PRICE,
          colorIds: [COLOR_NEGRO_ID],
        },
      ],
    });
    product = await api<ProductListItem>(token, 'GET', `/products/${created.productId}`);
    console.log(`Producto creado: ${created.productId}`);
  }

  const standardSize = product.productSizes?.find((size) => size.sizeId === SIZE_ID);
  if (!standardSize) {
    throw new Error('El producto test no tiene talla ESTÁNDAR.');
  }

  const hasNegro = standardSize.productSizeColors?.some((psc) => psc.colorId === COLOR_NEGRO_ID);
  if (!hasNegro) {
    await api(token, 'POST', `/product-sizes/${standardSize.id}/colors`, {
      colorId: COLOR_NEGRO_ID,
      initialStock: STOCK,
    });
    console.log('Variante Negro agregada con stock 1.');
  } else {
    await api(token, 'PATCH', `/product-sizes/${standardSize.id}/colors/${COLOR_NEGRO_ID}`, {
      stock: STOCK,
    });
    console.log('Stock Negro actualizado a 1.');
  }

  if (Number(standardSize.salePrice) !== SALE_PRICE) {
    await api(token, 'PATCH', `/products/${product.id}/sizes/${SIZE_ID}`, {
      salePrice: SALE_PRICE,
    });
  }

  console.log('\n✅ Listo para Culqi dev');
  console.log(`   Producto: ${PRODUCT_NAME}`);
  console.log(`   ID:       ${product.id}`);
  console.log(`   Talla:    ESTÁNDAR`);
  console.log(`   Color:    Negro`);
  console.log(`   Stock:    ${STOCK}`);
  console.log(`   Precio:   S/ ${SALE_PRICE.toFixed(2)}`);
  console.log(`   Tienda:   http://localhost:3015 (busca "${PRODUCT_NAME}")`);
}

main().catch((error: unknown) => {
  console.error(error);
  process.exit(1);
});

/**
 * Importa productos de nm-photos/nueva mercaderia vía API.
 *
 * Uso (stack Docker en marcha):
 *   cd nm-backend
 *   npx ts-node --project tsconfig.migration.json scripts/import-nueva-mercaderia.ts
 */
import * as dotenv from 'dotenv';
import * as fs from 'fs';
import * as path from 'path';
import { execFileSync } from 'child_process';
import jwt from 'jsonwebtoken';

dotenv.config();

const USE_PROD_DB_SSH = process.env.PROD_DB_SSH === '1';
const prisma = USE_PROD_DB_SSH ? null : new (require('@prisma/client').PrismaClient)();

const API_BASE_URL = (process.env.API_BASE_URL ?? 'http://localhost:3000/api/v1').replace(/\/$/, '');
const JWT_SECRET =
  process.env.IMPORT_JWT_SECRET ??
  (API_BASE_URL.includes('localhost')
    ? 'CAMBIA_ESTO_POR_UN_SECRET_DE_64_CHARS_MINIMO'
    : process.env.JWT_SECRET);
if (!JWT_SECRET) {
  throw new Error('Falta JWT_SECRET o IMPORT_JWT_SECRET en .env');
}

const PHOTOS_DIR = process.env.PHOTOS_DIR
  ? path.resolve(process.env.PHOTOS_DIR)
  : path.resolve(__dirname, '../../nm-photos/nueva mercaderia');

const PROD_SSH_CONFIG = process.env.PROD_SSH_CONFIG
  ?? path.resolve(__dirname, '../../vps/ssh-config');
const PROD_SSH_HOST = process.env.PROD_SSH_HOST ?? 'nm-prod';

const WAREHOUSE_ID = process.env.STORE_WAREHOUSE_ID ?? '46ea2f24-30d2-59a3-8790-8670a0105280';
const GENDER_ID = process.env.BULK_IMPORT_GENDER_ID ?? '7e4a0a3d-b04d-5e42-a962-ba8b9343033c';
const USER_ID = process.env.BULK_IMPORT_USER_ID ?? '482677ee-baca-51c8-a57e-409eec50bbba';
const USERNAME = process.env.BULK_IMPORT_USERNAME ?? 'zero';
const TENANT_ID = process.env.ECOMMERCE_TENANT_ID ?? 'b14b2a6d-ff01-57e4-9004-7ece99dc46d9';

const DEFAULT_STOCK = Number(process.env.BULK_IMPORT_DEFAULT_STOCK ?? 1);

interface ProductSpec {
  name: string;
  sizeLabel: string;
  colorLabel: string;
  salePrice: number;
  offerPrice: number;
  imageFile: string;
  shortDescription?: string;
}

const PRODUCTS: ProductSpec[] = [
  {
    name: 'Blusa tela stretch',
    sizeLabel: 'estandar',
    colorLabel: 'coral',
    salePrice: 45,
    offerPrice: 20,
    imageFile: 'Blusa tela stretch.jpeg',
  },
  {
    name: 'Blusa tela',
    sizeLabel: 'estandar',
    colorLabel: 'uva',
    salePrice: 40,
    offerPrice: 20,
    imageFile: 'Blusa tela.jpeg',
  },
  {
    name: 'Blusa tela licra',
    sizeLabel: 'estandar',
    colorLabel: 'melon',
    salePrice: 45,
    offerPrice: 25,
    imageFile: 'Blusa tela licra.jpeg',
  },
  {
    name: 'Polo algodón',
    sizeLabel: 's',
    colorLabel: 'celeste',
    salePrice: 25,
    offerPrice: 10,
    imageFile: 'Blusa algodon celeste.jpeg',
  },
  {
    name: 'Blusa gasa',
    sizeLabel: 'estandar',
    colorLabel: 'palo rosa',
    salePrice: 35,
    offerPrice: 15,
    imageFile: 'Blusa gasa.jpeg',
  },
  {
    name: 'Blusa seda francesa',
    sizeLabel: 'estandar',
    colorLabel: 'lila',
    salePrice: 40,
    offerPrice: 20,
    imageFile: 'Blusa seda francesa.jpeg',
  },
  {
    name: 'Blusa gasa crepet',
    sizeLabel: 'l',
    colorLabel: 'rosado',
    salePrice: 45,
    offerPrice: 20,
    imageFile: 'Blusa gasa crepet.jpeg',
  },
  {
    name: 'Polo viscosa nacional',
    sizeLabel: 'l',
    colorLabel: 'verde petroleo',
    salePrice: 35,
    offerPrice: 20,
    imageFile: 'Polo viscosa nacional.jpeg',
  },
];

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
    JWT_SECRET!,
    { expiresIn: '2h' },
  );
}

function normalize(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
}

function titleCase(value: string): string {
  return value
    .split(/\s+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

async function api<T>(token: string, method: string, apiPath: string, body?: unknown): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${apiPath}`, {
    method,
    headers: {
      Authorization: `Bearer ${token}`,
      ...(body ? { 'Content-Type': 'application/json' } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  const text = await response.text();
  if (!response.ok) {
    throw new Error(`${method} ${apiPath} → ${response.status}: ${text}`);
  }

  return text ? (JSON.parse(text) as T) : (null as T);
}

interface CatalogColor {
  id: string;
  description: string;
}

interface CatalogSize {
  id: string;
  description: string;
}

interface ProductListItem {
  id: string;
  name: string;
  productSizes?: Array<{
    id: string;
    sizeId: string;
    productSizeColors?: Array<{ colorId: string }>;
  }>;
}

function productKey(name: string, sizeLabel: string, colorLabel: string): string {
  return `${normalize(name)}|${normalize(sizeLabel)}|${normalize(colorLabel)}`;
}

function displayName(spec: ProductSpec): string {
  return spec.name;
}

async function resolveColorId(token: string, cache: Map<string, string>, label: string): Promise<string> {
  const key = normalize(label);
  const cached = cache.get(key);
  if (cached) return cached;

  const aliases: Record<string, string[]> = {
    'palo rosa': ['palo rosa', 'palorosa', 'palo rosa claro'],
    melon: ['melon', 'melón'],
    'verde petroleo': ['verde petroleo', 'verde petróleo', 'petroleo', 'petróleo'],
  };

  const candidates = aliases[key] ?? [label];
  const colors = await api<CatalogColor[]>(token, 'GET', '/colors');

  for (const candidate of candidates) {
    const normalizedCandidate = normalize(candidate);
    const match = colors.find((color) => normalize(color.description) === normalizedCandidate);
    if (match) {
      cache.set(key, match.id);
      return match.id;
    }
  }

  for (const candidate of candidates) {
    const normalizedCandidate = normalize(candidate);
    const match = colors.find((color) => normalize(color.description).includes(normalizedCandidate));
    if (match) {
      cache.set(key, match.id);
      return match.id;
    }
  }

  const created = await api<CatalogColor>(token, 'POST', '/colors', {
    description: titleCase(label),
  });
  cache.set(key, created.id);
  return created.id;
}

async function resolveSizeId(token: string, cache: Map<string, string>, label: string): Promise<string> {
  const key = normalize(label);
  const cached = cache.get(key);
  if (cached) return cached;

  const aliases: Record<string, string[]> = {
    estandar: ['estandar', 'estándar', 'standard', 'standar', 'standart', 'std'],
    s: ['s'],
    l: ['l'],
  };

  const candidates = aliases[key] ?? [label];
  const sizes = await api<CatalogSize[]>(token, 'GET', '/sizes');

  for (const candidate of candidates) {
    const normalizedCandidate = normalize(candidate);
    const match = sizes.find((size) => normalize(size.description) === normalizedCandidate);
    if (match) {
      cache.set(key, match.id);
      return match.id;
    }
  }

  throw new Error(`No se encontró la talla "${label}". Tallas disponibles: ${sizes.map((s) => s.description).join(', ')}`);
}

async function findExistingProduct(
  token: string,
  spec: ProductSpec,
): Promise<ProductListItem | undefined> {
  const name = displayName(spec);
  const list = await api<{ data: ProductListItem[] }>(
    token,
    'GET',
    `/products?search=${encodeURIComponent(name)}&perPage=50`,
  );

  return list.data?.find((item) => normalize(item.name) === normalize(name));
}

async function uploadImage(token: string, productId: string, imagePath: string): Promise<void> {
  const buffer = fs.readFileSync(imagePath);
  const fileName = path.basename(imagePath);
  const form = new FormData();
  form.append('image', new Blob([buffer], { type: 'image/jpeg' }), fileName);

  const response = await fetch(`${API_BASE_URL}/products/${productId}/media`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
    },
    body: form,
  });

  if (!response.ok) {
    const text = await response.text();
    throw new Error(`POST /products/${productId}/media → ${response.status}: ${text}`);
  }
}

async function ensureProduct(token: string, spec: ProductSpec, sizeId: string, colorId: string): Promise<string> {
  const name = displayName(spec);
  const purchasePrice = Math.round(spec.offerPrice * 0.5 * 100) / 100;
  let product = await findExistingProduct(token, spec);

  if (!product) {
    const created = await api<{ productId: string }>(token, 'POST', '/products', {
      name,
      shortDescription: `${spec.name} - Color ${titleCase(spec.colorLabel)}`,
      genderId: GENDER_ID,
      warehouseId: WAREHOUSE_ID,
      status: 'active',
      wooStatus: 'publish',
      sizes: [
        {
          sizeId,
          purchasePrice,
          salePrice: spec.salePrice,
          minSalePrice: spec.offerPrice,
        },
      ],
    });
    product = await api<ProductListItem>(token, 'GET', `/products/${created.productId}`);
    console.log(`✓ Creado: ${name}`);
  } else {
    console.log(`↻ Actualizado: ${name}`);
  }

  const productSize = product.productSizes?.find((row) => row.sizeId === sizeId);
  if (!productSize) {
    await api(token, 'POST', `/products/${product.id}/sizes/${sizeId}`, {
      purchasePrice,
      salePrice: spec.salePrice,
      minSalePrice: spec.offerPrice,
    });
    product = await api<ProductListItem>(token, 'GET', `/products/${product.id}`);
  }

  const refreshedSize = product.productSizes?.find((row) => row.sizeId === sizeId);
  if (!refreshedSize) {
    throw new Error(`No se pudo resolver la talla para ${name}`);
  }

  await api(token, 'PATCH', `/products/${product.id}/sizes/${sizeId}`, {
    purchasePrice,
    salePrice: spec.salePrice,
    minSalePrice: spec.offerPrice,
  });

  const hasColor = refreshedSize.productSizeColors?.some((row) => row.colorId === colorId);
  if (!hasColor) {
    await api(token, 'POST', `/product-sizes/${refreshedSize.id}/colors`, {
      colorId,
      initialStock: DEFAULT_STOCK,
    });
  } else {
    await api(token, 'PATCH', `/product-sizes/${refreshedSize.id}/colors/${colorId}`, {
      stock: DEFAULT_STOCK,
    });
  }

  await api(token, 'PATCH', `/products/${product.id}`, {
    wooStatus: 'publish',
    isOnSale: true,
    shortDescription: `${spec.name} - Color ${titleCase(spec.colorLabel)}`,
  });

  await setOfferPrice(product.id, spec.offerPrice);

  const media = await api<{ data: Array<{ id: string }> }>(token, 'GET', `/products/${product.id}/media`);
  if (!media.data?.length) {
    const imagePath = path.join(PHOTOS_DIR, spec.imageFile);
    if (!fs.existsSync(imagePath)) {
      throw new Error(`Falta la imagen: ${imagePath}`);
    }
    await uploadImage(token, product.id, imagePath);
    console.log(`  + Imagen: ${spec.imageFile}`);
  } else {
    console.log(`  · Imagen ya existente`);
  }

  return product.id;
}

async function setOfferPrice(productId: string, offerPrice: number): Promise<void> {
  if (USE_PROD_DB_SSH) {
    execFileSync(
      'ssh',
      [
        '-F',
        PROD_SSH_CONFIG,
        '-o',
        'BatchMode=yes',
        PROD_SSH_HOST,
        `docker exec -i nm_postgres psql -U postgres -d nm_services -v ON_ERROR_STOP=1 -c "UPDATE products SET offer_price = ${offerPrice}, is_on_sale = true, woo_status = 'publish' WHERE id = '${productId}';"`,
      ],
      { stdio: 'inherit' },
    );
    return;
  }

  await prisma!.product.update({
    where: { id: productId },
    data: {
      offerPrice,
      isOnSale: true,
      wooStatus: 'publish',
    },
  });
}

async function main(): Promise<void> {
  if (!fs.existsSync(PHOTOS_DIR)) {
    throw new Error(`No existe la carpeta de fotos: ${PHOTOS_DIR}`);
  }

  const token = createAccessToken();
  const colorCache = new Map<string, string>();
  const sizeCache = new Map<string, string>();
  const results: Array<{ name: string; id: string }> = [];

  console.log(`Importando ${PRODUCTS.length} productos desde ${PHOTOS_DIR}\n`);

  for (const spec of PRODUCTS) {
    const sizeId = await resolveSizeId(token, sizeCache, spec.sizeLabel);
    const colorId = await resolveColorId(token, colorCache, spec.colorLabel);
    const productId = await ensureProduct(token, spec, sizeId, colorId);
    results.push({ name: displayName(spec), id: productId });
  }

  console.log('\n✅ Importación completada\n');
  for (const item of results) {
    console.log(`- ${item.name} → ${item.id}`);
  }

  if (prisma) {
    await prisma.$disconnect();
  }
}

main().catch(async (error: unknown) => {
  if (prisma) {
    await prisma.$disconnect();
  }
  console.error(error);
  process.exit(1);
});

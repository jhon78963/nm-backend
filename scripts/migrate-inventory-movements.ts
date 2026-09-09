// =============================================================================
// ETL: inventory_movements (Laravel nm_db) → nm_services
// Ejecutar: npx ts-node --project tsconfig.migration.json scripts/migrate-inventory-movements.ts
// Requiere: migrate-laravel-data.ts ejecutado previamente
// =============================================================================

import { Client } from 'pg';
import { v5 as uuidv5 } from 'uuid';
import * as dotenv from 'dotenv';

dotenv.config({ path: '.env.migration' });

const srcConfig = {
  host: process.env.SRC_DB_HOST ?? 'localhost',
  port: Number(process.env.SRC_DB_PORT ?? 5432),
  database: process.env.SRC_DB_NAME ?? 'nm_db',
  user: process.env.SRC_DB_USER ?? 'postgres',
  password: process.env.SRC_DB_PASSWORD ?? 'postgres',
};

const dstConfig = {
  host: process.env.DST_DB_HOST ?? 'localhost',
  port: Number(process.env.DST_DB_PORT ?? 5433),
  database: process.env.DST_DB_NAME ?? 'nm_services',
  user: process.env.DST_DB_USER ?? 'postgres',
  password: process.env.DST_DB_PASSWORD ?? 'password',
};

const NS = {
  USER: 'a0000007-0000-5000-8000-000000000000',
  WAREHOUSE: 'a0000002-0000-5000-8000-000000000000',
  COLOR: 'a0000004-0000-5000-8000-000000000000',
  PRODUCT_SIZE: 'a000000c-0000-5000-8000-000000000000',
  PURCHASE: 'a000000f-0000-5000-8000-000000000000',
  SALE: 'a0000012-0000-5000-8000-000000000000',
  INV_MOVEMENT: 'a000001c-0000-5000-8000-000000000000',
} as const;

function toUUID(namespace: string, legacyId: string | number): string {
  return uuidv5(String(legacyId), namespace);
}

function normalizeReferenceType(referenceType: string | null): string | null {
  if (!referenceType) return null;
  const normalized = referenceType.trim();
  if (!normalized) return null;
  const parts = normalized.split('\\');
  return parts[parts.length - 1] ?? normalized;
}

function mapReferenceId(
  referenceType: string | null,
  referenceId: string | number | null,
): string | null {
  if (referenceId == null) return null;

  const type = normalizeReferenceType(referenceType)?.toLowerCase() ?? '';
  switch (type) {
    case 'purchase':
      return toUUID(NS.PURCHASE, referenceId);
    case 'sale':
    case 'saleexchange':
    case 'saleupdate':
      return toUUID(NS.SALE, referenceId);
    default:
      return null;
  }
}

async function migrateInventoryMovements(): Promise<void> {
  const src = new Client(srcConfig);
  const dst = new Client(dstConfig);

  console.log('Migrando inventory_movements...');
  console.log(`  Origen:  ${srcConfig.database}@${srcConfig.host}:${srcConfig.port}`);
  console.log(`  Destino: ${dstConfig.database}@${dstConfig.host}:${dstConfig.port}`);

  await src.connect();
  await dst.connect();

  try {
    const { rows: fallbackUsers } = await dst.query(
      `SELECT id FROM users WHERE is_deleted = false ORDER BY creation_time ASC LIMIT 1`,
    );
    const fallbackUserId: string | null = fallbackUsers[0]?.id ?? null;
    if (!fallbackUserId) {
      throw new Error('No hay usuarios en nm_services para usar como created_by_id fallback.');
    }

    const { rows: movements } = await src.query(
      `SELECT
         id, warehouse_id, product_size_id, color_id,
         direction, quantity, movement_type,
         reference_type, reference_id,
         balance_after_movement, occurred_at, created_by_user_id
       FROM inventory_movements
       ORDER BY id`,
    );

    await dst.query('BEGIN');

    let inserted = 0;
    let skippedMissingFk = 0;
    let skippedMissingColor = 0;

    for (const row of movements) {
      if (row.color_id == null) {
        skippedMissingColor++;
        continue;
      }

      const warehouseUUID = toUUID(NS.WAREHOUSE, row.warehouse_id);
      const productSizeUUID = toUUID(NS.PRODUCT_SIZE, row.product_size_id);
      const colorUUID = toUUID(NS.COLOR, row.color_id);

      const { rows: fkExists } = await dst.query(
        `SELECT 1
         FROM warehouses w
         JOIN product_size ps ON ps.id = $2
         JOIN colors c ON c.id = $3
         WHERE w.id = $1
         LIMIT 1`,
        [warehouseUUID, productSizeUUID, colorUUID],
      );
      if (fkExists.length === 0) {
        skippedMissingFk++;
        continue;
      }

      const createdById =
        row.created_by_user_id != null
          ? toUUID(NS.USER, row.created_by_user_id)
          : fallbackUserId;

      const newId = toUUID(NS.INV_MOVEMENT, row.id);
      const direction = String(row.direction).trim().toUpperCase().slice(0, 3);
      const referenceType = normalizeReferenceType(row.reference_type);

      await dst.query(
        `INSERT INTO inventory_movements (
           id, warehouse_id, product_size_id, color_id,
           direction, quantity, movement_type,
           reference_type, reference_id, balance_after,
           occurred_at, created_by_id
         ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)
         ON CONFLICT (id) DO NOTHING`,
        [
          newId,
          warehouseUUID,
          productSizeUUID,
          colorUUID,
          direction,
          row.quantity,
          String(row.movement_type).slice(0, 50),
          referenceType ? referenceType.slice(0, 50) : null,
          mapReferenceId(row.reference_type, row.reference_id),
          row.balance_after_movement,
          row.occurred_at,
          createdById,
        ],
      );
      inserted++;
    }

    await dst.query('COMMIT');

    const { rows: countRows } = await dst.query(
      'SELECT COUNT(*)::int AS total FROM inventory_movements',
    );

    console.log('\n✅ Migración de inventory_movements completada.');
    console.log(`   Procesados: ${movements.length}`);
    console.log(`   Insertados: ${inserted}`);
    console.log(`   Omitidos (FK faltante): ${skippedMissingFk}`);
    console.log(`   Omitidos (sin color_id): ${skippedMissingColor}`);
    console.log(`   Total en destino: ${countRows[0].total}`);
  } catch (err) {
    await dst.query('ROLLBACK').catch(() => undefined);
    throw err;
  } finally {
    await src.end().catch(() => undefined);
    await dst.end().catch(() => undefined);
  }
}

migrateInventoryMovements().catch((err: unknown) => {
  console.error('❌ Error:', err instanceof Error ? err.message : err);
  process.exit(1);
});

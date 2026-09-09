// =============================================================================
// ETL: product_histories (Laravel nm_db) → nm_services
// Ejecutar: npx ts-node --project tsconfig.migration.json scripts/migrate-product-histories.ts
// Requiere: migrate-laravel-data.ts ejecutado previamente (productos y usuarios migrados)
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
  PRODUCT: 'a000000b-0000-5000-8000-000000000000',
  PRODUCT_HISTORY: 'a000001b-0000-5000-8000-000000000000',
} as const;

function toUUID(namespace: string, legacyId: string | number): string {
  return uuidv5(String(legacyId), namespace);
}

async function migrateProductHistories(): Promise<void> {
  const src = new Client(srcConfig);
  const dst = new Client(dstConfig);

  console.log('Migrando product_histories...');
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

    const { rows: histories } = await src.query(
      `SELECT
         id, creation_time, creator_user_id,
         is_deleted, product_id, event_type,
         old_values, new_values, reason
       FROM product_histories
       WHERE is_deleted = false
       ORDER BY id`,
    );

    await dst.query('BEGIN');

    let inserted = 0;
    let skipped = 0;

    for (const row of histories) {
      const productUUID = toUUID(NS.PRODUCT, row.product_id);

      const { rows: productExists } = await dst.query(
        `SELECT id FROM products WHERE id = $1 LIMIT 1`,
        [productUUID],
      );
      if (productExists.length === 0) {
        skipped++;
        continue;
      }

      const createdById =
        row.creator_user_id != null
          ? toUUID(NS.USER, row.creator_user_id)
          : fallbackUserId;

      const newId = toUUID(NS.PRODUCT_HISTORY, row.id);

      await dst.query(
        `INSERT INTO product_histories (
           id, product_id, event_type, reason,
           old_values, new_values, created_by_id, created_at
         ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
         ON CONFLICT (id) DO NOTHING`,
        [
          newId,
          productUUID,
          String(row.event_type).slice(0, 100),
          row.reason ?? null,
          row.old_values ?? null,
          row.new_values ?? null,
          createdById,
          row.creation_time ?? new Date(),
        ],
      );
      inserted++;
    }

    await dst.query('COMMIT');

    const { rows: countRows } = await dst.query(
      'SELECT COUNT(*)::int AS total FROM product_histories',
    );

    console.log('\n✅ Migración de product_histories completada.');
    console.log(`   Procesados: ${histories.length}`);
    console.log(`   Insertados: ${inserted}`);
    console.log(`   Omitidos (producto no migrado): ${skipped}`);
    console.log(`   Total en destino: ${countRows[0].total}`);
  } catch (err) {
    await dst.query('ROLLBACK').catch(() => undefined);
    throw err;
  } finally {
    await src.end().catch(() => undefined);
    await dst.end().catch(() => undefined);
  }
}

migrateProductHistories().catch((err: unknown) => {
  console.error('❌ Error:', err instanceof Error ? err.message : err);
  process.exit(1);
});

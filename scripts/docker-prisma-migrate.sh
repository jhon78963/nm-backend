#!/usr/bin/env sh
# =============================================================================
# docker-prisma-migrate.sh — Migraciones Prisma en Docker
#
# Casos:
#   1. BD vacía                          → prisma migrate deploy
#   2. Ya tiene _prisma_migrations       → prisma migrate deploy (pendientes)
#   3. Esquema Laravel en nm_services    → renombra a nm_db, recrea nm_services,
#                                            migrate deploy + ETL Laravel→Prisma
#   4. nm_services vacía + nm_db existe  → migrate deploy + ETL
# =============================================================================
set -eu

SCHEMA="libs/database/prisma/schema.prisma"
PGHOST="${POSTGRES_HOST:-postgres}"
PGPORT="${POSTGRES_PORT:-5432}"
PGUSER="${POSTGRES_USER:-postgres}"
PGPASSWORD="${POSTGRES_PASSWORD:-password}"
export PGPASSWORD

DB="${POSTGRES_DB:-nm_services}"
LARAVEL_DB="${LARAVEL_SOURCE_DB:-nm_db}"

log() { printf '%s\n' "[migrate] $*"; }
err() { printf '%s\n' "[migrate] ERROR: $*" >&2; }

psql_admin() {
  psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d postgres -v ON_ERROR_STOP=1 "$@"
}

psql_scalar() {
  psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d "$1" -v ON_ERROR_STOP=1 -tAc "$2" | tr -d '[:space:]'
}

has_prisma_migrations() {
  psql_scalar "$DB" \
    "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='_prisma_migrations');"
}

table_count() {
  psql_scalar "$DB" \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE';"
}

tenant_id_type() {
  psql_scalar "$DB" \
    "SELECT COALESCE((SELECT data_type FROM information_schema.columns WHERE table_schema='public' AND table_name='tenants' AND column_name='id'), '');"
}

db_exists() {
  psql_scalar postgres \
    "SELECT EXISTS (SELECT 1 FROM pg_database WHERE datname='${1}');"
}

apply_prisma_migrations() {
  log "Aplicando migraciones Prisma en ${DB}..."
  npx prisma migrate deploy --schema="$SCHEMA"
  log "Migraciones Prisma aplicadas."
}

recover_laravel_schema_in_services_db() {
  log "Esquema Laravel detectado en ${DB}."

  if [ "$(db_exists "$LARAVEL_DB")" = "t" ]; then
    err "${LARAVEL_DB} ya existe. Elimínala o restaura el backup ahí directamente."
    exit 1
  fi

  log "Moviendo datos Laravel: ${DB} → ${LARAVEL_DB}..."
  psql_admin <<SQL
SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE datname = '${DB}' AND pid <> pg_backend_pid();
ALTER DATABASE "${DB}" RENAME TO "${LARAVEL_DB}";
CREATE DATABASE "${DB}";
SQL
  log "Base Laravel disponible en ${LARAVEL_DB}; ${DB} recreada vacía."
}

run_laravel_etl() {
  if [ "${RUN_LARAVEL_ETL:-true}" != "true" ]; then
    log "RUN_LARAVEL_ETL=false — ETL omitido."
    seed_document_series
    seed_chatbot
    return 0
  fi

  if [ "$(db_exists "$LARAVEL_DB")" != "t" ]; then
    log "No existe ${LARAVEL_DB} — ETL omitido."
    seed_document_series
    seed_chatbot
    return 0
  fi

  export SRC_DB_HOST="$PGHOST"
  export SRC_DB_PORT="$PGPORT"
  export SRC_DB_NAME="$LARAVEL_DB"
  export SRC_DB_USER="$PGUSER"
  export SRC_DB_PASSWORD="$PGPASSWORD"
  export DST_DB_HOST="$PGHOST"
  export DST_DB_PORT="$PGPORT"
  export DST_DB_NAME="$DB"
  export DST_DB_USER="$PGUSER"
  export DST_DB_PASSWORD="$PGPASSWORD"

  log "ETL Laravel (${LARAVEL_DB}) → Prisma (${DB})..."
  npx ts-node --project tsconfig.migration.json scripts/migrate-laravel-data.ts
  npx ts-node --project tsconfig.migration.json scripts/migrate-product-histories.ts
  npx ts-node --project tsconfig.migration.json scripts/migrate-inventory-movements.ts
  npx ts-node --project tsconfig.migration.json scripts/migrate-action-logs.ts
  npx ts-node --project tsconfig.migration.json scripts/migrate-permissions.ts
  log "ETL completado."
  seed_document_series
  seed_chatbot
}

seed_document_series() {
  log "Sembrando series documentales (BOLETA/FACTURA)..."
  npx ts-node --project tsconfig.migration.json scripts/seed-document-series.ts
  log "Series documentales listas."
}

seed_chatbot() {
  log "Sembrando agente admin del chatbot..."
  npx ts-node --project tsconfig.migration.json libs/database/prisma/seeds/chatbot-seed.ts
  log "Chatbot seed listo."
}

run_dev_admin_seed() {
  if [ "${SEED_DEV_ADMIN:-false}" != "true" ]; then
    log "SEED_DEV_ADMIN=false — seed de admin omitido."
    return 0
  fi

  log "Sembrando usuario admin de desarrollo..."
  npx ts-node --project tsconfig.migration.json libs/database/prisma/seed.ts
  log "Seed de admin completado."
}

# ── Flujo principal ─────────────────────────────────────────────────────────

if [ "$(has_prisma_migrations)" = "t" ]; then
  apply_prisma_migrations
  seed_document_series
  seed_chatbot
  run_dev_admin_seed
  exit 0
fi

tables="$(table_count)"

if [ "$tables" = "0" ]; then
  apply_prisma_migrations
  run_laravel_etl
  run_dev_admin_seed
  exit 0
fi

if [ "$(tenant_id_type)" = "bigint" ]; then
  recover_laravel_schema_in_services_db
  apply_prisma_migrations
  run_laravel_etl
  run_dev_admin_seed
  exit 0
fi

err "P3005: ${DB} no está vacía y no tiene _prisma_migrations."
err "Tablas detectadas: ${tables}; tenants.id tipo: $(tenant_id_type)"
err "Restaura el backup Laravel en ${LARAVEL_DB} o borra el volumen Postgres."
exit 1

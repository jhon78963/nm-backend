#!/usr/bin/env bash
# =============================================================================
# backfill-legacy-history.sh — Importa historial y kardex SIN borrar nm_services
#
# Usa nm_db (Laravel) como origen y agrega registros faltantes en nm_services.
# Seguro para producción: no hace DROP DATABASE ni re-ejecuta el ETL completo.
#
# Uso local:
#   ./scripts/backfill-legacy-history.sh
#
# Uso en VPS (con stack corriendo):
#   cd /opt/nm/nm-deploy
#   docker compose -f docker-compose.prod.yml run --rm \
#     -e RUN_LARAVEL_ETL=false \
#     migrate sh -c "npm run db:migrate:product-histories && npm run db:migrate:inventory-movements"
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if docker compose version >/dev/null 2>&1; then
  DC="docker compose"
else
  DC="docker-compose"
fi

DEPLOY_ROOT="$ROOT/../nm-deploy"
PGHOST="${POSTGRES_HOST:-localhost}"
PGPORT="${POSTGRES_PORT:-5432}"
PGUSER="${POSTGRES_USER:-postgres}"
PGPASSWORD="${POSTGRES_PASSWORD:-password}"
LARAVEL_DB="${LARAVEL_SOURCE_DB:-nm_db}"
SERVICES_DB="${POSTGRES_DB:-nm_services}"

export PGPASSWORD

log() { printf '%s\n' "[backfill] $*"; }
err() { printf '%s\n' "[backfill] ERROR: $*" >&2; }

db_exists() {
  psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d postgres -tAc \
    "SELECT EXISTS (SELECT 1 FROM pg_database WHERE datname='${1}');" \
    | tr -d '[:space:]'
}

table_count() {
  psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d "$1" -tAc \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE';" \
    | tr -d '[:space:]'
}

if [ -f "$DEPLOY_ROOT/docker-compose.yml" ] && docker ps --format '{{.Names}}' | grep -qx "nm_postgres"; then
  log "Detectado Postgres en Docker (nm_postgres)."
  POSTGRES_CONTAINER="nm_postgres"
  PGHOST="localhost"
  PGPORT="$(docker port "$POSTGRES_CONTAINER" 5432 2>/dev/null | head -1 | awk -F: '{print $NF}')"
  PGPORT="${PGPORT:-5432}"
fi

if [ "$(db_exists "$LARAVEL_DB")" != "t" ]; then
  err "No existe la base origen ${LARAVEL_DB}."
  err "Restaura solo el backup Laravel ahí (sin tocar ${SERVICES_DB}) y vuelve a ejecutar este script."
  exit 1
fi

if [ "$(db_exists "$SERVICES_DB")" != "t" ]; then
  err "No existe la base destino ${SERVICES_DB}."
  exit 1
fi

if [ "$(table_count "$SERVICES_DB")" = "0" ]; then
  err "${SERVICES_DB} está vacía. Ejecuta primero el ETL completo (migrate-laravel-data.ts)."
  exit 1
fi

log "Origen:  ${LARAVEL_DB}@${PGHOST}:${PGPORT}"
log "Destino: ${SERVICES_DB}@${PGHOST}:${PGPORT}"
log "Este script es aditivo: no borra datos existentes en ${SERVICES_DB}."

export SRC_DB_HOST="$PGHOST"
export SRC_DB_PORT="$PGPORT"
export SRC_DB_NAME="$LARAVEL_DB"
export SRC_DB_USER="$PGUSER"
export SRC_DB_PASSWORD="$PGPASSWORD"
export DST_DB_HOST="$PGHOST"
export DST_DB_PORT="$PGPORT"
export DST_DB_NAME="$SERVICES_DB"
export DST_DB_USER="$PGUSER"
export DST_DB_PASSWORD="$PGPASSWORD"

npm run db:migrate:product-histories
npm run db:migrate:inventory-movements

log "✅ Backfill completado."

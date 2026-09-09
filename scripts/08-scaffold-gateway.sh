#!/usr/bin/env bash
# =============================================================================
# 08-scaffold-gateway.sh
# Andamia el gateway (BFF) y el report-service.
#
# Gateway:
#   - Punto de entrada único para el Angular frontend
#   - Valida JWT y reenvía requests a los microservicios internos
#   - Agrega Swagger de todos los servicios en /api/docs
#   - Health checks en /health
#
# Report-service:
#   - DashboardMetricsService (métricas del dashboard)
#   - ReportService (productos, ventas diarias/mensuales, PDFs)
#   - AiProxy (predictions + inventory report)
#
# Equivale a:
#   DashboardController + ReportController + AiPredictionController
#   AiReportController (del Laravel original)
# =============================================================================
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info()    { echo -e "${BLUE}[INFO]${NC}  $*"; }
success() { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*" >&2; exit 1; }

[[ -f "nest-cli.json" ]] || error "Ejecuta desde la raíz del monorepo."

GW_SRC="apps/gateway/src"
RPT_SRC="apps/report-service/src"
MIGRATION_KIT="$(dirname "$(realpath "$0")")/.."

copy_kit() {
  local src="$MIGRATION_KIT/$1" dst="$2"
  [[ -f "$src" ]] || { warn "Kit: $src no encontrado."; return; }
  [[ -f "$dst" ]] && { warn "Ya existe: $dst"; return; }
  mkdir -p "$(dirname "$dst")"
  cp "$src" "$dst" && success "Copiado: $dst"
}

# ─── 1. Dependencias adicionales ─────────────────────────────────────────────
info "Instalando dependencias del gateway y report-service..."
npm install @nestjs/axios axios http-proxy-middleware pdfkit exceljs
npm install --save-dev @types/pdfkit
success "Dependencias instaladas."

# ─── 2. Módulos CLI ──────────────────────────────────────────────────────────
info "Generando módulos del gateway..."
(
  cd apps/gateway
  for mod in proxy health; do
    nest g module "$mod" --no-spec 2>/dev/null || warn "$mod ya existe."
  done
  nest g controller health/health --no-spec --flat 2>/dev/null || true
)

info "Generando módulos del report-service..."
(
  cd apps/report-service
  for mod in dashboard reports ai-proxy; do
    nest g module "$mod" --no-spec 2>/dev/null || warn "$mod ya existe."
  done
  for svc in dashboard/dashboard reports/reports ai-proxy/ai-proxy; do
    nest g service "$svc" --no-spec --flat 2>/dev/null || warn "Service $svc ya existe."
  done
  for ctrl in dashboard/dashboard reports/reports ai-proxy/ai-proxy; do
    nest g controller "$ctrl" --no-spec --flat 2>/dev/null || warn "Controller $ctrl ya existe."
  done
)
success "Módulos generados."

# ─── 3. Copiar fuentes ───────────────────────────────────────────────────────

# Gateway
copy_kit "apps/gateway/src/main.ts"                                       "$GW_SRC/main.ts"
copy_kit "apps/gateway/src/app.module.ts"                                 "$GW_SRC/app.module.ts"
copy_kit "apps/gateway/src/proxy/proxy.service.ts"                        "$GW_SRC/proxy/proxy.service.ts"
copy_kit "apps/gateway/src/proxy/proxy.module.ts"                         "$GW_SRC/proxy/proxy.module.ts"
copy_kit "apps/gateway/src/health/health.controller.ts"                   "$GW_SRC/health/health.controller.ts"

# Report service
copy_kit "apps/report-service/src/main.ts"                                "$RPT_SRC/main.ts"
copy_kit "apps/report-service/src/app.module.ts"                          "$RPT_SRC/app.module.ts"
copy_kit "apps/report-service/src/dashboard/dashboard.service.ts"         "$RPT_SRC/dashboard/dashboard.service.ts"
copy_kit "apps/report-service/src/dashboard/dashboard.controller.ts"      "$RPT_SRC/dashboard/dashboard.controller.ts"
copy_kit "apps/report-service/src/reports/reports.service.ts"             "$RPT_SRC/reports/reports.service.ts"
copy_kit "apps/report-service/src/reports/reports.controller.ts"          "$RPT_SRC/reports/reports.controller.ts"
copy_kit "apps/report-service/src/ai-proxy/ai-proxy.service.ts"           "$RPT_SRC/ai-proxy/ai-proxy.service.ts"
copy_kit "apps/report-service/src/ai-proxy/ai-proxy.controller.ts"        "$RPT_SRC/ai-proxy/ai-proxy.controller.ts"

# Tests
copy_kit "apps/report-service/src/dashboard/dashboard.service.spec.ts"    "$RPT_SRC/dashboard/dashboard.service.spec.ts"
copy_kit "apps/report-service/src/reports/reports.service.spec.ts"        "$RPT_SRC/reports/reports.service.spec.ts"

# Docker + infra
copy_kit "docker-compose.yml"     "docker-compose.yml"
copy_kit "docker-compose.prod.yml" "docker-compose.prod.yml"

# ─── 4. Variables de entorno ──────────────────────────────────────────────────
if [[ -f ".env" ]] && ! grep -q "GATEWAY_PORT" .env; then
  cat >> .env <<'ENVVARS'

# ── Gateway ───────────────────────────────────────────────────────────────────
GATEWAY_PORT=3000

# ── Puertos internos de microservicios ────────────────────────────────────────
AUTH_SERVICE_URL="http://localhost:3001"
CATALOG_SERVICE_URL="http://localhost:3002"
INVENTORY_SERVICE_URL="http://localhost:3003"
POS_SERVICE_URL="http://localhost:3004"
FINANCE_SERVICE_URL="http://localhost:3005"
HR_SERVICE_URL="http://localhost:3006"
REPORT_SERVICE_URL="http://localhost:3007"
AI_PROXY_SERVICE_URL="http://localhost:3008"
AI_ENGINE_URL="http://localhost:8010"
ENVVARS
  success ".env actualizado con URLs de servicios."
fi

# ─── 5. Ejecutar todos los tests ─────────────────────────────────────────────
info "Ejecutando TODOS los tests del monorepo..."
npm run test:all 2>&1 | tee /tmp/all-tests-output.log
echo ""
grep -E "Tests:|Test Suites:|PASS|FAIL" /tmp/all-tests-output.log | tail -20

# ─── 6. Resumen final ─────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║  MIGRATION KIT COMPLETO — nm-backend → NestJS Microservicios        ║${NC}"
echo -e "${GREEN}╠══════════════════════════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║  Microservicios:                                                     ║${NC}"
echo -e "${GREEN}║    ✓ auth-service     :3001  (JWT, RBAC, Tenants)                   ║${NC}"
echo -e "${GREEN}║    ✓ catalog-service  :3002  (Products, catalog)                  ║${NC}"
echo -e "${GREEN}║    ✓ inventory-service:3003  (Ledger, Purchases, Kardex)            ║${NC}"
echo -e "${GREEN}║    ✓ pos-service      :3004  (Checkout, SUNAT, Tickets)             ║${NC}"
echo -e "${GREEN}║    ✓ finance-service  :3005  (Cashflow, Acumulados)                 ║${NC}"
echo -e "${GREEN}║    ✓ hr-service       :3006  (Teams, Asistencia, Planilla)          ║${NC}"
echo -e "${GREEN}║    ✓ report-service   :3007  (Dashboard, Reports, AI)               ║${NC}"
echo -e "${GREEN}║    ✓ gateway          :3000  (BFF, proxy, Swagger)                  ║${NC}"
echo -e "${GREEN}╠══════════════════════════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║  SIGUIENTES PASOS:                                                   ║${NC}"
echo -e "${GREEN}║    docker-compose up -d postgres redis                               ║${NC}"
echo -e "${GREEN}║    npx prisma migrate dev --name init                                ║${NC}"
echo -e "${GREEN}║    npx prisma db seed                                                ║${NC}"
echo -e "${GREEN}║    npm run start:dev:gateway                                         ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════════════════╝${NC}"

# ─── 7. Comandos Git ─────────────────────────────────────────────────────────
echo ""
echo -e "${BLUE}  git add apps/gateway/ apps/report-service/ docker-compose.yml .env.example${NC}"
echo -e "${BLUE}  git commit -m \"feat(gateway): add api gateway bff with proxy routing and health checks\"${NC}"
echo -e "${BLUE}  git add apps/report-service/src/**/*.spec.ts${NC}"
echo -e "${BLUE}  git commit -m \"test(report): add unit tests for dashboard metrics and report service\"${NC}"
echo -e "${BLUE}  git tag v0.1.0-migration-kit -m \"Initial NestJS migration kit — all 8 services scaffolded\"${NC}"

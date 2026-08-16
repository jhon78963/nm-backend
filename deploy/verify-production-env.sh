#!/usr/bin/env bash
# Valida configuración de seguridad antes del cutover a nm-frontend-v2.
# Uso:
#   ./deploy/verify-production-env.sh          # usa .env actual
#   ./deploy/verify-production-env.sh --dry    # simula prod con plantilla (solo smoke del comando)

set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> nm-backend: verificación pre-cutover"
echo ""

if [[ "${1:-}" == "--dry" ]]; then
  echo "Modo dry-run: exportando variables mínimas de producción para el check..."
  export APP_ENV=production
  export APP_DEBUG=false
  export APP_KEY=base64:dryRunKeyForSecurityCheckOnlyNotForDeploy1234567890=
  export APP_URL=https://api.novedadesmaritex.net.pe
  export SESSION_SECURE_COOKIE=true
  export SESSION_ENCRYPT=true
  export SESSION_SAME_SITE=none
  export SESSION_DOMAIN=.novedadesmaritex.net.pe
  export CORS_ALLOWED_ORIGINS=https://adm.novedadesmaritex.net.pe
  export SANCTUM_STATEFUL_DOMAINS=adm.novedadesmaritex.net.pe
  export FRONTEND_URL=https://adm.novedadesmaritex.net.pe
fi

php artisan config:clear --quiet 2>/dev/null || true

if php artisan config:check-security; then
  echo ""
  echo "OK — revisar también el checklist manual en docs/DEPLOY-SECURITY.md"
  exit 0
fi

echo ""
echo "FAIL — corrige .env y vuelve a ejecutar."
echo "Plantilla: deploy/.env.production.example"
exit 1

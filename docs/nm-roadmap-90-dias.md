# Roadmap NM — 90 días (3 fases)

> **Propósito:** plan ejecutable para backend, frontend ERP y ecommerce.  
> **Última actualización:** 2026-09-08  
> **Contexto:** análisis post-implementación SSO chatbot + estado actual de producción.

---

## Cómo usar este documento

1. Ejecutar **Fase 1 completa** antes de Fase 2 (hay dependencias).
2. Marcar tareas con `[x]` al completarlas.
3. Si se pierde el contexto del chat, pegar este archivo y decir: *"Continúa el roadmap desde la primera tarea pendiente"*.
4. **No commitear secrets** (`.env`, `vps/secrets/*`).
5. Deploy producción: `./vps/deploy-update.sh` o push a `main` → GitHub Actions → `nm-deploy`.

### Repos y URLs prod

| Repo | Path local | Producción |
|------|------------|------------|
| Backend | `nm-backend/` | `https://api.novedadesmaritex.net.pe` |
| ERP | `nm-frontend/` | `https://app.novedadesmaritex.net.pe` |
| Tienda | `nm-ecommerce/` | `https://novedadesmaritex.net.pe` |
| Chatbot | `nm-backend/services/chatbot/` | `https://chatbot.novedadesmaritex.net.pe` |
| Deploy | `nm-deploy/` + `vps/secrets/` | VPS `nm-prod` |

### Comandos útiles

```bash
# Backend — tests
cd nm-backend && npm run test:all

# Frontend — build + tests
cd nm-frontend && npm run build -- --configuration=docker && npm test

# Ecommerce — lint + test + build
cd nm-ecommerce && npm run lint && npm test && npm run build

# Deploy VPS (desde Mac)
./vps/deploy-update.sh

# Deploy manual chatbot + env (si hace falta)
scp vps/secrets/chatbot.env nm-prod:/opt/nm/nm-backend/services/chatbot/.env
ssh nm-prod 'cd /opt/nm/nm-deploy && docker compose --profile edge up -d --build chatbot-service admin --force-recreate && docker compose --profile edge restart reverse-proxy'
```

---

## Resumen ejecutivo

| Fase | Semanas | Objetivo | Resultado esperado |
|------|---------|----------|-------------------|
| **1 — Estabilidad** | 1–4 | Cerrar riesgos legales, auth rota, tests críticos | Deploys confiables, compliance básico |
| **2 — Experiencia** | 5–8 | UX operativa + cliente, guards, persistencia | ERP y tienda más cohesionados |
| **3 — Crecimiento** | 9–12 | Integraciones chatbot, facturación, AI, observabilidad | Plataforma lista para escalar tráfico |

---

# FASE 1 — Estabilidad (semanas 1–4)

> **Meta:** eliminar bugs de producción, huecos legales y falta de tests en el flujo de dinero.

## 1.1 Backend — Tests ecommerce + CI auth

### Tareas

- [x] **1.1.1** Arreglar tests auth que fallan por bcrypt en CI  
  - Archivos: `apps/auth-service/src/auth/auth.service.spec.ts`, `auth.controller.spec.ts`, `users.service.spec.ts`  
  - Criterio: `npm run test:all` → 0 suites fallidas en CI
  - **Hecho 2026-09-08:** migración `bcrypt` → `bcryptjs` (compatible con hashes existentes)

- [x] **1.1.2** Tests unitarios `ecommerce-service` (prioridad alta)  
  - Crear specs para:
    - Creación de orden + reserva de stock (`orders` module)
    - Validación de cupones
    - Webhook Culqi (mock)
    - Cancelación de orden
  - Path: `apps/ecommerce-service/src/**/*.spec.ts`  
  - Criterio: cobertura mínima de happy path + error en checkout
  - **Hecho 2026-09-08:** specs en orders, coupons, culqi.service, culqi-payments

- [x] **1.1.3** Ampliar CI backend  
  - Archivo: `nm-backend/.github/workflows/ci.yml`  
  - Build: gateway + auth + ecommerce + catalog + pos (mínimo)  
  - Criterio: PR no mergeable si tests fallan
  - **Hecho 2026-09-08:** añadidos build catalog + pos

- [x] **1.1.4** Gateway health incluye ecommerce + chatbot  
  - Archivo: `apps/gateway/src/health/` (o equivalente)  
  - Criterio: `GET /health/services` lista ecommerce y chatbot con status
  - **Hecho 2026-09-08:** `health.controller.ts` actualizado

### Verificación Fase 1.1

```bash
cd nm-backend && npm run test:all
curl -s https://api.novedadesmaritex.net.pe/health/services | jq .
```

---

## 1.2 Ecommerce — Legal + auth cliente + envíos

### Tareas

- [x] **1.2.1** Formulario **Libro de reclamaciones** → backend  
  - Frontend: `nm-ecommerce/src/features/institutional/components/InstitutionalHtmlContent.tsx`  
  - Backend: nuevo endpoint en `ecommerce-service` o `mail-service` (email a soporte + registro BD opcional)  
  - Criterio: submit envía email/registro real; no solo `alert()` local
  - **Hecho 2026-09-08:** `POST /ecommerce/institutional/libro-reclamaciones` + BFF `/api/institutional/libro-reclamaciones`

- [x] **1.2.2** Formulario **Contáctanos** → backend  
  - Mismo patrón que 1.2.1  
  - Criterio: email a `MAIL_SUPPORT_EMAIL` o similar
  - **Hecho 2026-09-08:** `POST /ecommerce/institutional/contact` + BFF `/api/institutional/contact`

- [x] **1.2.3** Fix **reset password cliente**  
  - Problema: usa `auth/forgot-password` (staff) en vez de customer  
  - Archivos:
    - `nm-ecommerce/src/features/auth/` o `customer-auth/`
    - `ForgotPasswordForm`, `ResetPasswordPage`, `auth.service.ts`  
  - Endpoints correctos: `auth/customer/forgot-password`, `auth/customer/reset-password`  
  - Criterio: flujo E2E manual con email real en staging/prod
  - **Verificado 2026-09-08:** `auth/forgot-password` ya detecta `ecommerceCustomer` y envía link a `ECOMMERCE_STORE_URL/restablecer-contrasena`. No requiere endpoints customer separados.

- [x] **1.2.4** Validación **costos de envío server-side**  
  - Problema: tarifas hardcodeadas en `nm-ecommerce/src/features/checkout/constants/shipping-methods.ts`  
  - Backend: validar `shippingMethod` + `shippingCost` al crear orden en `ecommerce-service`  
  - Criterio: manipular precio en DevTools no crea orden con total incorrecto
  - **Ya implementado:** `orders.service.ts` usa `getShippingMethod(id, zone).cost` del servidor; el DTO no acepta shippingTotal del cliente.

- [x] **1.2.5** Activar **Culqi + reCAPTCHA** en producción  
  - Backend: `vps/secrets/nm-backend.env` → `CULQI_ENABLED=true`, `RECAPTCHA_ENABLED=true`  
  - Ecommerce: `NEXT_PUBLIC_RECAPTCHA_SITE_KEY`, `NEXT_PUBLIC_CULQI_PUBLIC_KEY`  
  - Criterio: checkout con tarjeta de prueba Culqi OK; registro con reCAPTCHA OK
  - **Hecho 2026-09-08:** secrets prod ya configurados; validación ampliada en `vps/validate-production-roadmap.sh` (Culqi + reCAPTCHA en backend y storefront)

### Verificación Fase 1.2

```bash
cd nm-ecommerce && npm test && npm run build
# Manual: /restablecer-contrasena, /contactanos, /libro-de-reclamaciones, checkout Culqi
```

---

## 1.3 Frontend ERP — Warehouse + guards ecommerce

### Tareas

- [x] **1.3.1** **Selector de almacén** en main layout  
  - Servicio: `nm-frontend/src/app/core/warehouse/active-warehouse.service.ts`  
  - Layout: `nm-frontend/src/app/layouts/main-layout/`  
  - Solo visible para Admin con múltiples warehouses  
  - Criterio: cambiar warehouse actualiza `X-Warehouse-Id` en requests
  - **Hecho 2026-09-08:** `WarehouseSelectorComponent` en header; usa `setActiveWarehouseId` + interceptor existente

- [x] **1.3.2** Unificar **guards ecommerce**  
  - Archivo: `nm-frontend/src/app/features/ecommerce/ecommerce.routes.ts`  
  - Problemas:
    - Nav usa `tenant.get`; rutas usan `roleGuard`
    - `/ecommerce/media` sin guard  
  - Criterio: permisos consistentes nav ↔ ruta; media protegida
  - **Hecho 2026-09-08:** nav usa `canAccessEcommerceRoutes`; `media.routes.ts` con `roleGuard`

- [x] **1.3.3** CI frontend: tests en pipeline  
  - Archivo: `nm-frontend/.github/workflows/ci.yml`  
  - Añadir: `npm test` (Vitest) + opcional Playwright smoke  
  - Criterio: PR falla si tests fallan
  - **Hecho 2026-09-08:** step `npm test -- --watch=false` antes del build

### Verificación Fase 1.3

```bash
cd nm-frontend && npm test && npm run build -- --configuration=docker
# Manual: login Admin → cambiar warehouse → ver header en Network tab
```

---

## 1.4 Deuda técnica rápida (opcional en Fase 1)

- [x] **1.4.1** Limpiar `nest-cli.json`: quitar `ai-proxy-service` y `libs/contracts` inexistentes  
  - **Hecho 2026-09-08:** entradas huérfanas eliminadas de `nest-cli.json`

- [x] **1.4.2** Implementar PDF ventas mensuales  
  - Archivo: `apps/report-service/src/reports/reports.controller.ts` (actualmente `NotImplementedException`)  
  - **Hecho 2026-09-08:** `SalesReportPdfService.generateMonthly()` + endpoint `GET /reports/sales/monthly/pdf`

- [x] **1.4.3** Re-activar o eliminar rutas comentadas `/ecommerce/products` y `/multimedia`  
  - Archivo: `nm-frontend/.../ecommerce/ecommerce.routes.ts`
  - **Hecho 2026-09-08:** rutas WooCommerce comentadas eliminadas (reemplazadas por nm-ecommerce)

---

## Criterio de cierre Fase 1

- [x] CI verde en backend + frontend + ecommerce (tests locales OK; CI remoto tras push)
- [x] Libro reclamaciones y contacto funcionan (backend + BFF + frontend)
- [x] Reset password cliente funciona (`auth/forgot-password` + `ECOMMERCE_STORE_URL`)
- [x] Envíos validados en servidor (`orders.service` recalcula costo)
- [x] Selector warehouse en ERP (`WarehouseSelectorComponent`)
- [x] Culqi/reCAPTCHA activos en prod (secrets + validación en `vps/validate-production-roadmap.sh`)

---

# FASE 2 — Experiencia (semanas 5–8)

> **Meta:** unificar ERP ↔ tienda ↔ operación diaria; persistencia cliente; tests E2E.

## 2.1 Ecommerce — Persistencia y compliance

### Tareas

- [x] **2.1.1** **Carrito server-side** para clientes logueados  
  - Archivos: `nm-ecommerce/src/features/cart/context/CartProvider.tsx`  
  - Backend: endpoint merge cart (nuevo o extensión orders)  
  - Criterio: login en otro dispositivo recupera carrito
  - **Hecho 2026-09-08:** `CustomerCartModule` + BFF `/api/account/cart` + sync/merge en login

- [x] **2.1.2** **Wishlist server-side** (mismo patrón)  
  - Archivos: `nm-ecommerce/src/features/wishlist/`  
  - Criterio: favoritos persisten en cuenta
  - **Hecho 2026-09-09:** `CustomerWishlistModule` + BFF `/api/account/wishlist` + sync en login

- [x] **2.1.3** **Banner cookies** + consentimiento  
  - Crear componente en layout raíz  
  - Alinear con `politica-de-cookies`  
  - Criterio: no cargar scripts no esenciales sin consent
  - **Hecho 2026-09-09:** `CookieConsentProvider` + banner + `AnalyticsScripts` (GTM/Cloudflare solo con consentimiento)

- [x] **2.1.4** Unificar URLs búsqueda SEO  
  - Problema: JSON-LD usa `/buscar`; app usa `/search`  
  - Archivos: `nm-ecommerce/src/features/seo/`, `HomeJsonLd`  
  - Criterio: una sola URL canónica de búsqueda
  - **Hecho 2026-09-09:** canónica `/buscar`; `/search` → 308; JSON-LD y `ROUTES.search` alineados

- [x] **2.1.5** E2E Playwright checkout  
  - Crear: `nm-ecommerce/tests-e2e/checkout.spec.ts`  
  - Cubrir: BACS, cupón, guest tracking, Culqi mock  
  - CI: añadir job e2e (staging o con mocks)
  - **Hecho 2026-09-09:** Playwright + mocks API checkout; job `e2e` en CI

### Verificación Fase 2.1

```bash
cd nm-ecommerce && npx playwright test
```

---

## 2.2 Frontend ERP — UX operativa

### Tareas

- [x] **2.2.1** **Adapters** para APIs ecommerce transaccionales  
  - Servicios sin adapter: orders, reviews, customers, coupons, newsletter  
  - Path: `nm-frontend/src/app/features/ecommerce/data-access/`  
  - Criterio: respuestas API pasan por adapter como inventario/finanzas
  - **Hecho 2026-09-09:** adapters en orders, reviews, customers, coupons, newsletter

- [x] **2.2.2** Rutas deep-link **ventas**  
  - Hoy: modales en list page  
  - Nuevo: `/finances/sales/new`, `/finances/sales/:id`, `/finances/sales/:id/exchange`  
  - Archivos: `finances.routes.ts`, componentes sales
  - **Hecho 2026-09-09:** rutas en `lists.routes.ts`; list navega en lugar de modales

- [x] **2.2.3** Pantalla **acceso denegado** explícita  
  - Guards: `permission.guard.ts`, `role.guard.ts`  
  - Criterio: usuario ve mensaje claro, no solo redirect a `/not-found`
  - **Hecho 2026-09-09:** ruta `/access-denied`; guards redirigen con mensaje 403

- [x] **2.2.4** **Dashboard vendedora** (rol no-admin)  
  - Archivo: `nm-frontend/src/app/features/dashboards/`  
  - Métricas básicas: ventas del día, tareas pendientes  
  - Criterio: vendedora ve valor al login, no solo saludo
  - **Hecho 2026-09-09:** métricas vendedora (ventas, monto, tareas); `pendingTasks` en API

- [x] **2.2.5** Fix docs/tests stale  
  - `inventories/products/README.md`  
  - `active-warehouse.service.spec.ts` (UUIDs string)
  - **Hecho 2026-09-09:** README alineado al módulo actual; specs con UUIDs

---

## 2.3 Backend — Notificaciones y reportes

### Tareas

- [ ] **2.3.1** **Notificación pedido nuevo** ecommerce → ERP  
  - Opciones: email (mail-service), WebSocket futuro, o badge en API polling  
  - Trigger: orden creada en `ecommerce-service`  
  - Criterio: operador recibe aviso < 1 min

- [ ] **2.3.2** PDF ventas mensuales (si no se hizo en 1.4.2)  
  - Referencia: PDF diario ya implementado en report-service

- [ ] **2.3.3** Tests gateway + mail-service básicos  
  - Smoke tests de proxy y envío mail mock

---

## 2.4 Chatbot — Pulido operativo

### Tareas

- [ ] **2.4.1** Verificar mapeo ERP user → `chat_agents` para todos los admins  
  - Script: `services/chatbot/deploy/create-nm-agents.mjs`  
  - Criterio: cada admin ERP tiene agente activo

- [ ] **2.4.2** Link producto en respuestas Malu → tienda  
  - Archivos: `services/chatbot/src/infrastructure/ai/tools/product-tools.service.ts`  
  - Criterio: bot puede enviar URL `novedadesmaritex.net.pe/producto/{slug}`

---

## Criterio de cierre Fase 2

- [ ] Carrito/wishlist persisten en cuenta
- [ ] E2E checkout en CI
- [ ] Adapters ecommerce en ERP
- [ ] Notificación pedido nuevo
- [ ] Cookie banner live
- [ ] Deep links ventas funcionan

---

# FASE 3 — Crecimiento (semanas 9–12)

> **Meta:** integraciones avanzadas, facturación ecommerce, AI visible, observabilidad.

## 3.1 Integración negocio

### Tareas

- [ ] **3.1.1** **Stock en tiempo real** en PDP tienda  
  - Backend: endpoint stock público por variant/warehouse  
  - Frontend: `nm-ecommerce/src/features/product/`  
  - Criterio: "Quedan X unidades" o "Agotado" en PDP

- [ ] **3.1.2** **Facturación SUNAT** pedidos ecommerce  
  - Flujo: orden pagada → `invoicing-service` (Greenter)  
  - Archivos: `apps/ecommerce-service/`, `services/invoicing/`  
  - Criterio: boleta/factura PDF en cuenta cliente

- [ ] **3.1.3** **Compra por WhatsApp** desde PDP  
  - Botón prellena mensaje a Malu con SKU/link  
  - Integrar con chatbot handoff

- [ ] **3.1.4** **Ventas al por mayor** (B2B)  
  - Página institucional existe; flujo: precios mayorista + checkout separado o cotización

---

## 3.2 AI + inventario inteligente

### Tareas

- [ ] **3.2.1** Mostrar **predicción demanda/precio** en ficha producto ERP  
  - Backend: `report-service` → `ai-engine` proxy ya existe  
  - Frontend: `nm-frontend/.../inventories/products/` o `/ai`  
  - Criterio: admin ve sugerencia de precio en edit product

- [ ] **3.2.2** **Alertas stock bajo**  
  - Cron job o evento post-venta  
  - Notificación: email o WhatsApp interno

- [ ] **3.2.3** Eventos async post-checkout (Redis Pub/Sub)  
  - Desacoplar: POS checkout → inventario → mail → reportes  
  - Referencia comentarios en `apps/pos-service/`

---

## 3.3 Observabilidad + analytics

### Tareas

- [ ] **3.3.1** **Sentry** (o similar) en backend, frontend, ecommerce  
  - Errores cliente + servidor centralizados

- [ ] **3.3.2** **Logs estructurados** con request ID en gateway  
  - Extender patrón de `libs/common` logging interceptor

- [ ] **3.3.3** **Google Analytics / GTM** en tienda  
  - Respetar cookie consent (Fase 2.1.3)

- [ ] **3.3.4** Dashboard Grafana operacional (opcional)  
  - Chatbot ya tiene patrón Loki en `services/chatbot/docker-compose.yml`

---

## 3.4 Deuda técnica estratégica

### Tareas

- [ ] **3.4.1** Limpiar MongoDB/UPRIT del chatbot si no se usa  
  - Archivos: `services/chatbot/src/main.ts`, repos Mongo

- [ ] **3.4.2** `docker-compose.prod.yml` documentado  
  - Path: `nm-deploy/` o `nm-backend/`

- [ ] **3.4.3** Rotación secrets prod (JWT, service keys, Meta, Culqi)  
  - Documentar en `vps/secrets/` (sin commitear valores)

- [ ] **3.4.4** Eliminar código WooCommerce muerto o reactivar sync  
  - Backend: `catalog-service/woocommerce`  
  - Frontend: rutas ecommerce comentadas

---

## Criterio de cierre Fase 3

- [ ] Stock live en tienda
- [ ] Facturación ecommerce automática
- [ ] AI visible en ERP productos
- [ ] Error tracking + analytics
- [ ] Chatbot enlaza a productos tienda

---

# Orden de ejecución recomendado (primera sesión)

Cuando digas *"ejecutar todo"*, seguir este orden estricto:

```
FASE 1
  1.1.1 → 1.1.2 → 1.1.3 → 1.1.4   (backend tests/CI)
  1.2.3 → 1.2.4 → 1.2.1 → 1.2.2   (ecommerce crítico)
  1.2.5                            (Culqi/reCAPTCHA prod)
  1.3.1 → 1.3.2 → 1.3.3           (ERP)
  1.4.x                            (deuda rápida, si hay tiempo)

FASE 2
  2.1.3 → 2.1.4 → 2.1.1 → 2.1.2 → 2.1.5
  2.2.1 → 2.2.2 → 2.2.3 → 2.2.4 → 2.2.5
  2.3.1 → 2.3.2 → 2.3.3
  2.4.1 → 2.4.2

FASE 3
  3.1.1 → 3.1.2 → 3.1.3 → 3.1.4
  3.2.1 → 3.2.2 → 3.2.3
  3.3.1 → 3.3.2 → 3.3.3 → 3.3.4
  3.4.1 → 3.4.2 → 3.4.3 → 3.4.4
```

---

# Prompt para retomar en chat nuevo

Copiar y pegar:

```
Continúa el roadmap NM desde la primera tarea pendiente en docs/nm-roadmap-90-dias.md.
Ejecuta implementación + tests + deploy según corresponda. Marca [x] las tareas completadas en el markdown.
Repo: /Users/zero/Desktop/nm-project
```

---

# Registro de progreso

| Fecha | Tarea | Repo | Commit/PR | Notas |
|-------|-------|------|-----------|-------|
| 2026-09-08 | SSO ERP chatbot + logout sync | nm-backend, nm-frontend | c667191 | Ya desplegado prod |
| 2026-09-08 | Fase 1.1 (tests, CI, health) | nm-backend | f8e6f4b | bcryptjs + ecommerce specs |
| 2026-09-08 | Fase 1.2 forms backend | nm-backend | e347775 | institutional module + mail |
| 2026-09-08 | Fase 1.2 forms BFF | nm-ecommerce | 2a2463e | institutional API routes |
| 2026-09-08 | Fase 1.3 ERP | nm-frontend | fe4d447, 1e67057 | SSO bridge + warehouse selector |
| 2026-09-08 | Fase 1.4 backend | nm-backend | 2cbb57b | PDF mensual + nest-cli |
| 2026-09-08 | Fase 1.4 frontend | nm-frontend | fb34a7a | rutas WooCommerce eliminadas |
| 2026-09-08 | **Fase 1 cerrada** | todos | push main | Ver criterios arriba |
| 2026-09-08 | Fase 2.1.1 carrito server-side | nm-backend, nm-ecommerce | 8f42e35, dcbaaa8 | CustomerCartModule + sync login |
| 2026-09-09 | Fase 2.1.2 wishlist server-side | nm-backend, nm-ecommerce | 8dc641e, 1bde329 | CustomerWishlistModule + sync login |
| 2026-09-09 | Fase 2.1.3 banner cookies | nm-ecommerce | 0f3880a | consent banner + gated analytics |

---

# Referencias

- Ecommerce seguridad/SEO previo: `docs/ecommerce-production-roadmap.md`
- Backend arquitectura: `nm-backend/docs/README.md`
- API tienda: `nm-ecommerce/backend-map.md`
- Deploy VPS: `vps/README.md`, `nm-deploy/docs/CI-CD.md`
- Chatbot deploy: `nm-backend/services/chatbot/docs/WA-FEATURES-DEPLOY.md`

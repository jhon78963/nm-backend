# Análisis de Bounded Contexts — nm-backend → nm-services (NestJS)

## Mapa de migración Laravel → Microservicios NestJS

| Microservicio NestJS | Dominio Laravel origen | Tablas DB principales | Integraciones |
|---|---|---|---|
| `apps/auth-service` | `app/Auth/`, `app/Administration/`, `app/Profile/` | `users`, `roles`, `permissions`, `tenants`, `warehouses`, `refresh_tokens`, `user_action_logs` | Sanctum → JWT dual-token |
| `apps/catalog-service` | `app/Inventory/Product*`, `app/Inventory/Color*`, `app/Inventory/Size*` | `products`, `product_size`, `product_size_color`, `colors`, `sizes`, `genders` | storage-service (media), nm-ecommerce (`woo_status`) |
| `apps/inventory-service` | `app/Inventory/Purchase*`, `app/Inventory/Inventory*`, `app/Inventory/Warehouse` | `inventory_balances`, `inventory_movements`, `purchases`, `purchase_lines`, `purchase_line_color_deltas` | catalog-service (eventos) |
| `apps/pos-service` | `app/Finance/Sale*`, `app/Finance/ElectronicDocument*`, `app/Finance/DocumentSeries` | `sales`, `sale_details`, `sale_payments`, `document_series`, `electronic_document_logs` | **Greenter/SUNAT** (integración crítica) |
| `apps/finance-service` | `app/Finance/CashMovement*`, `app/Finance/AccumulatedAccount*`, `app/Finance/FinancialSummary` | `cash_movements`, `cash_movement_vouchers`, `accumulated_account_settings`, `accumulated_account_transfers` | Node uploader (vouchers) |
| `apps/hr-service` | `app/Directory/Team*`, `app/Directory/Customer`, `app/Directory/Vendor` | `teams`, `attendances`, `team_payments`, `customers`, `vendors` | finance-service (TeamPayment → CashMovement) |
| `apps/report-service` | `app/Report/`, `app/Dashboard/` | Vistas agregadas de todas las tablas | DomPDF → `@react-pdf/renderer` o `pdfkit` |
| `apps/ai-proxy-service` | `app/Ai/` | Sin tablas propias | nm_ai_engine HTTP |
| `apps/gateway` | `routes/api.php` (loader) | Sin tablas propias | Todos los servicios |

---

## Estrategia de aislamiento de integraciones externas

### 1. Greenter / SUNAT (Facturación Electrónica)
**Problema:** Greenter es una librería PHP. No existe equivalente directo en Node/TypeScript.

**Solución propuesta (3 opciones en orden de preferencia):**

**Opción A (Recomendada): Mantener nm-backend solo para SUNAT**
```
pos-service (NestJS) ──HTTP POST──> nm-backend (Laravel, solo /api/fiscal/*)
```
- Migración incremental sin riesgo fiscal
- nm-backend se convierte en un **microservicio de facturación** dedicado
- Tiempo: ~1 semana de refactor en Laravel

**Opción B: Librería TypeScript equivalente**
- [`node-sunat`](https://github.com/) o implementar el UBL 2.1 con `xmlbuilder2`
- Firmar con `node-forge` o `@peculiar/x509`
- Riesgo: SUNAT requiere certificados específicos; alta complejidad de validación

**Opción C: PHP sidecar vía microservicio**
```
pos-service (NestJS) ──gRPC/HTTP──> sunat-sidecar (PHP Artisan command)
```

### 2. Tienda online (nm-ecommerce)
- La visibilidad en tienda usa `products.woo_status` (`draft` / `publish`) — legacy del nombre WooCommerce, sin sync externo
- Catálogo público: `ecommerce-service` + `nm-ecommerce` (Next.js)

### 3. Node Uploader (ya es Node)
- Integrar directamente en `catalog-service` y `finance-service` como cliente HTTP
- O reemplazar con un módulo `@nestjs/serve-static` + S3/MinIO

### 4. AI Engine (nm_ai_engine)
- `ai-proxy-service` hace proxy HTTP simple (sin transformación de datos)
- Implementación: `@nestjs/axios` con un módulo de configuración

---

## Comunicación inter-servicio

```
Gateway (REST) ─┬─> auth-service      (HTTP interno / puerto 3001)
                ├─> catalog-service   (HTTP interno / puerto 3002)
                ├─> inventory-service (HTTP interno / puerto 3003)
                ├─> pos-service       (HTTP interno / puerto 3004)
                ├─> finance-service   (HTTP interno / puerto 3005)
                ├─> hr-service        (HTTP interno / puerto 3006)
                ├─> report-service    (HTTP interno / puerto 3007)
                └─> ai-proxy-service  (HTTP interno / puerto 3008)
```

**Fase 1 (actual):** HTTP directo entre servicios (simple, testeable)  
**Fase 2:** Redis Pub/Sub para eventos asincrónicos (ej: Sale creada → inventory-service descuenta stock)  
**Fase 3:** NATS o RabbitMQ para mensajería garantizada

---

## Roadmap de migración (sprints de 2 semanas)

| Sprint | Entregable | Script |
|--------|-----------|--------|
| S1 | Monorepo + auth-service | `01-setup-monorepo.sh`, `02-scaffold-auth-service.sh` |
| S2 | catalog-service | `03-scaffold-catalog-service.sh` |
| S3 | inventory-service (ledger + purchases) | `04-scaffold-inventory-service.sh` |
| S4 | pos-service (checkout + tickets) | `05-scaffold-pos-service.sh` |
| S5 | finance-service (cashflow + acumulados) | `06-scaffold-finance-service.sh` |
| S6 | hr-service + report-service | `07-scaffold-hr-report-service.sh` |
| S7 | gateway + e2e tests + deployment | `08-scaffold-gateway.sh` |
| S8 | Sunat sidecar + migración de datos | `09-data-migration.sh` |

---

## Comandos Git — Convención Conventional Commits

```bash
# ── Hito 1: Bootstrap del monorepo ──────────────────────────────────────────
git add nest-cli.json tsconfig.json package.json .env.example
git commit -m "chore: initialize nestjs monorepo with fastify, prisma and jwt"

# ── Hito 2: Infraestructura compartida ──────────────────────────────────────
git add libs/
git commit -m "feat(libs): add common guards, decorators, filters and database module"

# ── Hito 3: Auth Service (código fuente) ─────────────────────────────────────
git add apps/auth-service/src/ libs/database/prisma/schema.prisma
git commit -m "feat(auth): implement jwt authentication with refresh token rotation and rbac"

# ── Hito 4: Auth Service (tests) ─────────────────────────────────────────────
git add apps/auth-service/src/**/*.spec.ts
git commit -m "test(auth): add unit tests for auth service, controller and users service"

# ── Hito 5: Scripts de scaffolding ───────────────────────────────────────────
git add scripts/
git commit -m "chore(scripts): add idempotent bash scripts for monorepo and auth service setup"
```

# nm-services — Migration Kit: nm-backend (Laravel) → NestJS Microservicios

Kit completo de migración de **Novedades Maritex** desde Laravel 12 + PostgreSQL
a un monorepo NestJS con microservicios, Fastify, Prisma y JWT.

---

## Arquitectura

```
Angular Frontend (nm-frontend-v2 :4200)
          │
          ▼
  Gateway (:3000) ─── JWT Validation ─── Rate Limiting
          │
    ┌─────┼──────────────────────────────────────────┐
    ▼     ▼     ▼         ▼         ▼      ▼      ▼
 auth  catalog inventory  pos    finance   hr   report
 :3001  :3002    :3003   :3004    :3005  :3006  :3007
    │                      │
    │              nm-backend (Laravel)
    │              Greenter / SUNAT sidecar
    │
 PostgreSQL (compartido en dev, separado en prod)
```

---

## Microservicios

| Servicio | Puerto | Dominio | Laravel equivalente |
|---|---|---|---|
| `gateway` | 3000 | BFF proxy | `routes/api.php` |
| `auth-service` | 3001 | Auth, Tenants, RBAC, Auditoría | `app/Auth/`, `app/Administration/` |
| `catalog-service` | 3002 | Productos, Colores, Tallas | `app/Inventory/Product*` |
| `inventory-service` | 3003 | Ledger, Compras, Kardex | `app/Inventory/Purchase*`, `app/Inventory/Inventory*` |
| `pos-service` | 3004 | Checkout, Ventas, SUNAT proxy, Tickets | `app/Finance/Sale*`, `PosController` |
| `finance-service` | 3005 | Flujo de caja, Cuentas acumuladas | `app/Finance/CashMovement*` |
| `hr-service` | 3006 | Equipos, Asistencia, Planilla, Clientes, Proveedores | `app/Directory/` |
| `report-service` | 3007 | Dashboard, Reportes, AI proxy | `app/Report/`, `app/Ai/` |

---

## Inicio rápido

```bash
# 1. Clonar / posicionarse en el directorio
cd nm-nestjs-migration

# 2. Infraestructura local
docker-compose up -d postgres redis

# 3. Configurar entorno
cp .env.example .env
# Editar .env con tus credenciales

# 4. Inicializar monorepo (si no lo hiciste aún)
./scripts/01-setup-monorepo.sh .

# 5. Generar cliente Prisma y migrar DB
npx prisma generate
npx prisma migrate dev --name init

# 6. Levantar todos los servicios en desarrollo
npm run start:dev:gateway    # Puerto 3000 (BFF)
npm run start:dev:auth       # Puerto 3001

# 7. Abrir Swagger
open http://localhost:3000/api/docs

# 8. Health check
curl http://localhost:3000/health/services
```

---

## Scripts de scaffolding

Ejecutar en orden en la raíz del monorepo NestJS:

```bash
./scripts/01-setup-monorepo.sh      # Monorepo base + dependencias
./scripts/02-scaffold-auth-service.sh
./scripts/03-scaffold-catalog-service.sh
./scripts/04-scaffold-inventory-service.sh
./scripts/05-scaffold-pos-service.sh
./scripts/06-scaffold-finance-service.sh
./scripts/07-scaffold-hr-service.sh
./scripts/08-scaffold-gateway.sh    # Incluye report-service + docker-compose
```

---

## Tests

```bash
npm run test:auth       # auth-service (22 casos)
npm run test:catalog    # catalog-service (10 casos)
npm run test:inventory  # inventory-service (13 casos)
npm run test:pos        # pos-service (10 casos)
npm run test:finance    # finance-service (11 casos)
npm run test:hr         # hr-service (12 casos)
npm run test:all        # Suite completa (~80 casos)
npm run test:cov        # Con cobertura de código
```

---

## Estrategia SUNAT / Greenter

El `nm-backend` (Laravel) actúa como **sidecar de facturación electrónica** durante la migración:

```
pos-service (NestJS) ──POST /api/fiscal/emit──> nm-backend (Laravel + Greenter)
                      ──POST /api/fiscal/void──>
                      ──GET  /api/fiscal/lookup/dni/{dni}──>
```

El `SunatService` (`apps/pos-service/src/sunat/sunat.service.ts`) centraliza
todas las llamadas. Cuando se implemente el equivalente TypeScript,
solo se cambia este archivo.

---

## Variables de entorno clave

| Variable | Descripción |
|---|---|
| `DATABASE_URL` | PostgreSQL connection string |
| `JWT_SECRET` | Secret del access token (mín. 32 chars) |
| `JWT_REFRESH_SECRET` | Secret del refresh token (distinto del anterior) |
| `SUNAT_BACKEND_URL` | URL del nm-backend Laravel (sidecar SUNAT) |
| `ECOMMERCE_STORE_URL` | URL pública de la tienda (nm-ecommerce) |
| `AI_ENGINE_URL` | URL del nm_ai_engine (Python/FastAPI) |
| `UPLOADER_URL` | URL del Node uploader (media/vouchers) |

---

## Commits convencionales — estándar del proyecto

```
feat(auth):      Nueva funcionalidad en auth-service
feat(catalog):   Nueva funcionalidad en catalog-service
fix(pos):        Corrección de bug en pos-service
test(finance):   Tests del finance-service
chore(scripts):  Scripts de infraestructura
refactor(hr):    Refactoring sin cambio de comportamiento
docs:            Documentación
```

---

## Roadmap pendiente

- [ ] `09-data-migration.sh` — Scripts de migración de datos Laravel → Prisma
- [ ] Separación de DBs por servicio (PostgreSQL schemas o bases separadas)
- [ ] Eventos inter-servicio con Redis Pub/Sub (checkout → inventory)
- [ ] CI/CD GitHub Actions con `npm run test:all`
- [ ] Implementación nativa UBL 2.1 (reemplazar sidecar SUNAT)
- [ ] Kubernetes manifests para producción en Google Cloud

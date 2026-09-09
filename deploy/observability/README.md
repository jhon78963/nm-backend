# Observabilidad NM Services — Loki + Promtail + Grafana

Stack centralizado para **todos los microservicios** del monorepo `nm-backend` cuando corren con Docker Compose.

## Qué cubre

Promtail recolecta logs de contenedores con label `logging=promtail`, incluyendo:

- `gateway`, `auth-service`, `catalog-service`, `inventory-service`
- `pos-service`, `finance-service`, `hr-service`, `report-service`
- `ecommerce-service`, `mail-service`, `document-service`, `storage-service`
- `invoicing-service`, `ai-engine`, `chatbot` (nm_chatbot)

Los logs JSON estructurados (request ID, `service`, `event`, `statusCode`) de la tarea **3.3.2** se indexan en Loki para consultas en Grafana.

## Levantar el stack

Desde la raíz de `nm-backend`:

```bash
docker compose -f docker-compose.full.yml --profile observability up -d loki promtail grafana
```

Con el stack de apps ya corriendo:

```bash
docker compose -f docker-compose.full.yml --profile observability up -d
```

## Acceso

| Servicio | URL | Credenciales default |
|----------|-----|----------------------|
| Grafana | http://localhost:3010 | `admin` / `admin` (cambiar en prod) |
| Loki | http://localhost:3100 | sin auth (solo red interna) |

Variables en `.env`:

```bash
GRAFANA_PORT=3010
GRAFANA_ADMIN_USER=admin
GRAFANA_ADMIN_PASSWORD=admin
GRAFANA_ROOT_URL=http://localhost:3010
```

## Dashboard incluido

- **NM Services — Overview** (`nm-services-overview`)
  - Errores 24 h por servicio
  - Proxy fallidos y HTTP 5xx en gateway
  - Volumen de logs por microservicio
  - Streams de errores y tráfico gateway

## Consultas útiles (Explore → Loki)

```logql
# Errores de un servicio
{app="nm-services", service="ecommerce-service"} | json | level="error"

# Trazar un request por ID
{app="nm-services"} | json | requestId="uuid-aqui"

# Eventos de checkout async
{app="nm-services"} | json | event=~"gateway.proxy.*"
```

## Producción

- Cambiar credenciales Grafana y exponer tras reverse proxy (HTTPS).
- El chatbot UPRIT mantiene su stack Loki propio en `services/chatbot/docker-compose.yml`; este stack es para **NM Maritex**.
- Opcional: unificar ambos en un solo Loki en prod si comparten VPS.

## Chatbot legacy

El patrón original vive en `services/chatbot/deploy/` (referencia roadmap 3.3.4). Esta carpeta lo generaliza al resto de microservicios NM.

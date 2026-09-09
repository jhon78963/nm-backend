# Observabilidad NM Services — Loki + Promtail + Grafana

Stack centralizado para **todos los microservicios** NM cuando corren con Docker Compose.

## Arquitectura

| Capa | Ubicación | Rol |
|------|-----------|-----|
| Configs (Loki, Promtail, dashboards) | `nm-backend/deploy/observability/` | Fuente de verdad |
| Contenedores (Loki, Promtail, Grafana) | `nm-deploy/docker-compose.observability.yml` | Orquestación de plataforma |
| Labels de scrape | `nm-backend/docker-compose.full.yml` | `logging=promtail`, `app=nm-services` |

## Qué cubre

Promtail recolecta logs de contenedores con label `logging=promtail`, incluyendo:

- `gateway`, `auth-service`, `catalog-service`, `inventory-service`
- `pos-service`, `finance-service`, `hr-service`, `report-service`
- `ecommerce-service`, `mail-service`, `document-service`, `storage-service`
- `invoicing-service`, `ai-engine`, `chatbot` (nm_chatbot)

Los logs JSON estructurados (request ID, `service`, `event`, `statusCode`) de la tarea **3.3.2** se indexan en Loki para consultas en Grafana.

## Levantar el stack

Desde `nm-deploy` (recomendado — stack unificado):

```bash
cd nm-deploy
docker compose --profile observability up -d
```

Con reverse-proxy en producción:

```bash
docker compose --profile edge --profile observability up -d --build
```

Solo backend en local (sin tienda/admin):

```bash
cd nm-backend
docker compose -f docker-compose.full.yml up -d
# Observabilidad: usar nm-deploy con --profile observability
```

## Acceso

| Entorno | Grafana | Loki (interno) |
|---------|---------|----------------|
| Local | http://localhost:3010 | http://localhost:3100 |
| Prod + edge | https://grafana.novedadesmaritex.net.pe | solo red Docker |

Credenciales default: `admin` / `admin` — **cambiar en prod**.

Variables en `nm-deploy/.env`:

```bash
GRAFANA_PORT=3010
GRAFANA_ADMIN_USER=admin
GRAFANA_ADMIN_PASSWORD=admin
GRAFANA_ROOT_URL=http://localhost:3010
# Prod:
# GRAFANA_ROOT_URL=https://grafana.novedadesmaritex.net.pe
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

- Cambiar credenciales Grafana antes de exponer.
- DNS: `grafana.novedadesmaritex.net.pe` → VPS (mismo certificado wildcard o SAN del dominio principal).
- El chatbot UPRIT mantiene su stack Loki propio en `services/chatbot/docker-compose.yml`; este stack es para **NM Maritex**.

## Chatbot legacy

El patrón original vive en `services/chatbot/deploy/` (referencia roadmap 3.3.4). Esta carpeta lo generaliza al resto de microservicios NM.

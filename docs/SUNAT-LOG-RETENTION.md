# Logs SUNAT y retención de datos

## Tabla `electronic_document_logs`

| Campo | Contenido | PII |
|-------|-----------|-----|
| `sale_id` | ID de venta | No |
| `action` | `ISSUE`, `VOID`, `CHECK_STATUS` | No |
| `request_payload` | Payload enviado a SUNAT (si aplica) | Redactado vía `SunatLogRedactor` |
| `response_payload` | Códigos/descripciones SUNAT, errores | Redactado (sin RUC/DNI/nombres completos) |
| `sunat_code` | Código de respuesta SUNAT | No |

Los payloads **no** almacenan XML completo ni datos del receptor en texto claro. RUC, DNI, nombres y direcciones se enmascaran antes de persistir.

## Archivos XML/CDR

Los XML firmados y CDR se guardan en `storage/app/sunat/xml/` y `storage/app/sunat/cdr/`. Contienen datos fiscales necesarios para la operación; el acceso debe limitarse al servidor y backups cifrados.

## Consultas DNI/RUC (API externa)

`SunatService` no persiste respuestas de apis.net.pe en logs de aplicación. Los clientes creados en BD siguen la política general de `customers`.

## Retención recomendada

- `electronic_document_logs`: conservar 12 meses (alineado con plazos de consulta SUNAT habituales); purgar o archivar filas antiguas con job programado.
- XML/CDR en disco: misma ventana que comprobantes electrónicos exigidos por normativa local.

## Depuración

En entornos no productivos, `LOG_LEVEL=debug` puede ampliar detalle en `storage/logs/laravel.log`. En producción usar `LOG_LEVEL=warning` (ver `.env.example`).

# Checklist de seguridad pre-producción

Usar antes de cada despliegue a producción del backend (`nm-backend`).  
Complementa `.env.example` (sección **DEPLOY SECURITY**) y el middleware `SecurityHeaders` (SEC-004).

Validación automática parcial:

```bash
php artisan config:check-security
```

El comando falla si detecta configuración insegura según la política de producción.

---

## Checklist (10 ítems verificables)

### 1. Entorno y depuración

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_KEY` generado (`php artisan key:generate`) — no usar el placeholder de `.env.example`

### 2. HTTPS y URL pública

- [ ] `APP_URL` usa `https://` (dominio real del API)
- [ ] El balanceador / nginx termina TLS y redirige HTTP → HTTPS

### 3. Cookies de sesión

- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `SESSION_ENCRYPT=true`
- [ ] `SESSION_HTTP_ONLY=true` (valor por defecto en Laravel; no desactivar)

### 4. SPA en subdominio distinto al API

Si el frontend (`adm.*`) y el API (`api.*`) no comparten origen:

- [ ] `SESSION_DOMAIN=.tudominio.com` (punto inicial para compartir cookie entre subdominios)
- [ ] `SESSION_SAME_SITE=none` (requiere `SESSION_SECURE_COOKIE=true`)
- [ ] Probar login desde el SPA en HTTPS y confirmar que la cookie de sesión llega al API

### 5. Sanctum stateful

- [ ] `SANCTUM_STATEFUL_DOMAINS` incluye **todos** los hostnames del SPA (sin `https://`), p. ej. `adm.tudominio.com`
- [ ] No depender solo de los defaults de desarrollo en `config/sanctum.php`

### 6. CORS

- [ ] `CORS_ALLOWED_ORIGINS` lista orígenes explícitos del SPA (`https://adm.tudominio.com`)
- [ ] No usar `*` (incompatible con `supports_credentials: true`)

### 7. Security headers HTTP (SEC-004)

El middleware `App\Shared\Foundation\Middleware\SecurityHeaders` añade en rutas `api/*`:

| Header | Valor |
|--------|--------|
| `Strict-Transport-Security` | Solo si `APP_ENV=production` |
| `X-Frame-Options` | `DENY` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | Restricción básica (cámara, micrófono, geolocalización, payment) |

- [ ] Verificar con `curl -I https://api.tudominio.com/api/auth/csrf-token` que los headers aparecen
- [ ] **Nginx (opcional):** puede duplicar o reforzar los mismos headers en el `server` del API, p. ej.:

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()" always;
```

> **Nota:** La CSP completa del frontend se documenta en SEC-005 (nginx del SPA), no aquí.

### 8. Logs

- [ ] `LOG_LEVEL=warning` (o `error`) en producción — evitar volcado verbose de excepciones con PII

### 9. Secretos y servicios externos

- [ ] Credenciales SUNAT, uploader, DB y mail no están en el repositorio
- [ ] `UPLOADER_API_KEY` y tokens de terceros rotados respecto a desarrollo

### 10. Validación final

- [ ] `php artisan config:check-security` termina con código 0
- [ ] Login → `auth/me` → logout desde el SPA en producción/staging HTTPS
- [ ] Rate limit de login activo (`throttle:5,1` en rutas públicas de auth)

---

## Cutover `nm-frontend-v2`

Al reemplazar el SPA legacy por v2 en el mismo dominio (`adm.*`):

| Variable | Valor ejemplo | Notas |
|----------|---------------|--------|
| `FRONTEND_URL` | `https://adm.novedadesmaritex.net.pe` | Links de reset password y emails |
| `CORS_ALLOWED_ORIGINS` | `https://adm.novedadesmaritex.net.pe` | Sin barra final; añade staging si aplica |
| `SANCTUM_STATEFUL_DOMAINS` | `adm.novedadesmaritex.net.pe` | Sin `https://` |
| `SESSION_DOMAIN` | `.novedadesmaritex.net.pe` | Punto inicial para subdominios |
| `SESSION_SAME_SITE` | `none` | Con `SESSION_SECURE_COOKIE=true` |

**Archivos en repo:**

- `deploy/.env.production.example` — plantilla `.env` lista para copiar
- `deploy/verify-production-env.sh` — ejecuta `config:check-security`
- `../nm-frontend-v2/cutover/04-backend-env.md` — checklist del cutover

```bash
# En servidor API, tras editar .env:
./deploy/verify-production-env.sh
php artisan config:cache
```

**Prueba manual post-cutover:**

1. Login en v2 → cookies `access_token`, `refresh_token`, `XSRF-TOKEN`
2. `GET /api/auth/me` → 200
3. Sin errores CORS en consola del navegador
4. Refresh automático tras 401 (token expirado)

---

## Referencias

- [Laravel Sanctum SPA authentication](https://laravel.com/docs/sanctum#spa-authentication)
- [Set-Cookie SameSite](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie#samesitesamesite-value)
- Issues: SEC-004 (headers), SEC-006 (cookies/sesión)

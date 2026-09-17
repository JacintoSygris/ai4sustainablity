# Secretos y parametros

Volver al [manual tecnico](index.md).

## Principios

- No guardar `.env` reales en Git.
- No pegar secretos en tickets, documentacion, capturas ni comandos compartidos.
- Usar un gestor de secretos o ficheros del host con permisos `0640` o mas restrictivos.
- Separar configuracion no secreta de secretos: dominios, rutas y flags pueden versionarse en plantillas; claves y contrasenas no.
- Rotar credenciales con una ventana planificada y verificar login, correo, colas y healthchecks despues.

## Creacion de secretos

Precondicion: estar en una copia de despliegue con dependencias PHP instaladas o en un contenedor Laravel construido.

```sh
# Directorio: app/web
php artisan key:generate --show
```

Resultado esperado: se imprime una clave `base64:...`.

Comprobacion: copiarla solo al secreto `APP_KEY` del entorno; no escribirla en Markdown ni en un Compose versionado.

Para contrasenas de base, Redis, SMTP y OAuth, generar valores con el gestor de secretos corporativo o con un generador local seguro. No reutilizar valores entre servicios.

## Almacenamiento y rotacion

Precondicion: el despliegue lee variables desde el mecanismo elegido por el operador.

1. Crear el secreto nuevo sin borrar el anterior.
2. Actualizar el servicio consumidor.
3. Reiniciar solo los procesos afectados.
4. Verificar healthchecks y flujo funcional.
5. Revocar el secreto anterior en el proveedor.

Resultado esperado: el servicio queda operativo con el secreto nuevo y el anterior deja de autenticar.

Comprobacion: revisar logs de autenticacion del proveedor y errores de Laravel/FastAPI sin exponer el secreto.

## Tabla de variables

| Variable | Servicio | Obligatoria | Ejemplo no secreto | Validacion |
|---|---|---:|---|---|
| `APP_NAME` | Laravel | Si | `IA4Sustainability` | Aparece en correos y vistas. |
| `APP_ENV` | Laravel | Si | `production` | `php artisan about` dentro de `web`. |
| `APP_KEY` | Laravel | Si | `base64:EXAMPLE_NOT_A_SECRET=` | Sesiones cifradas no fallan. |
| `APP_DEBUG` | Laravel | Si | `false` | Errores no muestran trazas al usuario. |
| `APP_URL` | Laravel | Si | `https://app.example.org` | Links de correo y OAuth usan el dominio correcto. |
| `APP_LOCALE` | Laravel | Recomendado | `es` | Mensajes localizados esperados. |
| `LOG_CHANNEL` | Laravel | Si | `stderr` o `stack` | Logs visibles en plataforma. |
| `LOG_LEVEL` | Laravel | Si | `info` | No registra datos sensibles en modo debug. |
| `DB_CONNECTION` | Laravel | Si | `pgsql` | `php artisan migrate:status`. |
| `DB_HOST` | Laravel | Si | `postgres` | Laravel alcanza PostgreSQL por red privada. |
| `DB_PORT` | Laravel | Si | `5432` | Conexion TCP interna correcta. |
| `DB_DATABASE` | Laravel/PostgreSQL | Si | `ia4sustainability` | Base existe. |
| `DB_USERNAME` | Laravel/PostgreSQL | Si | `ia4_app` | Rol con permisos limitados. |
| `DB_PASSWORD` | Laravel/PostgreSQL | Si | secreto gestionado | Migraciones autentican. |
| `DB_SSLMODE` | Laravel/PostgreSQL | Depende | `prefer` | Revisar politica del operador. |
| `REDIS_HOST` | Laravel/Redis | Si | `redis` | `queue:work` conecta. |
| `REDIS_PORT` | Laravel/Redis | Si | `6379` | Conexion interna. |
| `REDIS_USERNAME` | Laravel/Redis | Depende | `default` | Si se usan ACL. |
| `REDIS_PASSWORD` | Laravel/Redis | Recomendado | secreto gestionado | Redis no acepta acceso anonimo si la plataforma lo soporta. |
| `REDIS_DB` | Laravel/Redis | Si | `0` | Sesion/cola separables. |
| `REDIS_CACHE_DB` | Laravel/Redis | Si | `1` | Cache separada. |
| `SESSION_DRIVER` | Laravel | Si | `redis` | Login persiste tras recarga. |
| `SESSION_STORE` | Laravel | Si con Redis | `redis` | Sesion usa store correcto. |
| `SESSION_SECURE_COOKIE` | Laravel | Si | `true` | Cookies solo por HTTPS. |
| `SESSION_SAME_SITE` | Laravel | Si | `lax` | Login normal funciona. |
| `CACHE_STORE` | Laravel | Si | `redis` | Cache no usa array en produccion. |
| `QUEUE_CONNECTION` | Laravel | Si | `redis` | Jobs entran en Redis. |
| `REDIS_QUEUE` | Laravel | Recomendado | `default` | Worker escucha la misma cola. |
| `REDIS_QUEUE_RETRY_AFTER` | Laravel | Recomendado | `360` | Mayor que timeout de jobs largos. |
| `MAIL_MAILER` | Laravel | Si para correo | `smtp` | Reset de contrasena envia. |
| `MAIL_HOST` | Laravel/SMTP | Si para correo | `smtp.example.org` | Conexion al proveedor. |
| `MAIL_PORT` | Laravel/SMTP | Si para correo | `587` | STARTTLS habitual. |
| `MAIL_USERNAME` | Laravel/SMTP | Si para correo | usuario proveedor | Autenticacion correcta. |
| `MAIL_PASSWORD` | Laravel/SMTP | Si para correo | secreto gestionado | No aparece en logs. |
| `MAIL_ENCRYPTION` | Laravel/SMTP | Si para correo | `tls` | Envio cifrado. |
| `MAIL_FROM_ADDRESS` | Laravel/SMTP | Si para correo | `no-reply@app.example.org` | Remitente permitido. |
| `MAIL_FROM_NAME` | Laravel/SMTP | Si para correo | `IA4Sustainability` | Nombre visible. |
| `AUTH_REQUIRE_EMAIL_VERIFICATION` | Laravel | Recomendado | `true` | Usuarios no verificados no pasan rutas protegidas. |
| `TURNSTILE_SITE_KEY` | Laravel/frontend | Opcional | clave publica de ejemplo | Registro muestra widget si se configura. |
| `TURNSTILE_SECRET` | Laravel | Opcional | secreto gestionado | Verificacion antiabuso. |
| `SOCIAL_LOGIN_ENABLED` | Laravel | Opcional | `false` | OAuth desactivado por defecto. |
| `GOOGLE_CLIENT_ID` | Laravel/Google | Si Google | id de app | Redirect se inicia. |
| `GOOGLE_CLIENT_SECRET` | Laravel/Google | Si Google | secreto gestionado | Callback autentica. |
| `GOOGLE_REDIRECT_URI` | Laravel/Google | Si Google | `https://app.example.org/auth/google/callback` | Coincide exactamente en Google Cloud. |
| `MICROSOFT_CLIENT_ID` | Laravel/Entra | Si Microsoft | id de app | Redirect se inicia. |
| `MICROSOFT_CLIENT_SECRET` | Laravel/Entra | Si Microsoft | secreto gestionado | Callback autentica. |
| `MICROSOFT_REDIRECT_URI` | Laravel/Entra | Si Microsoft | `https://app.example.org/auth/microsoft/callback` | Coincide exactamente en Entra. |
| `MICROSOFT_TENANT_ID` | Laravel/Entra | Si Microsoft | `common` | Ajustar si el operador restringe tenants. |
| `CHARACTERIZATION_GATEWAY` | Laravel | Si | `api` | No usar `mock` para operacion real. |
| `CHARACTERIZATION_API_BASE_URL` | Laravel/FastAPI | Si | `http://ai-service:8001` | `/healthz` responde desde red privada. |
| `CHARACTERIZATION_API_TIMEOUT` | Laravel | Si | `60` | Timeout suficiente para prediccion. |
| `CHARACTERIZATION_AI_MODEL_PROFILE` | Laravel/FastAPI | Si | `new_format_732_v1_gpt41` | `/model-profiles` lo lista. |
| `CHARACTERIZATION_API_TOKEN` | Laravel/FastAPI | No usado por FastAPI incluido | vacio | No asumir autenticacion si el servicio no la implementa. |
| `CHARACTERIZATION_PREDICTION_MAPPING_PATH` | Laravel | Opcional | vacio o ruta interna | Mapping de claves existe si se personaliza. |
| `ESRS_MATTER_DR_MAPPING_PATH` | Laravel | Si para P9/P10 completo | `/var/www/html/data/ar16_to_esrs_dr_mapping_esrs2023_v1.json` | `esrs:validate-matter-dr-mapping`. |
| `FILESYSTEM_DISK` | Laravel | Si | `local` | Documentos usan disco local forzado por modelo. |
| `AWS_ACCESS_KEY_ID` y relacionadas | Laravel | Solo otras integraciones | ejemplo no secreto | No habilitan documentos P6 automaticamente. |
| `P6_DOCUMENT_UPLOAD_ENABLED` | Laravel | Si | `false` | Debe seguir `false` hasta existir `/extract-document`. |
| `P6_DOCUMENT_SCAN_ENABLED` | Laravel/ClamAV | Solo si documentos futuros | `false` | Activar solo con scanner operativo. |
| `P6_DOCUMENT_SCAN_BINARY` | Laravel/ClamAV | Si escaneo | `clamdscan` | Binario ejecutable por Laravel. |
| `P6_DOCUMENT_EXTRACT_TIMEOUT` | Laravel | Futuro | `240` | No arregla endpoint ausente. |
| `P6_DOCUMENT_EXTRACT_JOB_TIMEOUT` | Laravel | Futuro | `300` | No arregla endpoint ausente. |
| `LARAVEL_API_ORIGIN` | Next.js | Si | `http://web:8000` | Rewrites funcionan. |
| `NEXT_PUBLIC_LARAVEL_API_BASE_URL` | Next.js | Si | `/api` | Navegador usa origen publico. |
| `NEXT_PUBLIC_APP_URL` | Next.js | Si | `https://app.example.org` | Enlaces de frontend correctos. |
| `NEXT_TELEMETRY_DISABLED` | Next.js | Recomendado | `1` | Sin telemetria de framework. |
| Origenes CORS | Proxy/Laravel | Depende | `https://app.example.org` | Mantener mismo origen cuando sea posible. |

## Toggles seguros

Para produccion inicial:

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=pgsql
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
CHARACTERIZATION_GATEWAY=api
CHARACTERIZATION_API_BASE_URL=http://ai-service:8001
P6_DOCUMENT_UPLOAD_ENABLED=false
P6_DOCUMENT_SCAN_ENABLED=false
SOCIAL_LOGIN_ENABLED=false
```

OAuth, Turnstile y ClamAV se activan solo cuando sus proveedores estan configurados y verificados. La carga documental permanece desactivada hasta que exista un servicio compatible con `POST /extract-document`.

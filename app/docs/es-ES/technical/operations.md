# Operacion

Volver al [manual tecnico](index.md).

## Colas y worker

Laravel usa un worker independiente para jobs. En produccion no usar `QUEUE_CONNECTION=sync`.

Precondicion: `QUEUE_CONNECTION=redis` y Redis disponible.

```sh
# Directorio: /srv/ia4sustainability
docker compose up -d worker
docker compose logs --since=10m worker
```

Resultado esperado: el worker queda en ejecucion con `queue:work redis` y sin errores de conexion.

Comprobacion:

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web php artisan queue:failed
```

La tabla de fallos debe consultarse sin error. Si hay jobs fallidos, revisar causa antes de reintentarlos.

## Registros

Enviar logs de Caddy, frontend, Laravel, worker, FastAPI, PostgreSQL y Redis a la plataforma de observabilidad del operador. Los logs no deben incluir documentos, tokens, contrasenas ni respuestas completas de proveedores OAuth.

Precondicion: servicios en ejecucion.

```sh
# Directorio: /srv/ia4sustainability
docker compose logs --since=30m web worker ai-service frontend caddy
```

Resultado esperado: se observan eventos recientes sin trazas sensibles.

## Observabilidad basica y alertas

Alertar al menos por:

- `https://app.example.org/` no responde;
- `https://app.example.org/api/auth/register-config` falla;
- Laravel `/healthz` degradado en red privada;
- FastAPI `/healthz` falla;
- worker detenido o cola acumulada;
- errores SMTP/OAuth repetidos;
- almacenamiento de PostgreSQL, Redis o volumen documental por encima del umbral del operador;
- expiracion o fallo de renovacion TLS.

## Actualizaciones

Precondicion: backup completo y plan de vuelta atras.

```sh
# Directorio: /srv/ia4sustainability
docker compose pull
docker compose up -d postgres redis ai-service
docker compose up -d web
docker compose exec web php artisan migrate --force
docker compose exec web php artisan optimize
docker compose up -d worker frontend caddy
```

Resultado esperado: servicios actualizados y migraciones aplicadas de forma no destructiva.

Comprobacion: ejecutar checklist de [aceptacion y diagnostico](acceptance-diagnostics.md) en su bloque post-despliegue.

## Rotacion de secretos

Rotar `APP_KEY` solo con un procedimiento especifico de Laravel y ventana de mantenimiento, porque afecta datos cifrados y sesiones. Rotar contrasenas de PostgreSQL, Redis, SMTP y OAuth de forma individual, verificando cada dependencia antes de revocar la anterior.

## Acciones ante error

1. Identificar servicio afectado.
2. Revisar ultimo despliegue, secretos rotados y saturacion de recursos.
3. Leer logs del servicio sin exponer datos.
4. Si afecta datos, congelar cambios y preparar restauracion.
5. Documentar causa y accion correctiva fuera del repositorio publico.

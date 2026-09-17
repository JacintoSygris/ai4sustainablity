# Aceptacion y diagnostico

Volver al [manual tecnico](index.md).

## Checklist de instalacion limpia

1. Preparacion: DNS, firewall, usuario de servicio, directorios y secretos creados segun [preparacion del servidor](server-preparation.md).
2. Build: imagenes de frontend, web y ai-service construidas; imagen web con `pdo_pgsql`.
3. Base: PostgreSQL privado creado, rol limitado y migraciones no destructivas aplicadas.
4. Redis: sesion, cache y cola configuradas con Redis.
5. Arranque: `postgres`, `redis`, `ai-service`, `web`, `worker`, `frontend` y `caddy` en ejecucion.
6. Health externo: `https://app.example.org/` y `/api/auth/register-config` responden.
7. Health privado: Laravel `/healthz`, FastAPI `/healthz` y `/model-profiles` responden.
8. Registro y sesion: una cuenta de prueba puede registrarse, iniciar sesion y cerrar sesion.
9. Reset de contrasena: correo SMTP entrega enlace con `https://app.example.org`.
10. OAuth opcional: Google/Microsoft funcionan solo si `SOCIAL_LOGIN_ENABLED=true`.
11. Prediccion: una caracterizacion de prueba obtiene propuesta tecnica de FastAPI.
12. Informacion ESRS y candidato tecnico: con datos suficientes, descargas esperadas aparecen; Arelle valida tecnicamente el candidato si procede.
13. Backup: se genera backup de PostgreSQL y volumen documental.
14. Restore: restauracion probada en entorno aislado.
15. Documentos: `P6_DOCUMENT_UPLOAD_ENABLED=false` confirmado hasta que exista `/extract-document`.

## Comandos de aceptacion

Precondicion: despliegue arrancado.

```sh
# Directorio: /srv/ia4sustainability
curl -fsS https://app.example.org/ >/dev/null
curl -fsS https://app.example.org/api/auth/register-config
docker compose exec web php artisan migrate:status
docker compose exec web php artisan queue:failed
docker compose exec web php artisan report:validate-assets
docker compose exec web php artisan taxonomy:validate-assets esrs-set1-2024
docker compose exec ai-service python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8001/model-profiles', timeout=5).read().decode())"
```

Resultado esperado: todos los comandos terminan correctamente. Si un comando falla, no declarar aceptada la instalacion hasta resolverlo o justificar formalmente que el bloque no aplica.

## Diagnostico

| Sintoma | Comprobacion | Solucion segura |
|---|---|---|
| `https://app.example.org` no carga | Logs de Caddy y estado de `frontend` | Restaurar frontend o Caddy; no exponer Laravel directamente. |
| `/api/auth/register-config` falla | Rewrites Next y `web:8000` desde frontend | Corregir `LARAVEL_API_ORIGIN` y red privada. |
| Laravel `/healthz` degradado | Conexion PostgreSQL | Revisar `DB_*`, extension `pdo_pgsql`, permisos y estado de base. |
| Login no persiste | Cookies y Redis | Revisar `SESSION_DRIVER=redis`, `SESSION_SECURE_COOKIE=true`, dominio y TLS. |
| Jobs no avanzan | Estado del worker y Redis | Reiniciar worker tras revisar logs; no cambiar a `sync` en produccion. |
| Correo no llega | Proveedor SMTP y `MAIL_*` | Corregir credenciales/remitente; no registrar contrasenas. |
| OAuth devuelve error de redirect | URI registrada | Hacer coincidir exactamente callback y `APP_URL`. |
| `/predict` falla | `/healthz` y `/model-profiles` de FastAPI | Revisar perfil, artefactos y recursos. |
| Salidas de informacion ESRS no descargan | Estado del recorrido y assets | Completar datos, validar mapping/assets/taxonomia. |
| iXBRL candidato falla | Arelle y taxonomias | Ejecutar validadores; tratar como fallo tecnico, no forzar descarga valida. |
| Documento queda `failed` | `P6_DOCUMENT_UPLOAD_ENABLED` | Mantener desactivado; falta `/extract-document`. |
| Backup no restaura | Prueba aislada | Corregir procedimiento antes de cargar datos reales. |
| Redis pierde sesiones | Persistencia Redis | Activar y verificar AOF/RDB segun politica. |
| Aparecen puertos internos publicos | `ss` y reglas firewall | Cerrar puertos y quitar `ports` de servicios privados. |

## Reset seguro

En produccion, reset significa restaurar desde backup o reconstruir un entorno nuevo y restaurar datos. No usar `migrate:fresh`. Si se necesita vaciar un entorno de prueba, documentar que no contiene datos reales y hacerlo fuera de la instalacion productiva.

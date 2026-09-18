# IA4Sustainability Laravel API

Este directorio contiene el componente Laravel del perfil público:
autenticación, persistencia local, APIs del flujo P5-P10, validaciones de
activos ESRS y generación del paquete técnico P10.

La instalación recomendada del repositorio público se ejecuta desde `app/` con
`compose.public.yml`. Consulta la guía canónica en
[`../docs/installation-and-configuration.md`](../docs/installation-and-configuration.md).

## Papel dentro del runtime público

- Recibe las llamadas del frontend a través de `/api`.
- Usa el servicio FastAPI interno para la propuesta candidata P6.
- Ejecuta migraciones y seeders en el arranque del perfil público.
- Usa SQLite efímero en `/tmp/ia4sustainability/database.sqlite` cuando se
  levanta con `compose.public.yml`.
- Mantiene P9 en modo de alcance si no se configura un mapa AR16 a DR aprobado.
- Produce salidas P10 revisables; no produce una presentación oficial ante un
  regulador ni una atestación regulatoria.

## Configuración

La referencia de variables para un despliegue propio es
[`web/.env.production.example`](.env.production.example). Usa valores únicos y
secretos fuera de Git, imágenes y logs.

El perfil público de Compose fija valores locales para evaluación:
`APP_ENV=local`, `APP_DEBUG=false`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=log`,
OAuth desactivado, verificación de email desactivada y subida/escaneo
documental P6 desactivados.

## Auditorías con el stack levantado

Desde `app/`:

```sh
docker compose -f compose.public.yml exec web php artisan report:validate-assets --no-ansi
"${ESRS_ARELLE_COMMAND:?Configure ESRS_ARELLE_COMMAND first}" --version
```

El candidato XHTML/iXBRL requiere una instalación externa de la taxonomía EFRAG,
un manifiesto privado con SHA-256 y un ejecutable Arelle configurado. Consulte
`../docs/es-ES/technical/external-efrag-taxonomy.md`; la disponibilidad de
Arelle no demuestra aceptación regulatoria.

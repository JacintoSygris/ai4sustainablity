# IA4Sustainability

IA4Sustainability es una aplicación pública para evaluación local del flujo
P5-P10: caracterización, propuesta candidata del modelo, revisión de doble
materialidad, confirmación humana, captura de datos ESRS y paquete técnico de
preparación.

La guía canónica de instalación y configuración está en
[`docs/installation-and-configuration.md`](docs/installation-and-configuration.md).

## Arranque rápido local

```sh
git clone https://github.com/JacintoSygris/ai4sustainablity.git
cd ai4sustainablity/app
docker compose -f compose.public.yml up --build -d
```

También puedes usar:

```sh
podman-compose -f compose.public.yml up --build -d
```

La primera construcción necesita red para descargar imágenes base y
dependencias. Cuando los servicios estén levantados:

| Servicio | URL local |
|---|---|
| Frontend Next | `http://localhost:3000` |
| API Laravel | `http://localhost:8000` |
| Servicio FastAPI | `http://localhost:8001` |

En el navegador, abre `http://localhost:3000`, registra un usuario y después
inicia sesión. El frontend proxyfica `/api` hacia Laravel.

## Comprobaciones básicas

```sh
curl -fsS http://127.0.0.1:3000/ >/dev/null
curl -fsS http://127.0.0.1:8000/healthz
curl -fsS http://127.0.0.1:3000/api/auth/register-config
curl -fsS http://127.0.0.1:8001/healthz
curl -fsS http://127.0.0.1:8001/model-profiles
```

## Alcance del perfil público

`compose.public.yml` es un perfil de evaluación y desarrollo local. Usa SQLite
en `tmpfs`, sesiones en fichero, cola sincrónica, correo a log, OAuth
desactivado, verificación de email desactivada y subida/escaneo documental P6
desactivados. El contenedor Laravel ejecuta `migrate:fresh --seed` en cada
arranque, por lo que los datos se descartan al reiniciar.

No uses este perfil para datos reales ni como despliegue de producción. P10
produce un paquete técnico de preparación; no es una presentación oficial ante
un regulador, un trabajo de aseguramiento, una opinión legal ni una salida
aceptada por un regulador.

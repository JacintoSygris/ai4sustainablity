# Instalacion local

Volver al [manual tecnico](index.md).

Esta pagina se conserva como ruta puente. Para produccion, seguir [servicios y despliegue](services-deployment.md). Para evaluacion local, recordar que `app/compose.public.yml` expone servicios internos, usa SQLite efimera y ejecuta `migrate:fresh`.

Precondicion: usar solo datos ficticios.

```sh
# Directorio: app
docker compose -f compose.public.yml up --build -d
```

Resultado esperado: frontend, Laravel y FastAPI arrancan para evaluacion local.

Comprobacion:

```sh
# Directorio: app
curl -fsS http://localhost:3000/ >/dev/null
curl -fsS http://localhost:8000/healthz
curl -fsS http://localhost:8001/healthz
curl -fsS http://localhost:8001/model-profiles
```

Parada:

```sh
# Directorio: app
docker compose -f compose.public.yml down
```

Al volver a arrancar, la base se recrea.

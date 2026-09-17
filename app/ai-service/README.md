# Servicio FastAPI de modelo de IA4Sustainability

Este directorio contiene el servicio público FastAPI de inferencia para el
perfil `new_format_732_v1_gpt41`.

La forma recomendada de ejecutarlo es levantar el stack completo desde `app/`.
Consulta la guía canónica en
[`../docs/installation-and-configuration.md`](../docs/installation-and-configuration.md).

## Ficheros incluidos

- `Dockerfile.public`
- `requirements.public.txt`
- `public_model_app.py`
- `public_runtime/`
- `model-artifacts/gpt41/`
- `.dockerignore`
- `README.md`

## Ejecución

Desde `app/`:

```sh
docker compose -f compose.public.yml up --build -d
```

Construcción aislada del servicio, desde `app/ai-service/`:

```sh
docker build -f Dockerfile.public -t ia4sustainability-ai-public .
docker run --rm -p 8001:8001 ia4sustainability-ai-public
```

## Endpoints

```sh
curl -fsS http://127.0.0.1:8001/healthz
curl -fsS http://127.0.0.1:8001/model-profiles
```

La API de perfiles expone únicamente `new_format_732_v1_gpt41` y reporta 102
claves. Las predicciones son apoyo candidato para una revisión humana posterior
de materialidad; nunca son materialidad final.

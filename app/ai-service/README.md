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
- `src/`: módulos opcionales de aprendizaje y el alias `src/services` (ver más abajo)
- `requirements-learning.txt` y `requirements-learning.lock.txt`: entorno opcional de aprendizaje
- `scripts/`: herramientas offline opcionales; nunca forman parte del servicio
- `tests/` y `src/tests/`
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

## Resolución del directorio de artefactos

El runtime resuelve el directorio de los artefactos del modelo en este orden:

1. Override explícito `I4S_AI_ARTIFACT_DIR` (si la ruta no existe, el arranque
   falla de forma cerrada señalando esa ruta). Una ruta relativa se resuelve
   desde la raíz de `app/ai-service`, no desde el directorio de trabajo.
2. Layout de contenedor/instalación: `trained_classifier/new_format/gpt41`
   (el que construye `Dockerfile.public`).
3. Layout de checkout de Git: `model-artifacts/gpt41` (permite ejecutar el
   servicio directamente desde el checkout con systemd, sin symlinks ni copias).

Si no existe ninguno, se devuelve la ruta canónica de Docker y la validación
de arranque falla de forma cerrada.

## Ejecución bare-metal (systemd, sin Docker)

Si ejecutas el servicio directamente desde un checkout de Git (p. ej. con
systemd), el runtime resuelve los artefactos automáticamente en
`model-artifacts/gpt41`; no hace falta symlink ni copia. También puedes forzar
la ruta con `I4S_AI_ARTIFACT_DIR`.

Recuerda alinear `CHARACTERIZATION_API_BASE_URL` del backend Laravel con la
URL real del servicio en ese modo de despliegue (p. ej.
`http://127.0.0.1:8001`; el host `http://ai-service:8001` de
`compose.public.yml` solo resuelve dentro de la red Docker).

## Endpoints

```sh
curl -fsS http://127.0.0.1:8001/healthz
curl -fsS http://127.0.0.1:8001/model-profiles
```

La API de perfiles expone únicamente `new_format_732_v1_gpt41` y reporta 102
claves. Las predicciones son apoyo candidato para una revisión humana posterior
de materialidad; nunca son materialidad final.

`/healthz` incluye un bloque `learning` (ganchos disponibles, interruptores y
`runtime_sha256`) y no lee el registro de aprendizaje. `/model-profiles` incluye
`serving_identity_sha256`, los mismos interruptores y, en `learning`,
`registry_readable` y `effective_sha256`: el hash del modelo supervisado que
este proceso serviría ahora, resuelto por la misma ruta de fallo cerrado que
`/predict` (`null` si sirve el modelo base). `registry_readable` es `null` con el
servicio de aprendizaje desactivado y `false` si el proceso no puede abrir el
registro, también antes del primer candidato. Ninguno de los dos expone rutas ni
identificadores de candidatos o de personas.

## Codificación de entrada `p5-gpt41-encoding-v1`

Los sectores se codifican como letras de sección CNAE/NACE Rev.2.1 (las
divisiones numéricas se convierten a su sección) y los productos y servicios
como los códigos A-L de
[`../web/data/p5-gpt41-feature-encoding-v1.json`](../web/data/p5-gpt41-feature-encoding-v1.json).
Los productos ya no se rellenan a partir del sector. Cada predicción informa en
`feature_metadata.derived_fields.encoding_coverage` si la codificación es
`complete` o `degraded` y qué valores no reconoce el modelo distribuido. Este
cambio debe desplegarse junto con la pasarela Laravel que envía esos códigos.

## Ganchos opcionales de aprendizaje

`public_model_app.py` añade `public_runtime/` y después `src/` a la ruta de
importación. `src/` aporta dos ganchos opcionales: la superposición de un
candidato supervisado promovido (servido como `learning:<id>`) y la política de
retroalimentación (`feedback_bandit`). Si `src/` no está, se sirve el modelo
base.

- Desactivados en local y en `compose.public.yml`.
- Activos por configuración en entornos desplegados (`APP_ENV=production|staging`
  o `LEARNING_DEPLOYED=true`, tanto en Laravel como en este servicio).
- Solo con consentimiento; la promoción y la reversión son manuales en
  `/admin/learning`.
- Si falta un requisito previo, fallan de forma cerrada y se sirve el modelo
  base.

`src/services/__init__.py` es un alias exclusivo de la edición pública: apunta a
`public_runtime/services`, de modo que el puente Laravel (`PYTHONPATH=src`), las
pruebas y FastAPI cargan los mismos ficheros de servicio. No añadas módulos en
`src/services/`.

Con el aprendizaje desplegado, uvicorn debe ejecutarse desde el mismo entorno
que usa Laravel para el aprendizaje, construido con Python 3.13 a partir del
lockfile con hashes:

```sh
python3.13 -m venv .venv-learning
.venv-learning/bin/python -m pip install --require-hashes -r requirements-learning.lock.txt
.venv-learning/bin/python -m uvicorn public_model_app:app --host 127.0.0.1 --port 8001
```

El servicio debe ejecutarse con el mismo usuario del sistema que PHP-FPM, la cola
y el planificador, con las mismas variables `LEARNING_*` y el mismo
`LEARNING_ENGINE_ROOT` absoluto, persistente, sin enlaces simbólicos y fuera del
checkout. La imagen de `Dockerfile.public` y `compose.public.yml` siguen sin
aprendizaje: usan Python 3.12 con los requisitos de servicio, que no coinciden
con las versiones del entorno de aprendizaje.

Comprobaciones de paridad tras cada despliegue, desde `app/ai-service/`:

```sh
PYTHONPATH=src .venv-learning/bin/python -c 'import learning_production_registry as r; print(r.digest({"versions": r.versions(), "code_sha256": r.code_hashes()}))'
curl -fsS http://127.0.0.1:8001/healthz         # learning.runtime_sha256 debe coincidir
curl -fsS http://127.0.0.1:8001/model-profiles  # serving_identity_sha256 debe coincidir con la identidad base de /admin/learning
                                                # learning.effective_sha256 debe coincidir con el SHA-256 del candidato
                                                # que /admin/learning muestra como modelo efectivo (null si es el modelo base)
```

La comprobación de `learning.effective_sha256` es la única que detecta un
servicio que no lee el mismo `LEARNING_ENGINE_ROOT` cuando
`LEARNING_BANDIT_MODE=off`: sin la política de retroalimentación, la identidad
base no depende del registro.

Cambiar `public_runtime/services/service_predict.py`, `datatypes.py`, los
módulos de aprendizaje de `src/`, `src/train/masked_multilabel.py` o la versión
de parche de Python invalida los candidatos supervisados promovidos hasta que se
reentrenen. Una promoción de la política de retroalimentación cambia la
identidad de servicio y tiene el mismo efecto. El procedimiento completo está en
[`../docs/learning-activation-runbook.md`](../docs/learning-activation-runbook.md).

El control opcional `LEARNING_GATE_METRICS_ENABLED=false` añade métricas e intervalos por empresa y exige una mejora predeclarada para promover. `learning_serving_adapter.py` conserva el alias público `services`; `LEARNING_SERVING_RUNTIME` solo admite `public`. El contrato `../contracts/api/learning-gate-metrics-v1.schema.json` forma parte de los hashes. Su copia `learning-gate-metrics-v1.schema.json` se mantiene byte a byte para el contexto de Docker y se verifica en las pruebas del runtime. Consulte [la configuración y los límites](../docs/learning-engine-production.md#métricas-del-control-de-promoción).

## Herramientas offline opcionales

Ninguna se ejecuta en el servicio ni se importa desde él. Los informes locales
de ejecución y evaluación no se publican: `artifacts/` está ignorado por Git.

- `scripts/concepts/build_relations.py` regenera
  `../web/data/esrs_concept_relations_v1.json` y su `.sha256`; las etiquetas se
  pasan con `--labels` (obligatorio). Indica `--evidence` con una ruta fuera del
  repositorio o dentro de `artifacts/` para el informe de ejecución.
- `scripts/xbrl_mapping/matcher.py` regenera
  `../web/data/xbrl_concept_suggestions_v1.json` a partir de los datos públicos
  IG-3 y del mapa de conceptos, y deja su informe de evaluación en `artifacts/`.
- `scripts/ixbrl/validate_with_arelle.py` valida con Arelle una muestra sintética
  XHTML/iXBRL. Exige `--taxonomy-package` con el paquete oficial obtenido por el
  operador fuera del repositorio y `--confirm-external-taxonomy-rights`; nunca
  descarga la taxonomía y escribe la evidencia fuera del repositorio. Solo
  descarga el UTR y los esquemas públicos XBRL/W3C. Ver
  [`../docs/es-ES/technical/external-efrag-taxonomy.md`](../docs/es-ES/technical/external-efrag-taxonomy.md).
- `scripts/greentech/measure.py` mide localmente tiempos y energía estimada;
  exige `GREENTECH_MEASUREMENT_ENABLED=true` y todas sus entradas, incluido
  `--output-prefix`, son argumentos obligatorios.

## Pruebas

Desde `app/ai-service/`:

```sh
PYTHONPATH=src python -m unittest discover -s src/tests
python -m pytest -q tests
```

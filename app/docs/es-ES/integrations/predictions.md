# Integración de propuestas

Volver al [índice de documentación](../index.md).

El servicio de propuestas recibe caracterización normalizada y devuelve claves candidatas. Laravel las convierte mediante correspondencias configuradas antes de mostrarlas como temas revisables.

## Superficie técnica

- `POST /predict`
- `GET /healthz`
- `GET /model-profiles`

En el perfil incluido se usa el perfil técnico `new_format_732_v1_gpt41`. El servicio sirve el código de `app/ai-service/public_runtime`.

## Codificación de la caracterización

Para `new_format_732_v1_gpt41`, Laravel envía directamente la letra de sección CNAE-2025 / NACE Rev. 2.1 del sector: banca `L64` → `L`, informática `K62` → `K`, edición `J58` → `J`. Si recibe una división numérica, el servicio aplica la misma conversión.

Los productos usan la taxonomía de extracción A–L, independiente de las letras de sector. La correspondencia versionada es `app/contracts/mappings/p5-gpt41-feature-encoding-v1.json`; Laravel carga su copia idéntica de `app/web/data/`. No se infiere un producto para opciones mixtas, desconocidas o ausentes, ni a partir del sector. La plantilla se convierte a las bandas de tamaño del entrenamiento y el valor regional por defecto cuenta el Reino Unido como Europa. La [nota de codificación](../../fix-ai-encoding.md) (en castellano) enumera las categorías y bandas que el modelo no conoce.

`feature_metadata.derived_fields.encoding_coverage` indica `complete` o `degraded` y enumera `unsupported_values`; P6 muestra una advertencia cuando la cobertura es `degraded`. Describe la cobertura de la codificación, no la calidad de la propuesta. Como P5 no recoge la forma jurídica, la cobertura suele ser `degraded`.

## Identidad de servicio y metadatos

Cada respuesta incluye en `mapping_metadata`:

- `runtime_activation`: `runtime_enabled` para el modelo base.
- `serving_identity`: perfil, SHA-256 de los artefactos cargados, SHA-256 de las políticas, configuración efectiva (umbral y políticas activas) y `serving_identity_sha256`. Los umbrales por etiqueta, el filtro sectorial y el suelo CRC no forman parte del servicio público y figuran como inactivos.
- `feedback_bandit`, solo con el ajuste por retroalimentación cargado: algoritmo, modo sombra, secuencia, cambios aplicados, `decision_id` y `basis` (codificador, contexto, hash del esquema de características, hash del modelo y puntuaciones base). Laravel lo conserva para capturar eventos P8 con consentimiento.

## Campos opcionales de aprendizaje

Laravel puede añadir a la petición `learning_shadow_authorized` (booleano, `false` por defecto) y `learning_shadow_subject` (UUID en minúsculas). Los deriva del consentimiento vigente de la cuenta autenticada; nunca se copian del navegador. No son variables del modelo, no autentican la petición y se excluyen de las exportaciones de entrenamiento. Solo permiten el registro en sombra cuando el servicio lo tiene habilitado; Python vuelve a comprobar la autorización y las retiradas antes de registrar.

Con `LEARNING_ENGINE_ENABLED` y `LEARNING_ENGINE_SERVING_ENABLED` activos y un candidato promovido manualmente, la respuesta puede usar `model_profile=learning:<id-candidato>`, con el mismo eje de claves que el perfil base. En ese caso `runtime_activation` vale `explicit_promoted_learning_candidate`, `serving_identity` vincula los bytes del candidato y su control de promoción, y `learning_base_identity` registra la identidad del modelo base. Cualquier fallo de integridad, consentimiento, caducidad o compatibilidad devuelve la predicción ordinaria de `new_format_732_v1_gpt41`. Con los valores predeterminados del perfil local, el aprendizaje no altera la salida. Véase [Motor de aprendizaje opcional](../learning-engine.md).

`GET /healthz` incluye un bloque `learning` (ganchos disponibles, variables efectivas y `runtime_sha256`) y `GET /model-profiles` incluye `serving_identity_sha256`. Sirven para comprobar que el servicio en ejecución coincide con el entorno del entrenador y con la identidad base que calcula Laravel.

## Reglas de interpretación

- La salida propone temas candidatos, no materialidad final.
- Una respuesta sin candidatos puede ser válida.
- Las claves desconocidas o de revisión no deben convertirse automáticamente en decisiones.
- El prefijo de una clave no basta para crear un tema.
- La selección final debe confirmarla la persona usuaria.

Una integración debe conservar esta separación en sus pantallas y mensajes.

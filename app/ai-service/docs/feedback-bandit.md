# Ajuste por retroalimentación (bandit contextual)

El [runbook de activación](../../docs/learning-activation-runbook.md) describe la captura autorizada de eventos, las versiones privadas y la promoción manual desde la sección «Ajuste por retroalimentación» del panel `/admin/learning`. Esta nota describe el módulo `src/feedback_bandit.py`: sus modos, políticas, límites y el contrato de eventos. Laravel sigue siendo la autoridad de P8 y de la doble importancia relativa; el módulo solo ajusta, de forma acotada, los temas candidatos de P6.

## Activación y reversión

El servicio de propuestas carga el bandit en uno de dos modos:

- **Gestionado** (instalaciones declaradas): `LEARNING_BANDIT_MODE=active` o `shadow`, con `LEARNING_ENGINE_ENABLED=true`, `LEARNING_ENGINE_AUTHORITY=consent`, `LEARNING_ENGINE_ROOT` y una autorización de servicio vigente. En una instalación declarada (`APP_ENV=production|staging` o `LEARNING_DEPLOYED=true`) sin `LEARNING_BANDIT_MODE`, el modo es `active` por defecto, igual que esas dos variables del motor. Lee la versión **promovida** del catálogo privado de `LEARNING_ENGINE_ROOT`. Sin versión promovida usa un prior vacío en memoria, que congela la base de la propuesta sin cambiar ninguna recomendación. `shadow` registra las decisiones sin cambiar la respuesta. Es el modo que usa el panel y el que describe el runbook.
- **Manual y offline**: solo cuando `LEARNING_BANDIT_MODE` no está definida **y** la instalación no es declarada, `ESRS_FEEDBACK_BANDIT_PATH` puede apuntar a un estado JSON privado creado con `FeedbackBandit(...).save(path)`. Sirve para pruebas locales o evaluación. En una instalación declarada, o con cualquier valor de `LEARNING_BANDIT_MODE`, la ruta se ignora; no la defina en una instalación gestionada.

`LEARNING_BANDIT_MODE=off` apaga el mecanismo en cualquier instalación y revierte la intervención; en una instalación declarada es la única forma de apagarlo, porque retirar la ruta no basta. Fuera de una instalación declarada, sin variable de modo ni ruta, el mecanismo está apagado, y en el modo manual retirar la ruta también lo apaga. Un estado nuevo creado a mano usa `shadow=True`: registra solo algoritmo, secuencia y número de cambios y conserva las recomendaciones originales; `shadow=False` habilita cambios acotados.

Una instantánea inicial para el modo manual se prepara con las columnas y el hash reales del perfil, desde `app/ai-service` con `PYTHONPATH=src`. No aprende nada hasta recibir eventos confirmados:

```python
from feedback_bandit import FeedbackBandit
from services import service_predict as sp

profile = sp.resolve_model_profile("new_format_732_v1_gpt41")
state = FeedbackBandit(
    profile.name,
    [k for k in sp.load_profile_esrs_columns(profile) if sp.is_new_format_candidate_key(k)],
    list(sp.load_profile_feature_columns(profile)),
    sp._model_artifact_hashes(profile)["esrs_classifier.pkl"],
    algorithm="linucb", shadow=True,
)
# Guardar en una ruta privada autorizada, fuera de Git:
# state.save(private_state_path)
```

En `src`, `services` es un alias de `public_runtime/services`, de modo que el fragmento usa el mismo código de servicio que FastAPI.

El cargador comprueba versión, checksum SHA-256, perfil, bytes del clasificador, esquema ordenado de características, temas y estructura numérica. Un archivo ausente, corrupto o incompatible desactiva el mecanismo, con un aviso sin contenido privado. El estado forma parte de la identidad de servicio y cada petición lee una instantánea propia. La inferencia no actualiza ni guarda el estado. Cada petición obtiene un UUID nuevo (`decision_id`), conservado en los metadatos. Cada tema usa un generador aleatorio local derivado por SHA-256 de la semilla, el identificador de decisión y el tema: la misma instantánea con el mismo identificador reproduce la decisión, y los identificadores nuevos permiten explorar aunque se recargue el estado. No hay un generador global ni un cursor que deba escribirse al inferir. El estado es `feedback-bandit-v2`; los estados v1 se rechazan y deben reconstruirse desde eventos autorizados.

## Políticas y límites

- `epsilon_greedy`: estimación de la recompensa de dos acciones por tema y bucket, con exploración epsilon (0,1).
- `linucb`: LinUCB disjunto por tema y acción, prior identidad, alpha 0,5 y actualización inversa de rango uno (Sherman–Morrison).
- `thompson`: Beta(1,1) por tema y acción, actualizada con recompensa Bernoulli.
- `thompson_bucket`: la misma política separada en 16 buckets de contexto.
- `sgd`: `SGDClassifier(loss="log_loss").partial_fit` por tema, tasa constante 0,05, clases [0,1], sin reajustar observaciones pasadas.

El contexto usa la fila P5 del codificador existente del RF: hash con signo a 16 coordenadas, tanh de los números, marcador explícito de ausentes y normalización L2. Se añaden el intercepto y la puntuación RF, con dimensión fija 18. Los buckets usan cuatro bits (puntuación respecto de 0,5 y signo de tres coordenadas). Es una aproximación de contexto con colisiones, no una taxonomía de sectores aprendida.

Cada actualización es O(1) respecto del **número de eventos históricos**, sin reentrenamiento por lotes: O(d²) para LinUCB, O(d) para SGD y O(1) para los contadores, con d fijo. La memoria está acotada por temas, acciones y buckets. La serialización recorre el estado agregado y no conserva eventos. Guarda parámetros SGD, cursor y semilla en JSON; no ejecuta pickle de políticas. Quien consuma el módulo debe serializar los escritores.

En el servicio público se aplica después del filtro de umbral del RF; el servicio público no incluye umbrales por etiqueta, filtro sectorial ni suelo CRC, que figuran como inactivos en la identidad de servicio. Solo puede cambiar temas candidatos cuya puntuación RF esté a ±0,1 del umbral correspondiente, con un máximo de tres cambios por predicción, ordenados por clave. Los límites configurables nunca superan un margen de 0,25 ni cinco cambios. Se exige un mínimo de tres eventos por tema. El módulo admite proteger al último representante de una norma, pero el servicio público no protege ninguna norma porque no tiene suelo CRC. La decisión P8 de la persona usuaria no cambia. La recompensa es la concordancia entre la acción mostrada y la etiqueta confirmada; no se inventan negativos no observados.

La [fuente P8 y el contrato de eventos](../../contracts/api/p8-feedback-bandit-v1.md) reutilizan `p6_snapshot`, `guided_answers` y las etiquetas confirmadas. El servicio de propuestas devuelve el contexto y las puntuaciones en `mapping_metadata.feedback_bandit.basis` solo cuando el bandit está cargado, y Laravel conserva esos metadatos. `update_event` consume eventos vinculados y ordenados. No hay un endpoint nuevo en el servicio de propuestas ni se duplica el motor de aprendizaje supervisado.

## Evaluación offline

La evaluación por replay sobre el corpus de entrenamiento requiere el corpus privado, conjuntos gold y vínculos de identidad empresarial que no se distribuyen; por eso su script no forma parte de este repositorio. Esa evaluación no es causal, no usa IPS ni propensiones de personas usuarias reales y no sustituye una evaluación con datos de uso.

## Pruebas

Desde `app/ai-service`, con el entorno de aprendizaje:

```sh
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src python -m unittest discover -s src/tests -p 'test_feedback_bandit*.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src python -m unittest discover -s src/tests -p 'test_service_predict.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src python -m unittest discover -s src/tests -p 'test_public_learning_wiring.py'
```

`test_public_learning_wiring.py` procesa y promueve versiones del bandit con los comandos del puente y comprueba que el servicio de propuestas, en otro proceso, sirve la versión promovida, recupera la anterior al revertir y vuelve a la salida base al apagar el aprendizaje.

Prueba de Laravel, desde `app/web`:

```sh
php artisan test tests/Feature/Services/FeedbackBanditContractTest.php
```

Comprueba que el adaptador conserva la base privada de retroalimentación y que una acción hipotética en sombra no se convierte en propuesta P6. La respuesta completa, activa y en sombra, de `tests/Fixtures/feedback-bandit-python-response.json` se produce con el serializador Python y un modelo sintético; la prueba Python comprueba que sigue coincidiendo byte a byte. El mock HTTP envía JSON con `JSON_PRESERVE_ZERO_FRACTION`, como el serializador real, para que `0.0` no se convierta en `0`. `LearningP6SnapshotTest` conserva los contratos anteriores y rechaza políticas incoherentes, y `LearningCaseExportTest` cubre la exportación con esa respuesta completa en ambos modos.

Referencias de implementación: [LinUCB disjunto, Li et al.](https://arxiv.org/abs/1003.0146) y [`SGDClassifier.partial_fit`](https://scikit-learn.org/stable/modules/generated/sklearn.linear_model.SGDClassifier.html). No se añaden dependencias: NumPy y scikit-learn ya forman parte del servicio.

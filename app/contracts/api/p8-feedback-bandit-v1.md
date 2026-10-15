# P8 → aprendizaje incremental: contrato interno v1

Operación, consentimiento y privacidad: [activación y operación del aprendizaje](../../docs/learning-activation-runbook.md). Módulo Python: [ajuste por retroalimentación](../../ai-service/docs/feedback-bandit.md).

Contrato de consumo entre Laravel y el módulo Python del bandit. Laravel conserva la autoridad: captura y procesa eventos solo con consentimiento vigente, sin endpoint público en el servicio de propuestas. El sobre por evento descrito abajo es estable. El transporte local lo envuelve con una clave de origen, un sujeto seudónimo, la revisión y la caducidad; Python asigna `sequence` bajo bloqueo. Este contrato no sustituye el motor de aprendizaje supervisado ni sus autorizaciones.

## Fuente y autoridad

La fuente es la confirmación vigente y validada que expone `MaterialityConfirmationController::confirmationState`: `is_confirmed=true`, `is_stale=false`, `confirmation_status=confirmed`, `p6_snapshot.topic_ids`, `confirmed_topic_ids`, `confirmation.revision`, `guided_answers` y `learning_topic_labels`. Nunca se usa el estado preliminar por defecto de P6. El consumidor comprueba la pertenencia al usuario, la versión de P5, la generación P6 y la revisión P8 dentro de una lectura consistente de Laravel. Ningún cliente puede enviar una recompensa y convertirse en autoridad.

Con el bandit cargado (modo gestionado `LEARNING_BANDIT_MODE=active|shadow`, o un `ESRS_FEEDBACK_BANDIT_PATH` válido en modo manual), Python devuelve `mapping_metadata.feedback_bandit.basis`. El adaptador de Laravel lo conserva en `result_data.mapping_metadata.python.feedback_bandit.basis`. Contiene el contexto codificado, las puntuaciones RF anteriores a las políticas opcionales y al bandit, el hash del modelo, el hash del esquema de características y la versión del codificador. La identidad de servicio conserva el hash de la política usada, también en sombra. `LearningP6Snapshot` admite el hash y el indicador opcionales `feedback_bandit` junto a las tres políticas anteriores, exige coherencia entre ambos y conserva los digests de respuestas anteriores sin ese campo. Python conserva también `feedback_bandit.decision_id` para reproducir la exploración con el mismo estado; no identifica a la persona usuaria ni autoriza eventos de aprendizaje. Sin esta base congelada no se producen eventos: no se rellenan puntuaciones con ceros ni se vuelve a inferir con el modelo actual una recomendación histórica.

## Proyección y máscara

Se usa la correspondencia AR16→clave Python aprobada existente. Solo se convierten correspondencias unívocas y coherentes con la propuesta P6; se excluyen las ambiguas o no aprobadas. La aceptación o el rechazo deben ser explícitos en `learning_topic_labels` (0/1) y coherentes con el conjunto confirmado y con las respuestas guiadas, cuando existan. No se convierten en etiqueta negativa `revisar`, un valor desconocido, la ausencia de revisión ni la desaparición de un registro.

- Propuesto y confirmado: `proposed=true, accepted=true`.
- Propuesto y rechazado explícitamente: `proposed=true, accepted=false`.
- No propuesto y añadido por la persona usuaria: `proposed=false, accepted=true`.
- No propuesto y no añadido: sin evento.

`proposed` describe lo que finalmente se mostró, después de las políticas opcionales y del bandit; `base_score` es siempre la probabilidad original del RF. En sombra, las acciones hipotéticas no son propuestas mostradas. Una adición recompensa con cero la acción de no recomendar. Los negativos no propuestos siguen sin observarse; este sesgo de observación limita el aprendizaje, sobre todo de supresiones.

## Sobre JSON por evento

Campos exactos aceptados por `FeedbackBandit.update_event`:

| Campo | Restricción |
|---|---|
| `version` | `p8-bandit-event-v1` |
| `profile` | Igual al perfil del estado |
| `model_sha256` | Hash del clasificador RF, igual al del estado |
| `feature_schema_sha256` | SHA-256 del JSON canónico de las columnas ordenadas del estado |
| `encoder` | `p5-hash-tanh-v1` |
| `sequence` | Entero consecutivo, empezando en 1; estado siguiente = anterior + 1 |
| `topic` | Clave Python canónica presente en el estado |
| `context` | Vector de 16 números finitos en [-1,1], capturado en P6 |
| `base_score` | Número finito en [0,1], capturado en P6 |
| `proposed`, `accepted` | Booleanos estrictos; al menos uno verdadero |

El exportador usa una clave única privada de (caracterización, generación P6, revisión P8, tema) y valida y deduplica antes de asignar la secuencia. Un único escritor serializa las actualizaciones y persiste el cursor junto con el estado. Un duplicado o un salto se rechaza sin aplicarse dos veces. Una corrección o un borrado requieren reconstruir desde el registro permitido; no se reintroducen revisiones antiguas ni se conservan datos tras una revocación. La secuencia no sustituye esa deduplicación de origen ni autoriza el uso de datos.

No se exportan nombres, notas, correos, identificadores de usuario ni texto libre. El checksum detecta alteraciones accidentales; **no firma ni autoriza** datos. El estado y las exportaciones se guardan fuera de Git, bajo control del operador. No hay CSV ni HTML nuevos. Cualquier ruta de exportación futura requerirá autenticación de Laravel, comprobación de propiedad, validación y limitación de frecuencia.

# Motor de aprendizaje opcional

## Alcance

El aprendizaje opcional tiene dos circuitos que comparten configuración, consentimiento y panel de administración:

- **Motor de aprendizaje supervisado**: conserva las decisiones de materialidad revisadas (P8) y la evidencia estructurada de P9 de las cuentas que consienten, entrena modelos candidatos, los evalúa contra conjuntos reservados y solo los sirve después de una promoción manual.
- **Ajuste por retroalimentación**: un bandit contextual acotado que aprende de forma incremental de las confirmaciones P8 consentidas y solo puede cambiar unos pocos temas candidatos cercanos al umbral. Cada versión también requiere promoción manual.

El panel `/admin/learning` presenta una sección para cada circuito con estos mismos nombres: «Motor de aprendizaje supervisado» y «Ajuste por retroalimentación».

Los candidatos están separados del modelo base, cuyos artefactos no se sustituyen. Una ausencia de respuesta no genera una etiqueta «no material». Las decisiones sobre relevancia y selección de indicadores tampoco eliminan obligaciones normativas. El circuito conserva las revisiones de origen y la autorización de cada caso; un cambio de las fuentes, una retirada o la eliminación de la cuenta invalidan su elegibilidad.

## Activación

- **Apagado en local y en la composición incluida.** `compose.public.yml` y el entorno local o de pruebas mantienen el aprendizaje apagado y sintético.
- **Encendido por configuración en entornos desplegados.** Con `APP_ENV=production` o `staging`, o con `LEARNING_DEPLOYED=true`, los valores predeterminados de Laravel y del servicio de propuestas activan ambos circuitos. `LEARNING_DEPLOYED=false` los mantiene apagados aunque `APP_ENV=production`; la plantilla `app/web/.env.production.example` lo fija así hasta completar la lista de aprovisionamiento.
- **Solo con consentimiento.** La autoridad es `consent`. Solo participan cuentas con correo verificado y consentimiento vigente para esta finalidad, que se concede y se retira en `/profile`. El motor supervisado exige además una pertenencia empresarial verificada.
- **Promoción y reversión manuales.** Se realizan en `/admin/learning`, reservado a los IDs configurados en `LEARNING_ADMIN_USER_IDS` y `LEARNING_ENGINE_ADMIN_IDS`. Nada se promueve automáticamente.
- **Requisitos que fallan de forma cerrada y visible.** El panel enumera migraciones, directorio de estado, entorno Python, scheduler, cola, autorización vigente, conjunto reservado y commit declarado. Un requisito ausente bloquea las acciones que dependen de él, y cualquier fallo de integridad, compatibilidad, consentimiento o caducidad hace que el servicio use el modelo base.

No cambie `APP_ENV` a `testing` en un entorno real para eludir estos límites. El [runbook de activación](../learning-activation-runbook.md) detalla variables, comprobaciones del panel, despliegue con Jenkins, apagado y reversión; la nota del [motor supervisado en modo operativo](../learning-engine-production.md) describe conjuntos reservados, control de promoción y protección de datos. Ambas están en castellano.

## Componentes y separación del servicio

Laravel conserva autenticación, sesiones, consentimiento, captura, exportación, trabajos en cola, el panel y las tareas programadas `learning:tick` y `learning:maintain-privacy`. Llama a los módulos Python de `app/ai-service/src` mediante un subproceso local con argumentos fijos y un entorno depurado; no expone endpoints administrativos en el servicio de propuestas.

El servicio de propuestas sigue sirviendo `public_runtime`. `public_model_app.py` añade `src` después de `public_runtime`, de modo que los ganchos de aprendizaje se cargan si existen y permanecen inactivos sin configuración. Sin `src`, se sirve el modelo base. En `src`, el paquete `services` es un alias de `public_runtime/services`: el puente de Laravel, las pruebas y FastAPI usan los mismos archivos de servicio.

Dos lecturas permiten comprobar que el servicio en ejecución coincide con el entorno del entrenador:

- `GET /healthz` incluye un bloque `learning` con `hooks_available`, `engine_enabled`, `serving_enabled`, `bandit_mode` y `runtime_sha256` (versiones de Python y dependencias más huellas del código de aprendizaje y de servicio).
- `GET /model-profiles` incluye `serving_identity_sha256`, la identidad del modelo base y de la política efectiva de ese proceso.

Ninguna contiene rutas ni identificadores de personas. Si `runtime_sha256` no coincide con el valor calculado en el entorno de aprendizaje, o `serving_identity_sha256` con la identidad base del panel, los candidatos promovidos no se sirven.

El aprendizaje solo está soportado en una instalación nativa, con el servicio de propuestas ejecutado desde el entorno `.venv-learning`. Las imágenes `Dockerfile.public` no incluyen el entorno de aprendizaje ni un volumen de estado compartido, y mantienen el aprendizaje apagado. El paquete no incluye corpus de entrenamiento, datos empresariales, conjuntos reservados, modelos candidatos ni políticas privadas.

## API

La API autenticada incluye `GET /api/admin/learning` y `POST /api/admin/learning/actions` para el panel, `PUT /profile/learning-consent` para el consentimiento, y las rutas sintéticas `GET/PUT /api/learning-case/draft` y `POST /api/learning-case/close` y `/withdraw`. Todas mantienen las protecciones de sesión y CSRF. Las rutas del panel y del consentimiento tienen además límites de frecuencia; las rutas sintéticas no los tienen. Las rutas del panel y las sintéticas exigen correo verificado cuando `AUTH_REQUIRE_EMAIL_VERIFICATION=true`, y conceder el consentimiento lo exige siempre. Las acciones del panel exigen un motivo, quedan auditadas antes de encolarse y no aceptan rutas, comandos ni archivos.

## Dependencias y pruebas

Construya `app/ai-service/.venv-learning` con Python 3.13 desde `requirements-learning.lock.txt` (`pip install --require-hashes`). Ese mismo entorno ejecuta el entrenador y, cuando el aprendizaje está desplegado, el servicio de propuestas. `requirements.public.txt` sigue siendo el conjunto de la imagen del servicio sin aprendizaje. Consulte el [inventario de dependencias](learning-dependencies.md) antes de preparar el entorno.

Las pruebas Python de aprendizaje usan `PYTHONPATH=src`. Las del runtime público, en `tests/`, usan pytest, que no forma parte de los locks y se instala aparte:

    cd app/ai-service
    PYTHONPATH=src python -B -m unittest discover -s src/tests
    python -B -m pytest -q -p no:cacheprovider tests

Las pruebas portables proporcionan casos sintéticos y no requieren recibos privados. Las pruebas específicas de una plataforma o motor de base de datos necesitan recursos desechables explícitos; una omisión no equivale a una comprobación aprobada.

El arnés nativo es opcional y se ejecuta desde `app/web`:

    php tests/scripts/t11-native.php --allow-native-fixture --profile=<perfil-desechable-del-operador> --engines=mysql,pgsql --require-positive-discovery

El operador debe preparar un perfil desechable compatible con los controles de driver, versión, loopback e identidad de base de datos del arnés. No utilice una base real para estas pruebas. Los arneses privados ligados a una máquina no forman parte de esta distribución.

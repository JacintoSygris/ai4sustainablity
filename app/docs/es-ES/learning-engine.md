# Motor de aprendizaje opcional

## Alcance

El módulo conserva las decisiones de materialidad revisadas y permite preparar casos, entrenar modelos candidatos, evaluarlos, recargarlos y comprobar una selección o reversión explícita en un entorno sintético de ensayo. Los candidatos están separados del modelo que atiende las peticiones de la aplicación.

Una ausencia de respuesta no genera una etiqueta «no material». Las decisiones sobre relevancia y selección de indicadores tampoco eliminan obligaciones normativas. El circuito conserva las revisiones de origen y la autorización de cada caso; un cambio de las fuentes, una retirada o la eliminación de la cuenta invalidan su elegibilidad.

## Límite operativo

El aprendizaje está deshabilitado por defecto. La composición incluida solo admite el modo sintético de prueba: no contiene un emisor operativo positivo de autorización ni un recorrido completo de aprendizaje con datos empresariales. No existe una variable de entorno que convierta ese mecanismo en una función productiva. No cambiar `APP_ENV` a `testing` en TEST o producción para eludir este límite.

Publicar, instalar o actualizar esta versión no activa el aprendizaje y no sustituye el modelo servido. El uso empresarial requiere una composición operativa adicional con identidad y derechos verificables, almacenamiento duradero, políticas aplicables, exportación autorizada y registro de candidatos fuera de los perfiles de prueba. Entrenar y promover un modelo son operaciones distintas.

## API y separación del servicio

La API autenticada incluye `GET/PUT /api/learning-case/draft` y `POST /api/learning-case/close` y `/withdraw`. Mantiene las protecciones de sesión y CSRF. La existencia de estas rutas no implica que un cierre operativo esté habilitado.

Los módulos Python de aprendizaje son independientes de `public_runtime`, `public_model_app.py`, los requisitos del servicio y los cuatro archivos del modelo distribuido. No deben sustituirse los modelos existentes por candidatos de ensayo. El paquete no incluye corpus de entrenamiento, datos empresariales, modelos candidatos entrenados ni políticas privadas.

## Dependencias y pruebas

Instalar `app/ai-service/requirements-learning.txt` únicamente en un entorno opcional separado. Conservar `requirements.public.txt` para el servicio existente y consultar el [inventario de dependencias](learning-dependencies.md) y su lockfile antes de preparar el entorno de ensayo.

Las pruebas Python usan `PYTHONPATH=src`. Las pruebas portables proporcionan casos sintéticos y no requieren recibos privados. Las pruebas específicas de una plataforma o motor de base de datos necesitan recursos desechables explícitos; una omisión no equivale a una comprobación aprobada.

El arnés nativo es opcional y se ejecuta desde `app/web`:

    php tests/scripts/t11-native.php --allow-native-fixture --profile=<perfil-desechable-del-operador> --engines=mysql,pgsql --require-positive-discovery

El operador debe preparar un perfil desechable compatible con los controles de driver, versión, loopback e identidad de base de datos del arnés. No utilizar una base real para estas pruebas. Los arneses privados ligados a una máquina no forman parte de esta distribución.

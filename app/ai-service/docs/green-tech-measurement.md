# Medición local Green Tech

`scripts/greentech/measure.py` estima el coste local en tiempo, energía y emisiones de los componentes de IA relacionados con la propuesta de temas NEIS para la doble importancia relativa. Es una herramienta de línea de comandos para operadores: no añade rutas ni interfaz y no cambia Laravel, FastAPI, el modelo servido ni el aprendizaje. No requiere dependencias nuevas.

## Activación y ejecución

`GREENTECH_MEASUREMENT_ENABLED` vale **false por defecto**. Solo la CLI local lo lee, y debe establecerse en `true` para medir. La aplicación no importa estos scripts. Desactivarlo impide cargar modelos y escribir evidencias. Los archivos pickle deben proceder de un artefacto de confianza: joblib deserializa código y no debe recibir modelos de terceros.

Use un entorno Python externo al repositorio con las dependencias de aprendizaje automático existentes (scikit-learn, NumPy, pandas, joblib, scikit-multilearn), por ejemplo el entorno de aprendizaje `.venv-learning`. Entradas:

- `--artifact-dir`: el directorio de artefactos del perfil. Puede ser el distribuido `app/ai-service/model-artifacts/gpt41`, que contiene los cuatro obligatorios: `esrs_classifier.pkl`, `esrs_columns.pkl`, `sector_columns.pkl` y `region_columns.pkl`. `product_columns.pkl` es opcional: si existe se registra su hash y, si falta, el informe lo registra como `null`;
- los CSV de empresas y de etiquetas de entrenamiento, que **no se distribuyen** en este repositorio y que el operador aporta desde su copia privada de entrenamiento.

Ejemplo desde la raíz del repositorio, con `GREEN_PYTHON` (intérprete), `GREEN_PRIVATE_INPUTS` (directorio de lectura con los CSV) y `GREEN_SCRATCH_ROOT` (directorio externo de resultados) establecidos por el operador:

```bash
PYTHONDONTWRITEBYTECODE=1 GREENTECH_MEASUREMENT_ENABLED=true "$GREEN_PYTHON" \
  app/ai-service/scripts/greentech/measure.py \
  --artifact-dir app/ai-service/model-artifacts/gpt41 \
  --company-csv "$GREEN_PRIVATE_INPUTS/companies.csv" \
  --label-csv "$GREEN_PRIVATE_INPUTS/labels.csv" \
  --tdp-w 170 \
  --tdp-source 'https://www.amd.com/en/products/processors/desktops/ryzen/7000-series/amd-ryzen-9-7950x.html' \
  --repetitions 3 --calls 3 --batch-size 2 --feedback-size 32 \
  --memory-gb 4 --pue 1 \
  --output-prefix "$GREEN_SCRATCH_ROOT/greentech-run-001"
```

El TDP del ejemplo corresponde al Ryzen 9 7950X; **cámbielo y cite su fuente** para otro equipo. La RAM y el PUE son supuestos explícitos, no lecturas de potencia. La fecha de la fuente del TDP no se infiere del procesador ni de la URL: añada `--tdp-source-year` únicamente con un año verificable. Si se omite, el JSON registra `null` y el Markdown muestra «año desconocido».

El prefijo debe ser nuevo: la CLI reserva en exclusiva `<prefijo>.reservation.json` antes de cargar entradas o ejecutar el benchmark, de modo que dos ejecuciones concurrentes no pueden compartirlo. JSON y Markdown se crean en modo exclusivo, sin truncar archivos existentes. El marcador pasa a `complete` solo después de escribir y sincronizar ambos archivos; quien lea los resultados debe comprobarlo antes de consumir la pareja. Un fallo o una interrupción conservan la reserva (`failed` o `reserved`) y pueden dejar archivos incompletos: use otro prefijo, sin reutilizar el anterior. No es una publicación atómica de dos archivos ante lectores que ignoren el marcador.

Escriba los resultados fuera del repositorio; no los versione. No se serializan modelos nuevos, perfiles, nombres de empresas, etiquetas ni texto de informes. Se conservan los hashes de entradas y código y los agregados con sus muestras.

## Qué mide y cómo se interpreta

- Ajuste de la receta persistida RF/ClassifierChain/preprocesador sobre el pliegue de entrenamiento de la partición iterativa. Se clona el modelo, sin parámetros aprendidos. Semilla local fija; no reconstruye los índices históricos exactos. Excluye evaluación, serialización, búsqueda y la extracción previa con LLM.
- `N = --calls` llamadas a `Pipeline.predict` en lotes de `--batch-size` perfiles, frente a `N × batch-size` llamadas individuales sobre los mismos perfiles. Ambas usan el modelo ya cargado. La unidad normalizada es un perfil y se exige la igualdad de predicciones. No mide el endpoint completo.
- Deserialización independiente y carga por petición frente a una carga inicial seguida de las mismas llamadas. Disco caliente y mismo proceso; incluye el coste inicial de la alternativa reutilizada. Orden de pares alternado.
- Reentrenamiento RF completo sobre entrenamiento más una tanda reservada frente a `SGDClassifier(loss="log_loss").partial_fit` por etiqueta, con el preprocesador congelado. También mide la inicialización del SGD. La tanda procede del corpus con etiquetas silver existentes: es un **proxy de retroalimentación**, sin correcciones humanas. No se evalúan calidad, convergencia ni equivalencia con RF.
- Escenario bibliográfico LLM: escala indicativa de la estimación de Epoch AI para GPT-4o con entrada corta. No realiza llamadas externas. No es el coste de la extracción histórica con GPT-4.1 ni una alternativa funcional validada.

Primero sondea RAPL en `/sys/class/powercap` y suma solo paquetes, sin contar dos veces los dominios internos. Si no son legibles, usa una estimación explícita inspirada en [Green Algorithms](https://arxiv.org/abs/2007.07610): tiempo de CPU sumado de los hilos × TDP/núcleos físicos, limitado a la capacidad física, más RAM asignada × 0,3725 W/GB × tiempo transcurrido, y aplica el PUE. Un RAPL legible mide los paquetes de todo el host, incluidos el reposo y otros procesos; la RAM y el PUE siguen siendo estimados. Un fallo del contador durante la ejecución invalida el resultado. CodeCarbon no se instala en el producto ni se activa telemetría.

El factor español de generación de 2024 se **calcula en el código** con los [27 millones de tCO₂e de Red Eléctrica](https://www.sistemaelectrico-ree.es/es/2024/informe-del-sistema-electrico/generacion/generacion-de-energia-electrica/generacion-no-renovable-de-energia-electrica) y los [262.247 GWh](https://www.sistemaelectrico-ree.es/es/2024/informe-del-sistema-electrico/generacion/generacion-de-energia-electrica/generacion-total-de-energia-electrica). Es una referencia histórica nacional de generación con el numerador redondeado, no un factor horario, de consumo ni de ciclo de vida. La referencia de [Epoch AI (2025)](https://epoch.ai/gradient-updates/how-much-energy-does-chatgpt-use) usa 0,3 Wh por 500 tokens de salida e incluye infraestructura, por lo que no se aplica otra vez el PUE. Sus emisiones con el factor español son hipotéticas.

Cada operación registra repeticiones, media y desviación estándar **muestral** de tiempo, energía y emisiones. La desviación no expresa la incertidumbre del modelo de energía. Los cocientes usan medias. Cada comparación local (`calculations.comparison()`) devuelve `candidate_over_baseline`, `reduction_percent`, `at_least_50_percent_in_this_scope` y `supports_traditional_development_comparison`, este último siempre `false`. El informe JSON registra `traditional_development_comparison.supported: false` con su motivo en `reason_es`, y la CLI termina con la línea «Evidencia escrita. Comparación con desarrollo tradicional: no evaluada.». Superar el 50 % en una comparación local no demuestra un ahorro frente a un desarrollo tradicional.

## Límites y fases

Diseño: compara reutilización y lotes. Entrenamiento: mide ajustes locales. Ejecución: mide inferencia y carga. Desarrollo: no hay medición de sesiones, CI, compilaciones o campañas; tampoco se contabilizan la extracción LLM anterior, la fabricación, la red, el disco, la GPU ni la infraestructura completa. No hay una línea base de desarrollo tradicional con calidad y ciclo de vida equivalentes, aislamiento del host compartido ni medición del servidor de producción, y los resultados locales no son extrapolables a él. El informe indica que la comparación con un desarrollo tradicional **no se ha evaluado**. Las mediciones no autorizan promociones, activación de aprendizaje ni despliegues.

## Pruebas

Desde `app/ai-service`:

```bash
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src "$GREEN_PYTHON" \
  -m unittest discover -s src/tests -p 'test_greentech*.py'
```

Cubren unidades, PUE, límite SMT, entradas inválidas, desviación muestral, cocientes, contador RAPL con desbordamiento y el rechazo por variable desactivada antes de cargar entradas. También cubren el rechazo de etiquetas fraccionarias antes de convertirlas a enteros, la colisión concurrente, la conservación de destinos existentes, las reservas fallidas y el año del TDP explícito o desconocido. Los valores ausentes de etiquetas mantienen el tratamiento de la receta heredada (cero); no se presenta como validación de verdad. El informe conserva comprobaciones de igualdad y hashes antes y después. Para repetir, use un prefijo externo nuevo, mantenga entradas y parámetros y compare distribuciones; no espere identidad de tiempos.

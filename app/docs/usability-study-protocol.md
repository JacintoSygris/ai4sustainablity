# Protocolo de estudio formativo de usabilidad

Este documento prepara un estudio formativo de usabilidad con el kit incluido en la aplicación. No acredita que se haya realizado ningún estudio ni aporta resultados de personas usuarias.

## Objetivos y contexto

Identificar barreras y mejoras en P5–P10 para personas que preparan información de sostenibilidad en pymes. Evaluar efectividad (logro de los objetivos de tarea), eficiencia (tiempo y errores observados) y satisfacción (SUS y comentarios), según el marco de [ISO 9241-11:2018](https://www.iso.org/standard/63500.html). No se pretende certificar conformidad ISO, accesibilidad o cumplimiento normativo del informe. El análisis de doble importancia relativa sigue siendo una decisión humana; las sugerencias de IA son candidatas.

Una ronda moderada con **5–8 participantes previstos**, personas adultas con funciones de sostenibilidad, administración o asesoría de pymes y capacidad de leer español. Buscar variedad de experiencia con NEIS, competencia digital y necesidades de acceso. Excluir desarrolladores del producto y personas que conozcan las tareas de antemano. Esta es una elección de diseño, no una muestra reclutada ni una cifra obtenida. Se justifica para descubrir problemas y priorizar iteraciones por los rendimientos decrecientes del modelo de [Nielsen y Landauer (1993), DOI 10.1145/169059.169166](https://doi.org/10.1145/169059.169166) y la recomendación formativa de [Nielsen (2000)](https://www.nngroup.com/articles/why-you-only-need-to-test-with-5-users/). La recomendación no garantiza cobertura y **no justifica inferencia poblacional**: [NN/G distingue estudios cualitativos y cuantitativos](https://www.nngroup.com/articles/5-test-users-qual-quant/). Si aparecen perfiles con comportamientos distintos, planificar rondas propias. No validar psicométricamente la escala ni declarar diferencias significativas con esta muestra.

## Reclutamiento y preparación

El equipo responsable invita voluntariamente a personas del perfil mediante sus canales habituales; este kit no envía invitaciones. Explicar propósito, duración prevista, ausencia de consecuencias por rechazar o retirarse y el tratamiento de datos antes de acordar la sesión. No registrar nombres, correos, empresas o listados de reclutamiento en Git ni en los comentarios del estudio. No registrar pantalla, voz o imagen; cualquier ampliación requiere otro protocolo y consentimiento.

Antes de invitar: asignar responsable y moderador, confirmar los contactos de `/privacy`, organizar almacenamiento privado y borrado, verificar el scheduler y las migraciones, activar el flag únicamente en el entorno autorizado y probar autenticación con cuentas de prueba. Registrar privadamente fecha real, versión del producto, dispositivo, navegador, ayudas de accesibilidad y experiencia por categorías amplias. Si el operador no puede asegurar el plazo de borrado, no iniciar la recogida. No habilitar aprendizaje con estos datos.

Cada persona usa una cuenta individual y una sesión autenticada normal. La inscripción no crea cuentas ni cambia su contraseña. Utilizar el perfil de empresa ficticio de abajo y verificar que no se aporten datos empresariales reales. Se puede usar la aplicación sin participar. Cookies necesarias de sesión y CSRF conservan sus reglas; el estudio no depende de aceptar las finalidades opcionales de preferencias, recuperación o tiempo real. No añade cookies, almacenamiento local, proveedores de analítica ni llamadas a Python.

## Información y consentimiento mostrado

Versión `2026-10-08.1`. Los siguientes párrafos son el texto español de `STUDY_CONSENT` mostrado antes de la casilla, desmarcada por defecto:

> Evaluamos la efectividad, la eficiencia y la satisfacción al preparar un informe con las NEIS. La sesión prevista dura entre 45 y 60 minutos.
>
> Recogemos un código aleatorio vinculado a tu cuenta, tu consentimiento, los inicios, finalizaciones y abandonos de las tareas del asistente, las fechas, los errores visibles, las respuestas SUS y comentarios opcionales. No recogemos las respuestas de tu empresa, pulsaciones, grabaciones ni documentos para el estudio.
>
> Participar es voluntario. Puedes seguir usando la aplicación sin participar, omitir comentarios o retirarte en cualquier momento desde este aviso. Retirarte borra tus datos del estudio en el servidor.
>
> La base jurídica es tu consentimiento (art. 6.1.a RGPD). Los datos se guardan en el servidor de la plataforma, sin analítica de terceros ni nuevas cookies o almacenamiento local. Se conservarán hasta que te retires o hasta el borrado al cierre del estudio, previsto en un máximo de 90 días desde tu inscripción.

Enlace a `/privacy`: «Consulta el responsable, el contacto para ejercer tus derechos y la información de privacidad». Esa política identifica al responsable y los canales para ejercer derechos. Casilla obligatoria: «He leído la información y consiento expresamente el tratamiento de mis datos para este estudio voluntario». Botones «Inscribirme con consentimiento» y «Continuar sin participar». No se presupone consentimiento por navegar o por la elección de cookies. Referencia legal: [RGPD, artículos 6.1.a, 7, 13 y 17](https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng). El responsable debe confirmar la información y organización efectivas antes de la sesión; el kit no es una certificación jurídica.

Se almacena únicamente el UUID aleatorio `study_participant_id`, el vínculo privado `user_id`, versión y fecha de consentimiento y fecha de inscripción para limitar la retención. Retirarse desde el aviso borra inscripción, tareas, eventos, cuestionario y comentarios por cascada. La cuenta y el trabajo del producto permanecen. La retirada no exige que el correo siga verificado. Borrar la cuenta también borra datos del estudio por clave foránea. No se mantiene una lista de personas retiradas.

## Escenarios y criterios de tarea

**Datos ficticios exclusivamente para el escenario**, no resultados del estudio: «Taller Circular Demo», pyme industrial española, ejercicio 2026, 150 personas, ingresos de 20 millones de euros, no cotizada, sin filiales, fabricación de piezas metálicas, venta en España, moneda EUR. Tiene consumo eléctrico y residuos de metal; compras a proveedores y personal de producción. El moderador entrega una ficha marcada FICTICIA. No rellenar el producto con valores aleatorios para simular participantes. No se garantiza una propuesta de temas concreta: depende del perfil y versión del servicio.

| Tarea | Consigna al participante | Criterio de éxito observado |
|---|---|---|
| P5 (pantalla 1) | Caracteriza la empresa usando la ficha y continúa. | Datos requeridos guardados y navegación correcta a la revisión. |
| P6 (pantalla 2) | Examina las sugerencias y revisa cada una; explica una modificación usando el caso. | Revisión completa guardada; entiende que la IA no decide la importancia relativa final. |
| P7 (pantalla 3) | Localiza las plantillas y registra el análisis ficticio del caso, considerando personal y proveedores, impactos ambientales y costes. | Comprende el trabajo externo; registra el proceso y puede continuar. |
| P8 (pantalla 4) | Confirma la selección final y documenta las razones del caso. | Selección guardada, justificaciones requeridas satisfechas, navegación a datos normativos. |
| P9 (pantalla 5) | Localiza una divulgación y marca qué información tienes y qué falta. Usa «dato ficticio del escenario» en textos; no inventes evidencia real. | Respuestas de inventario guardadas y navegación al informe. |
| P10 (pantalla 6) | Revisa el borrador, las limitaciones y los controles de descarga; explica qué falta para aprobar el informe. | Identifica el carácter de borrador y limitaciones; revisa las descargas disponibles y pulsa «He terminado de revisar el informe y sus descargas». |

P10 no exige una presentación regulatoria ni validar XBRL real. Si se prueban hechos/revisión/aprobación, el moderador debe entregar otra ficha ficticia cerrada y adaptar expresamente los criterios; no producir un informe supuestamente real. Las tareas son independientes de la calidad de las predicciones del modelo.

## Guion del moderador

1. Presentar el estudio: «Evaluamos la herramienta, no tus conocimientos. Puedes parar o retirarte sin consecuencias». Leer información, resolver dudas y permitir rechazar. Solo después de inscribirse comienza la instrumentación; no reconstruir tiempos anteriores.
2. Entregar la ficha y pedir pensar en voz alta sin grabar. Aclarar que los datos del caso son ficticios y que el estudio recoge únicamente los datos enumerados.
3. Leer una consigna cada vez. Evitar indicar dónde hacer clic. Ante una dificultad, preguntar «¿Qué esperabas que ocurriera?»; anotar privadamente por código de participante si hubo ayuda, fallo técnico o criterio no logrado. No pegar texto personal en comentarios.
4. Comprobar el resultado con el criterio de tarea. Navegar sin completar registra abandono. No ayudar a completar para mejorar artificialmente la tasa. Si interrumpe la sesión, anotar la causa; no convertir un intento abierto en éxito o fracaso sin evidencia.
5. Tras P10, solicitar SUS sin explicar la polaridad ni sugerir respuestas. También está disponible a demanda en el aviso. Los comentarios son opcionales. Agradecer y recordar el botón de retirada.
6. Cerrar con las barreras y expectativas expresadas por la persona. Exportar únicamente a un espacio privado autorizado. Al cierre del estudio, borrar también exportaciones, notas y las copias que estén bajo control del equipo.

Duración prevista: 45–60 minutos; es una reserva de agenda, no una medición. Conservar por separado las notas de moderación y sus criterios, sin identidad ni fechas de nacimiento. El éxito instrumental del navegador se contrasta con esas observaciones: no constituye por sí solo prueba del logro.

## SUS y fuentes

Se usan sin reformular los diez ítems españoles de **Sevilla-Gonzalez MDR, Moreno Loaeza L, Lazaro-Carrera LS, Bourguet Ramirez B, Vázquez Rodríguez A, Peralta-Pedrero ML, Almeda-Valdes P. Spanish Version of the System Usability Scale for the Assessment of Electronic Tools: Development and Validation. JMIR Hum Factors 2020;7(4):e21161. DOI [10.2196/21161](https://humanfactors.jmir.org/2020/4/e21161/), Multimedia Appendix 2**. Texto comprobado contra el DOCX publicado, obtenido mediante el servicio público de anexos de Europe PMC para PMC7773510; el binario no se incorpora al repositorio.

© los autores citados; originalmente publicado en JMIR Human Factors, 16-12-2020. Se reutiliza bajo [Creative Commons Attribution 4.0](https://creativecommons.org/licenses/by/4.0/), que permite uso, distribución y reproducción con atribución. El artículo y su licencia no certifican este producto. El catálogo inglés usa los ítems ingleses del mismo anexo, sin el punto tipográfico inicial del ítem 7. Esos ítems ingleses reproducen la redacción original de la escala: Brooke J. «SUS: a quick and dirty usability scale». En: Jordan PW, Thomas B, Weerdmeester BA, McClelland IL (eds.). *Usability Evaluation in Industry*. Londres: Taylor & Francis; 1996. p. 189–194. La interfaz muestra ambas fuentes junto al cuestionario. Ítems: `app/frontend/lib/usability-questionnaire.mjs`; traducciones: `lib/i18n/study-messages.mjs`, integradas en el catálogo existente. El resto del texto español se mantiene; el idioma del cuestionario se almacena.

Escala Likert 1–5 con anclas totalmente en desacuerdo / totalmente de acuerdo y 3 neutral. Sin preselección. Para ítems impares, contribución = respuesta − 1; para pares, contribución = 5 − respuesta. SUS = suma × 2,5 (escala 0–100, **no porcentaje de tareas correctas**). Solo se puntúan diez respuestas válidas. Un envío posterior reemplaza el anterior; no aumenta la muestra. Formularios cerrados sin guardar no se almacenan.

## Implementación, activación y límites

**`USABILITY_STUDY_ENABLED=false` por defecto** en `app/web/config/usability.php`. Migrar primero (`php artisan migrate`), configurar flag en Laravel y refrescar caché de configuración según el procedimiento del entorno. Laravel anuncia `usability_study_enabled` únicamente al estar activo en `/api/auth/session`; el layout autenticado Next monta el aviso solo con ese valor. No hay flag público independiente ni nuevo paquete. `USABILITY_STUDY_ADMIN_USER_IDS` tiene lista vacía por defecto; contiene IDs de cuentas existentes autorizadas por el operador, separados por comas. No es un atributo modificable del usuario ni puede concederse desde una petición.

API de sesión Laravel (`web`, `auth`, verificación condicional, CSRF; límites por usuario/ruta):

- `GET /api/usability-study`: inscripción propia, versión, consentimiento vigente, estados P10/SUS y cuestionario propio completo para edición, solo con consentimiento vigente; 30/min.
- `POST /api/usability-study/enrol`: consentimiento expreso y versión; 5/min.
- `DELETE /api/usability-study`: retirada propia; 10/min. Sigue disponible con el flag apagado, incluso si aún no se migró.
- `POST /api/usability-study/events`: UUID de inscripción propia y tarea, P5–P10, start/complete/abandon, errores opcionales; 60/min. No se admite fecha cliente como autoridad.
- `PUT /api/usability-study/questionnaire`: UUID propio, diez enteros 1–5 y comentarios opcionales acotados; 10/min.
- `GET /api/usability-study/export.csv`: solo administradores de la lista del servidor; 5/min. Sin `user_id`, nombres, correos, respuestas empresariales ni comentarios. Se neutralizan fórmulas incluso precedidas de espacios o controles. Respuestas de datos: sin caché.

Tablas nuevas: `usability_participants`, `usability_tasks`, `usability_events`, `usability_questionnaires`. Las escrituras y la retirada bloquean la cuenta y la inscripción en una transacción. El UUID de consentimiento evita que una petición tardía de una inscripción retirada escriba en una nueva. Los intentos usan UUID independiente e inicios/terminales idempotentes; un terminal contrario devuelve conflicto. Se limita el número de intentos por inscripción como protección de recursos, no como métrica del estudio.

El tracker está en memoria; solo se activa para una inscripción vigente. Las finalizaciones P5/P6/P8/P9 se señalan al continuar tras el flujo de guardado; P7 al continuar desde la guía habilitada; P10 mediante confirmación explícita del participante. El botón de P10 depende del intento actual, mientras que el indicador histórico de P10 sirve para ofrecer SUS. Cada nueva visita puede completarse. Desmontar el layout intenta enviar el abandono pendiente con `keepalive` sin invalidar el consentimiento; retirar el consentimiento sí invalida la cola. Cargar el informe o una pantalla no equivale a completar. Los errores son **avisos nuevos visibles instrumentados**, incluidos validación, carga y guardado donde están disponibles; no todos los fallos de red, descargas o excepciones del navegador. No se envía el texto del error. Esta limitación y la autodeclaración P10 se contrastan con notas del moderador.

Al guardar SUS se conservan respuestas y comentarios en el formulario. Al volver a montar el aviso, Laravel recupera el cuestionario propio completo, sin caché y solo con consentimiento vigente. Cambiar una respuesta conserva el comentario; vaciar expresamente el campo permite borrarlo. El texto se muestra mediante controles React, sin insertar HTML. No se añade a exportaciones ni al informe de métricas.

Salir de una tarea sin completarla registra abandono. Se intenta un POST con `keepalive` en `pagehide`; no se usa `sendBeacon` sin CSRF. El transporte puede perderse al cerrar el navegador o por red/CSRF/sesión; el aviso de errores de instrumentación indica métricas incompletas. No hay cola persistente ni envío a terceros. Los intentos sin terminal permanecen abiertos; **no se inventa su duración ni abandono**. El reloj autoritativo es el del servidor; tiempos incluyen latencia, pausas y trabajo externo P7. Reabrir una tarea crea un intento nuevo. No asumir que el tiempo transcurrido es tiempo activo. Múltiples pestañas pueden generar varios intentos; los UUID los separan y la efectividad no se duplica.

`php artisan usability:purge` borra inscripciones con antigüedad de 90 días y sus hijos; se programa diariamente solo cuando el flag está activo y requiere el scheduler existente. El consentimiento caducado deja de admitir eventos aun si el scheduler no funciona. Reinscribirse tras caducidad borra el estudio anterior y genera otro UUID; no se renueva la retención por reintentos de inscripción, consentimiento a otra versión o actualización SUS. Al cierre ejecutar **`php artisan usability:purge --all`**, borrar exportaciones/notas privadas y apagar el flag. Antes de desactivarlo, purgar: el aviso deja de montarse cuando Laravel deja de anunciar el flag. Si quedan datos con recogida apagada, la retirada API y el comando de borrado continúan disponibles. Copias de seguridad siguen su política de rotación del responsable: el botón no borra copias exportadas ni garantiza eliminación instantánea de backups. No publicar exportaciones; pseudonimizar no equivale a anonimizar.

## Plan de análisis

Ejecutar `php artisan usability:report` en el servidor autorizado: JSON por participante y agregado, sin identidad ni texto libre. Los comentarios quedan almacenados para revisión privada autorizada; este kit no añade una pantalla de lectura de comentarios. `--csv` produce las mismas filas participante/tarea que el endpoint admin. Guardar salidas **privadamente**, nunca en Git. El CLI es para operadores con acceso al servidor; el endpoint sí exige sesión y autorización admin. No contiene datos demo, semillas ni participantes preinstalados.

- SUS: una puntuación por participante con cuestionario completo. Media, desviación estándar **muestral**, IC bilateral 95 % de Student t: media ± t(0,975, n−1) × SD/√n. Tabla df 1–30; para n mayor se usa df=30 conservador. Intervalo sin recortar al rango de la escala; interpretar límites fuera de rango como incertidumbre, no como puntuaciones posibles. Sin respuestas: media/SD/IC vacíos; con una respuesta: SD/IC vacíos. No imputar cuestionarios omitidos.
- Efectividad primaria: parejas participante/tarea con al menos un intento completo / (inscripciones incluidas × seis tareas). Por tarea: participantes con éxito / inscripciones incluidas. Por participante: tareas con éxito / seis. Repeticiones no elevan el numerador. También se informa completados / iniciados por intento. Inscripciones sin tareas siguen en el denominador; informar ausencias, abandono y casos abiertos separadamente.
- Eficiencia: segundos por intento completo y por todos los intentos terminados (incluye abandonos), con media, SD e IC descriptivos. Nunca calcular tiempo de un intento abierto. Respetar la dependencia de intentos repetidos: estos intervalos no prueban diferencias de población. Para comparar participantes usar notas y una regla de selección de intento predefinida.
- Errores: suma de recuentos observados y número de intentos con recuento disponible; falta de observación se deja vacía, no se sustituye por cero. Un intento instrumentado sin avisos tiene cero. Fallos y ayudas moderadas adicionales se analizan en notas, fuera de esa suma.
- Analizar barreras, contexto y ayuda cualitativamente por código y tarea. Registrar oportunidades de mejora con prioridad y evidencia; repetir una ronda después de corregir. No presentar medidas pequeñas y de conveniencia como representativas o causales.

## Plantilla de resultados reales (rellenar tras el estudio)

No completar estas celdas con pruebas automáticas ni datos sintéticos.

| Campo | Valor real |
|---|---|
| Fecha de inicio / cierre | |
| Versión/commit del producto | |
| Entorno y condiciones | |
| Personas invitadas / inscritas / retiradas | |
| Perfil y criterios de selección | |
| Cuestionarios completos / omitidos | |
| SUS media / SD / IC 95 % | |
| Tasa de finalización de tareas | |
| Problemas de instrumentación / intentos abiertos | |
| Ayudas del moderador y desviaciones | |
| Limitaciones de los resultados | |
| Ubicación privada de evidencias | |
| Fecha real de borrado | |

| Código participante | SUS | Tareas completas | Tiempo por tarea | Errores observados | Ayudas | Barreras |
|---|---|---|---|---|---|---|
| | | | | | | |

| Tarea | Finalización | Tiempo completados (media / SD / IC) | Abandonos / abiertos | Errores / cobertura | Observación |
|---|---|---|---|---|---|
| P5 | | | | | |
| P6 | | | | | |
| P7 | | | | | |
| P8 | | | | | |
| P9 | | | | | |
| P10 | | | | | |

| Problema | Evidencia por código/tarea | Prioridad | Mejora propuesta | Responsable | Fecha de comprobación |
|---|---|---|---|---|---|
| | | | | | |

## Pruebas

Laravel, desde `app/web`: `php artisan test --filter='Usability|StudyAnalysis'`, que ejecuta `tests/Feature/Api/UsabilityStudyApiTest.php` y `tests/Unit/Usability/StudyAnalysisTest.php`. Cubren sesión y administración, variable apagada, consentimiento y versiones, propiedad, idempotencia, retirada en cascada, validación, límites, retención, SUS, incertidumbre, tiempos, reintentos y neutralización de CSV.

Frontend, desde `app/frontend`: `node --test tests/*.mjs` y `node_modules/.bin/tsc --noEmit --incremental false -p .`; prueba acotada: `node --test tests/usability-study.test.mjs`. Comprueba el tracker real, la ausencia de eventos sin inscripción, la revocación de trabajo pendiente, los errores de transporte, el orden y la idempotencia, los idiomas y el montaje condicionado. Las regresiones ejecutan el TSX real con un runtime de hooks acotado: orden de desmontaje con transporte pendiente, regreso a P10 y guardar, reabrir, editar y recargar el SUS. No equivale a una sesión real de navegador. El kit no cambia el servicio Python ni añade dependencias, y las pruebas no generan resultados de participantes.

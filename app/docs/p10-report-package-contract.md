# Contrato del paquete P10

Estado: contrato técnico público.

## Alcance

P10 produce un paquete revisable de preparación de informe a partir del estado
confirmado del flujo P5-P9. El paquete puede incluir:

- una previsualización de informe dentro de la aplicación autenticada;
- un paquete revisable en navegador y apto para impresión por el usuario;
- un paquete JSON de evidencias con caracterización, materialidad final,
  preparación de datapoints, limitaciones y trazabilidad AR16 a ESRS;
- un candidato estructural técnico iXBRL cuando se usa el endpoint
  correspondiente.

El paquete no incluye presentación oficial ante un regulador, aseguramiento,
opinión legal, atestación de taxonomía ni salida aceptada por un regulador.

## Entradas

La generación consume estado persistido del flujo:

- caracterización y alcance de la organización;
- temas candidatos P6 solo como contexto de propuesta;
- estado de guía, checklist y acta de doble materialidad P7;
- materialidad final confirmada por el usuario en P8;
- conjunto efectivo de datapoints P9, respuestas de usuario, preparación y
  limitaciones;
- versión configurada del mapa AR16 a ESRS Disclosure Requirement.

La generación no debe modificar la caracterización ni la materialidad. Los
cambios de perfil del modelo runtime quedan fuera del alcance de P10.

## Almacenamiento y regeneración

El modelo de almacenamiento por defecto es la regeneración desde el estado
P5-P9. Salvo decisión funcional posterior, se persisten metadatos y no
artefactos generados.

Si más adelante se introducen ficheros de informe o campos de base de datos
persistidos, el producto debe definir migración, reversión, retención y
regeneración.

## Texto de límite requerido

La interfaz P10 y las salidas del paquete deben expresar el límite del producto
de forma clara:

IA4S ayuda a preparar divulgaciones ESRS 2023 y a organizar evidencias. No es
software de presentación oficial, aseguramiento, opinión legal, atestación de
taxonomía ni salida aceptada por un regulador.

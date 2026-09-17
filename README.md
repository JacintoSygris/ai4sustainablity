# IA4Sustainability

IA4Sustainability es una aplicación para apoyar el flujo de sostenibilidad P5–P10: caracterización de la organización, propuesta candidata, revisión de doble materialidad, confirmación humana, captura de datos ESRS y preparación técnica de un paquete de informe.

Incluye:

- Frontend Next.js en `app/frontend`.
- API y persistencia Laravel en `app/web`.
- Servicio FastAPI con el perfil de modelo incluido en `app/ai-service`.
- Contratos y mapeos ESRS/AR16 en `app/contracts`.
- Exportación técnica XHTML/iXBRL preparada para validación estructural.

## Inicio rápido

```sh
git clone https://github.com/JacintoSygris/ai4sustainablity.git
cd ai4sustainablity/app
docker compose -f compose.public.yml up --build -d
```

Con Podman:

```sh
podman-compose -f compose.public.yml up --build -d
```

Abre `http://localhost:3000`, registra un usuario y accede al flujo guiado.

La documentación completa de instalación, configuración y límites del entorno local está en [`app/docs/installation-and-configuration.md`](app/docs/installation-and-configuration.md).

## Alcance

Las predicciones del modelo en P6 son propuestas candidatas y la materialidad final requiere confirmación humana en P8. El paquete P10 es una preparación técnica: no constituye una presentación oficial, aseguramiento, opinión legal ni garantía de aceptación regulatoria.

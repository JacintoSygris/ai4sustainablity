# IA, mapping y reporting

Volver al [manual tecnico](index.md).

## Servicio FastAPI incluido

El servicio IA incluido arranca con Uvicorn y expone solo:

- `GET /healthz`;
- `GET /model-profiles`;
- `POST /predict`.

No expone `POST /extract-document`.

Precondicion: `ai-service` en red privada.

```sh
# Directorio: /srv/ia4sustainability
docker compose exec ai-service python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8001/healthz', timeout=5).read().decode())"
docker compose exec ai-service python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8001/model-profiles', timeout=5).read().decode())"
```

Resultado esperado: `status` es `ok` y el perfil activo es `new_format_732_v1_gpt41`.

Comprobacion desde Laravel: configurar `CHARACTERIZATION_GATEWAY=api` y `CHARACTERIZATION_API_BASE_URL=http://ai-service:8001`; una caracterizacion enviada debe producir una propuesta tecnica o un error controlado.

## Mapping ESRS

El mapeo de asuntos a requisitos de divulgacion se activa con una ruta inmutable:

```dotenv
ESRS_MATTER_DR_MAPPING_PATH=/var/www/html/data/ar16_to_esrs_dr_mapping_esrs2023_v1.json
```

Precondicion: archivo presente dentro del contenedor Laravel y con `source.status=approved`.

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web php artisan esrs:validate-matter-dr-mapping /var/www/html/data/ar16_to_esrs_dr_mapping_esrs2023_v1.json
```

Resultado esperado: el comando termina correctamente y lista cobertura de asuntos y requisitos.

Comprobacion: si la ruta falta, el JSON no es valido o el estado no es `approved`, Laravel trata el mapping como no disponible.

## Informacion ESRS, exportaciones y candidato tecnico

Las salidas de informacion ESRS y el paquete/candidato tecnico requieren precondiciones reales de estado y datos. Una descarga disponible o un estado listo no equivale a presentacion regulatoria, aseguramiento, validacion de contenido ni aceptacion por terceros.

Precondiciones tecnicas habituales:

- usuario autenticado y, si se exige, email verificado;
- caracterizacion creada;
- decision de materialidad registrada;
- respuestas de datapoints/facts suficientes;
- mapping ESRS/DR validado si la salida lo necesita;
- assets de reporting presentes e integros;
- Arelle ejecutable para validacion del candidato iXBRL.

Validar assets:

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web php artisan report:validate-assets
docker compose exec web php artisan taxonomy:validate-assets esrs-set1-2024
```

Resultado esperado: ambos comandos informan `OK`.

## Arelle y taxonomia

El Dockerfile publico instala Arelle en `/opt/arelle/bin/arelleCmdLine` y el validador lo usa por defecto. La validacion se ejecuta offline con paquetes vendorizados. Si el binario falta, no es ejecutable o los paquetes fallan integridad, el candidato iXBRL debe considerarse fallido tecnicamente.

Comprobacion:

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web /opt/arelle/bin/arelleCmdLine --version
```

Resultado esperado: Arelle responde con version. Esto no prueba aceptacion regulatoria.

## Interpretacion estricta del candidato XHTML/iXBRL

El candidato XHTML/iXBRL es una salida tecnica para revision. `arelle_validation.status=passed` solo significa que la validacion tecnica configurada no ha fallado. No demuestra que el informe sea completo, correcto, auditado, presentado ni aceptado. Cualquier uso externo requiere revision profesional y procedimiento del operador.

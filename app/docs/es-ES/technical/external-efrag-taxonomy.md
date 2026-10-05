# Taxonomía EFRAG externa para el candidato XHTML/iXBRL

Volver al [manual técnico](index.md).

## Requisito para XHTML/iXBRL

La salida XHTML/iXBRL de IA4Sustainability es un candidato técnico, no una presentación oficial. Para habilitarla, la instalación necesita el paquete oficial `ESRS-Set1-XBRL-Taxonomy.zip` de `EFRAG ESRS XBRL Taxonomy Set 1`, versión `2023-12-22`, para el perfil `esrs-2023-preparatory-v1`.

Este requisito afecta únicamente al candidato XHTML/iXBRL. La ausencia del paquete no impide usar las salidas HTML, DOCX o JSON de evidencias que no dependan de XHTML/iXBRL.

## Por qué el ZIP no se incluye en el repositorio

El paquete se descarga y se instala por separado en el servidor. No se incorpora a Git, imágenes de contenedor ni releases por tres motivos:

1. Descargar una copia autorizada no concede automáticamente derecho a redistribuirla. Incluirla en un repositorio público distribuiría una copia en cada clon, fork, imagen y release.
2. La validación debe quedar vinculada al archivo exacto instalado por quien opera el sistema. Por eso se calcula su SHA-256 en el servidor y se registra en un manifiesto privado.
3. Separar el paquete del despliegue evita que una actualización de aplicación sustituya silenciosamente la taxonomía y permite limitar su lectura al proceso de validación.

## Instalación en el servidor

Defina un directorio externo que no sea un checkout, imagen, release ni almacenamiento de Laravel. Por ejemplo:

```sh
export TAXONOMY_DIR=/srv/ia4sustainability/external-taxonomy/esrs-set1-2023
install -d -m 0750 "$TAXONOMY_DIR"
```

1. Obtenga el ZIP oficial bajo los derechos aplicables y cópielo a:

   ```text
   $TAXONOMY_DIR/ESRS-Set1-XBRL-Taxonomy.zip
   ```

2. Calcule el checksum en el propio servidor:

   ```sh
   sha256sum "$TAXONOMY_DIR/ESRS-Set1-XBRL-Taxonomy.zip"
   ```

3. Cree un manifiesto privado junto al ZIP. No lo añada al repositorio, a una imagen ni a una release:

   ```json
   {
     "schema_version": "external_taxonomy_manifest_v1",
     "profile_id": "esrs-2023-preparatory-v1",
     "taxonomy_entrypoint": "https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd",
     "taxonomy_package_path": "/srv/ia4sustainability/external-taxonomy/esrs-set1-2023/ESRS-Set1-XBRL-Taxonomy.zip",
     "taxonomy_package_checksum": "SHA-256-calculado-en-el-servidor",
     "external_taxonomy_package_confirmed": true
   }
   ```

4. Configure Laravel con rutas absolutas:

   ```dotenv
   ESRS_EXTERNAL_TAXONOMY_MANIFEST_PATH=/srv/ia4sustainability/external-taxonomy/esrs-set1-2023/manifest.json
   ESRS_ARELLE_COMMAND=/ruta/absoluta/a/arelleCmdLine
   ```

5. Recargue la configuración y reinicie los procesos de la aplicación según el método de despliegue elegido. Confirme que el usuario de servicio puede leer el ZIP, el manifiesto y el ejecutable, sin ampliar esos permisos a otros procesos.

## Verificación y comportamiento seguro

El manifiesto debe coincidir con el perfil, el entrypoint y el SHA-256 del ZIP. El candidato XHTML/iXBRL se bloquea si falta el manifiesto, el paquete no es legible, el checksum no coincide, la ruta pertenece a la aplicación o al almacenamiento de Laravel, falta Arelle o Arelle informa un fallo.

Arelle se ejecuta sin red, con `--internetConnectivity=offline` y `--validate`. La aplicación no descarga taxonomías durante la validación.

P10 muestra a la persona autenticada el nombre de taxonomía, la versión, el perfil y un estado de disponibilidad. No muestra la ruta local, el contenido del manifiesto ni el SHA-256 completo.

Un resultado `validated_candidate` sólo indica que pasaron las comprobaciones técnicas configuradas. No acredita que el informe sea completo, correcto, asegurado, presentado o aceptado por una autoridad.

## Paquetes genéricos country y codelist-common: instalación separada

Estos paquetes tienen una configuración independiente de EFRAG. Los consumidores probados son únicamente `XbrlCountryTaxonomyPackage` y `XbrlCodelistCommonTaxonomyPackage`, mediante `verifiedPath()`. No se ha verificado su integración con Arelle; no cambian el modelo de taxonomía ni el requisito externo de EFRAG descrito arriba.

### Copias exactas y disposición externa

La persona operadora debe obtener bajo derechos aplicables las versiones exactas `xbrl-country-current-2024-snapshot` y `xbrl-codelist-common-2024-snapshot`, con sus dos manifiestos originales, dos ZIP y nueve archivos fuente. La procedencia declarada es [XBRL country](https://www.xbrl.org/taxonomy/int/country/current/) y [XBRL codelist-common 2024](https://www.xbrl.org/taxonomy/int/codelist-common/2024/). Estas URLs no acreditan permiso para redistribuir los snapshots exactos; la custodia externa tampoco concede derechos. No hay descarga automática ni aprobación de licencia implícita.

Conserve sin cambios los manifiestos y los bytes fijados; no regenere los ZIP. Coloque este árbol bajo una raíz absoluta externa, por ejemplo `/opt/external-generic-xbrl`:

```text
data/xbrl/taxonomies/
  xbrl-country-current-2024-snapshot.manifest.json
  xbrl-country-current-2024-snapshot.zip
  xbrl-country-current-2024-source/
    entry-en.xsd
    entry.xsd
    elts.xsd
    label-en.xml
    label-code.xml
    reference.xml
    definition.xml
  xbrl-codelist-common-2024-snapshot.manifest.json
  xbrl-codelist-common-2024-snapshot.zip
  xbrl-codelist-common-2024-source/
    role-label-code.xsd
    property-part.xsd
```

Son 13 archivos, 788794 bytes en total. No incorpore estos bytes a repositorios, imágenes, releases ni artefactos de distribución. La raíz debe estar fuera del checkout, de la release, de la imagen y del almacenamiento de Laravel. Monte el directorio externo de solo lectura en el runtime; en Docker, use un bind mount de solo lectura con destino `/opt/external-generic-xbrl`. En una instalación directa, aplique ACL/permisos que permitan al usuario de servicio leer, sin escribir.

Los ZIP deben coincidir con los valores fijados en los loaders:

| ZIP | Bytes | SHA-256 esperado |
|---|---:|---|
| `xbrl-country-current-2024-snapshot.zip` | 35563 | `bbcaa6097bdbfa67880b3f1e7435fc203eaa8ed24fdb6c093efa75687fcf4e55` |
| `xbrl-codelist-common-2024-snapshot.zip` | 2387 | `a4ef52ff46f4489309ead522e180aff93cfc3ad13e47ffb61ae7aa8b633c5a1e` |

### Configuración y denegación por defecto

Configure en el entorno del runtime gestionado por la persona operadora:

```dotenv
XBRL_GENERIC_TAXONOMY_ROOT=/opt/external-generic-xbrl
```

Los dos loaders resuelven exclusivamente `services.report.generic_xbrl_root`, alimentado por esa variable. No aceptan la raíz desde la API ni tienen ruta de respaldo o valor por defecto. Recargue la configuración y reinicie los procesos según el método de despliegue existente; asegure que leen la variable del runtime.

Sin raíz configurada, o con una raíz/archivo ausente o inválido, la carga se deniega. El resolver rechaza rutas relativas, checkout, almacenamiento, padres protegidos y alias por symlink/junction. Mantiene las comprobaciones de manifiesto, versión, SHA-256, tamaño, miembros y catálogo; los metadatos lógicos siguen usando `data/xbrl/taxonomies/...`, aunque los bytes residan fuera. La matriz nativa de symlinks/junctions sigue sin cualificar: estas comprobaciones no equivalen a aceptación completa de seguridad.

### Cualificación local y despliegue

Con dependencias de prueba instaladas y los archivos externos ya aprovisionados, ejecute desde `app/web`:

```sh
# app/web
XBRL_GENERIC_TAXONOMY_ROOT=/opt/external-generic-xbrl \
  php vendor/bin/pest --filter='(XbrlCountryTaxonomyPackageTest|XbrlCodelistCommonTaxonomyPackageTest|GenericXbrlPackageRootTest)'
```

Las pruebas de paquetes usan copias externas desechables y necesitan permisos para crearlas fuera del checkout, separadas de los originales de solo lectura. Aprovisione explícitamente estos inputs antes de CI. Un checkout limpio sin ellos no acredita una cualificación positiva: las pruebas de paquetes fallan, sin skip ni respaldo silencioso. Este ejemplo no aprovisiona automáticamente Jenkins; el despliegue PUBLIC corresponde a la persona operadora y se ejecuta solo después de comprobar su preparación.

La evidencia local verificada del 2026-10-05 registra 24/24 pruebas, 73 aserciones y cero fallos, errores o skips: 20 casos del resolver y cuatro casos de paquetes reales (un positivo y una alteración de manifiesto por loader). `report:validate-assets` terminó por separado con salida 0 usando el validador sin cambios; comprueba los assets de informes, no prueba positivamente la carga de estos paquetes.

Instalar/configurar, custodiar copias exactas, cualificar localmente y aceptar una release en producción son estados distintos. Estos resultados no acreditan despliegue ni preparación para producción. Retirar los bytes del árbol actual no purga HEAD ni el historial Git previo; no autoriza reescribirlo ni redistribuir copias anteriores. Los datos de negocio son ficticios; esta instalación no habilita entrenamiento real ni una nueva capacidad pública de entrenamiento.

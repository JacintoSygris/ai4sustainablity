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

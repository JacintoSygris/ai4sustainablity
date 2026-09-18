# External EFRAG taxonomy for the XHTML/iXBRL candidate

Back to the [technical manual](index.md).

## XHTML/iXBRL requirement

IA4Sustainability's XHTML/iXBRL output is a technical candidate, not an official filing. Enabling it requires the official `ESRS-Set1-XBRL-Taxonomy.zip` package for `EFRAG ESRS XBRL Taxonomy Set 1`, version `2023-12-22`, under profile `esrs-2023-preparatory-v1`.

This requirement affects only the XHTML/iXBRL candidate. A missing package does not prevent use of HTML, DOCX or JSON evidence outputs that do not depend on XHTML/iXBRL.

## Why the ZIP is not included in the repository

The package is downloaded and installed separately on the server. It is not included in Git, container images or releases for three reasons:

1. Downloading an authorised copy does not automatically grant the right to redistribute it. Including it in a public repository would distribute a copy through every clone, fork, image and release.
2. Validation must be tied to the exact file installed by the system operator. Its SHA-256 is therefore calculated on the server and recorded in a private manifest.
3. Keeping the package separate from deployment prevents an application update from silently replacing the taxonomy and allows read access to be limited to the validation process.

## Server installation

Define an external directory that is not a checkout, image, release or Laravel storage. For example:

```sh
export TAXONOMY_DIR=/srv/ia4sustainability/external-taxonomy/esrs-set1-2023
install -d -m 0750 "$TAXONOMY_DIR"
```

1. Obtain the official ZIP under the applicable rights and copy it to:

   ```text
   $TAXONOMY_DIR/ESRS-Set1-XBRL-Taxonomy.zip
   ```

2. Calculate its checksum on the server:

   ```sh
   sha256sum "$TAXONOMY_DIR/ESRS-Set1-XBRL-Taxonomy.zip"
   ```

3. Create a private manifest next to the ZIP. Do not add it to the repository, an image or a release:

   ```json
   {
     "schema_version": "external_taxonomy_manifest_v1",
     "profile_id": "esrs-2023-preparatory-v1",
     "taxonomy_entrypoint": "https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd",
     "taxonomy_package_path": "/srv/ia4sustainability/external-taxonomy/esrs-set1-2023/ESRS-Set1-XBRL-Taxonomy.zip",
     "taxonomy_package_checksum": "SHA-256-calculated-on-the-server",
     "external_taxonomy_package_confirmed": true
   }
   ```

4. Configure Laravel with absolute paths:

   ```dotenv
   ESRS_EXTERNAL_TAXONOMY_MANIFEST_PATH=/srv/ia4sustainability/external-taxonomy/esrs-set1-2023/manifest.json
   ESRS_ARELLE_COMMAND=/absolute/path/to/arelleCmdLine
   ```

5. Reload configuration and restart application processes according to the selected deployment method. Confirm that the service user can read the ZIP, manifest and executable without extending that permission to other processes.

## Verification and safe behaviour

The manifest must match the profile, entrypoint and ZIP SHA-256. The XHTML/iXBRL candidate is blocked when the manifest is absent, the package cannot be read, the checksum does not match, the package path belongs to the application or Laravel storage, Arelle is missing, or Arelle reports a failure.

Arelle runs without network access using `--internetConnectivity=offline` and `--validate`. The application does not download taxonomies while validating.

P10 shows authenticated users the taxonomy name, version, profile and availability state. It does not show the local path, manifest contents or full SHA-256.

A `validated_candidate` result means only that the configured technical checks passed. It does not demonstrate that a report is complete, correct, assured, filed or accepted by an authority.

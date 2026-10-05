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

## Generic country and codelist-common packages: separate installation

These packages have configuration independent of EFRAG. The only proven consumers are `XbrlCountryTaxonomyPackage` and `XbrlCodelistCommonTaxonomyPackage`, through `verifiedPath()`. Their integration with Arelle has not been verified; they do not change the taxonomy model or EFRAG's external requirement described above.

### Exact copies and external layout

The operator must obtain the exact versions `xbrl-country-current-2024-snapshot` and `xbrl-codelist-common-2024-snapshot` under applicable rights, with their two original manifests, two ZIPs and nine source files. Declared provenance is [XBRL country](https://www.xbrl.org/taxonomy/int/country/current/) and [XBRL codelist-common 2024](https://www.xbrl.org/taxonomy/int/codelist-common/2024/). These URLs do not establish permission to redistribute the exact snapshots; external custody grants no rights either. There is no automatic download or implicit licence approval.

Keep the manifests and pinned bytes unchanged; do not regenerate the ZIPs. Place this tree under an absolute external root, for example `/opt/external-generic-xbrl`:

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

There are 13 files, totalling 788794 bytes. Do not include these bytes in repositories, images, releases or distribution artefacts. The root must be outside the checkout, release, image and Laravel storage. Mount the external directory read-only into the runtime; in Docker, use a read-only bind mount targeting `/opt/external-generic-xbrl`. For a direct installation, apply ACLs/permissions allowing the service user to read without writing.

The ZIPs must match the values pinned in the loaders:

| ZIP | Bytes | Expected SHA-256 |
|---|---:|---|
| `xbrl-country-current-2024-snapshot.zip` | 35563 | `bbcaa6097bdbfa67880b3f1e7435fc203eaa8ed24fdb6c093efa75687fcf4e55` |
| `xbrl-codelist-common-2024-snapshot.zip` | 2387 | `a4ef52ff46f4489309ead522e180aff93cfc3ad13e47ffb61ae7aa8b633c5a1e` |

### Configuration and denial by default

Configure the operator-owned runtime environment:

```dotenv
XBRL_GENERIC_TAXONOMY_ROOT=/opt/external-generic-xbrl
```

Both loaders resolve only `services.report.generic_xbrl_root`, supplied by this variable. They do not accept the root through the API and have no fallback path or default value. Reload configuration and restart processes using the existing deployment method; ensure they read the runtime variable.

Loading is denied when the root is unset or a root/file is missing or invalid. The resolver rejects relative paths, checkout, storage, protected parents and symlink/junction aliases. Manifest, version, SHA-256, size, member and catalogue checks are retained; logical metadata still uses `data/xbrl/taxonomies/...` even though the bytes reside externally. The native symlink/junction matrix remains unqualified: these checks do not constitute full security acceptance.

### Local qualification and deployment

With test dependencies installed and external files already provisioned, run from `app/web`:

```sh
# app/web
XBRL_GENERIC_TAXONOMY_ROOT=/opt/external-generic-xbrl \
  php vendor/bin/pest --filter='(XbrlCountryTaxonomyPackageTest|XbrlCodelistCommonTaxonomyPackageTest|GenericXbrlPackageRootTest)'
```

Package tests use disposable external copies and need permission to create them outside the checkout, separate from the read-only originals. Explicitly provision these inputs before CI. A clean checkout without them cannot establish positive qualification: package tests fail, with no skip or silent fallback. This example does not automatically provision Jenkins; PUBLIC deployment is operator-owned and runs only after readiness checks.

Local evidence verified on 2026-10-05 records 24/24 tests, 73 assertions and zero failures, errors or skips: 20 resolver cases and four real-package cases (one positive and one manifest-tampering case per loader). `report:validate-assets` separately returned exit 0 using the unchanged validator; it checks report assets and does not positively prove loading of these packages.

Installation/configuration, custody of exact copies, local qualification and acceptance of a production release are distinct states. These results do not establish deployment or production readiness. Removing bytes from the current tree does not purge HEAD or prior Git history; it does not authorise rewriting that history or redistributing earlier copies. Business data is fictitious; this installation enables neither real training nor a new public training capability.

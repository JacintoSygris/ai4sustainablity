# NACE/CNAE Catalog

`nace_codes.json` is the runtime catalog used by `NaceCodeSeeder`.

Source:
- `docs/source/Estructura_CNAE2025.xlsx`
- Sheet: `Estructura_CNAE2025`
- Source date shown in workbook: January 2025

The source currently used is the Spanish CNAE 2025 structure. Until an
authoritative English NACE Rev. 2.1/CNAE 2025 title source is added, both
`title.es` and `title.en` intentionally store the Spanish title. This avoids
mixing old NACE Rev. 2 English labels with CNAE 2025 section/code changes.

The app keeps the existing section-prefixed code style for compatibility with
the current UI and saved values:

- Section: `A`
- Division `01`: `A1`
- Group `01.1`: `A1.1`
- Class `01.11`: `A1.1.1`
- Division `62`: `K62`

Expected current counts:

- Sections: 22
- Divisions: 87
- Groups: 287
- Classes: 664
- Total: 1060

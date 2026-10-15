#!/usr/bin/env python3
"""Generate with the PHP product renderer, provision public schemas, then validate offline.

The operator supplies the official ESRS taxonomy package (--taxonomy-package) under the
applicable rights and confirms them explicitly (--confirm-external-taxonomy-rights); this
tool never downloads the taxonomy. Taxonomy, venv, dependency cache, logs, synthetic ZIP
and evidence summaries stay outside the repository; never commit them, because messages
can quote taxonomy labels. Only the public UTR and public XBRL/W3C schemas are fetched.
The full validation determines the exit status; a formula-disabled diagnostic is NOT a pass.
"""
from __future__ import annotations

import argparse
from collections import Counter
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
from urllib.request import urlopen
import venv
from zipfile import ZipFile

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[3]
TAXONOMY_URL = "https://xbrl.efrag.org/downloads/ESRS-Set1-XBRL-Taxonomy.zip"
UTR_URL = "https://www.xbrl.org/utr/utr.xml"
ENTRYPOINT = "https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd"


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def download(url: str, path: Path) -> None:
    with urlopen(url, timeout=60) as response:
        content = response.read(32 * 1024 * 1024 + 1)
    if len(content) > 32 * 1024 * 1024:
        raise ValueError("Public resource exceeds download budget")
    path.write_bytes(content)


def summarize(log_path: Path, exit_code: int) -> dict:
    entries = json.loads(log_path.read_text())["log"]
    counts = Counter(entry["level"].lower() for entry in entries)
    errors = [entry for entry in entries if entry["level"].lower() in ("error", "fatal", "critical")]
    return {
        "exit_code": exit_code,
        "error_count": len(errors),
        "warning_count": counts["warning"],
        "completed": any("validated in " in entry.get("message", {}).get("text", "") for entry in entries),
        "first_errors": [{"code": entry["code"], "message": entry["message"]["text"][:650]} for entry in errors[:5]],
        "error_code_families": dict(Counter(entry["code"].split(":")[0] for entry in errors)),
        "log_sha256": sha(log_path),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default="php", help="PHP CLI with DOM and ZipArchive")
    parser.add_argument("--scratch", type=Path, help="External scratch directory; defaults to a new temporary directory")
    parser.add_argument("--taxonomy-package", type=Path, required=True,
                        help="Official ESRS taxonomy package ZIP obtained by the operator (outside the repository); "
                             "official distribution: " + TAXONOMY_URL)
    parser.add_argument("--confirm-external-taxonomy-rights", action="store_true",
                        help="Confirm that the operator holds the rights to use the supplied taxonomy package")
    parser.add_argument("--evidence-prefix", type=Path,
                        help="Evidence path prefix outside the repository; defaults to <scratch>/evidence/arelle-validation")
    args = parser.parse_args()
    if not args.confirm_external_taxonomy_rights:
        parser.error("--confirm-external-taxonomy-rights is required; nothing was generated")
    taxonomy = args.taxonomy_package.resolve()
    if taxonomy == REPO or REPO in taxonomy.parents:
        parser.error("The taxonomy package must be outside the repository")
    if not taxonomy.is_file():
        parser.error("The taxonomy package does not exist or is not a file")
    scratch = (args.scratch or Path(tempfile.mkdtemp(prefix="ixbrl-arelle-"))).resolve()
    if scratch == REPO or REPO in scratch.parents:
        parser.error("Scratch must be outside the repository")
    evidence_prefix = (args.evidence_prefix or scratch / "evidence" / "arelle-validation").resolve()
    if evidence_prefix == REPO or REPO in evidence_prefix.parents:
        parser.error("Evidence must be written outside the repository")
    scratch.mkdir(parents=True, exist_ok=True)
    environment = scratch / "venv"
    if not (environment / "bin/python").exists():
        venv.EnvBuilder(with_pip=True).create(environment)
    subprocess.run([str(environment / "bin/python"), "-m", "pip", "install", "--no-cache-dir", "-r", str(HERE / "requirements-validation.txt")], check=True)
    utr = scratch / "utr.xml"
    download(UTR_URL, utr)
    manifest = {
        "schema_version": "external_taxonomy_manifest_v1", "profile_id": "esrs-2023-preparatory-v1",
        "taxonomy_entrypoint": ENTRYPOINT, "taxonomy_package_path": str(taxonomy),
        "taxonomy_package_checksum": sha(taxonomy),
        "external_taxonomy_package_confirmed": args.confirm_external_taxonomy_rights is True,
    }
    manifest_path = scratch / "manifest.json"
    manifest_path.write_text(json.dumps(manifest, indent=2) + "\n")
    sample = scratch / "sample.zip"
    subprocess.run([args.php, str(HERE / "generate_sample.php"), str(manifest_path), str(sample)], check=True)
    arelle = str(environment / "bin/arelleCmdLine")
    common = [arelle, "--xdgConfigHome", str(scratch / "config"), "--cacheDirectory", str(scratch / "cache"),
              "--disablePersistentConfig", "--internetRecheck", "never", "--packages", str(taxonomy), "--file", str(sample)]
    (scratch / "provision-log.json").unlink(missing_ok=True)
    # Acquisition only: resolve public XBRL/W3C dependencies before disconnecting validation.
    subprocess.run(common + ["--internetConnectivity", "online", "--internetTimeout", "20", "--internetLogDownloads",
                             "--logFile", str(scratch / "provision-log.json")], check=True, timeout=600)
    dependencies = []
    for path in sorted((scratch / "cache").rglob("*")):
        if path.is_file() and path.relative_to(scratch / "cache").parts[0] in ("http", "https"):
            relative = path.relative_to(scratch / "cache").as_posix()
            protocol, rest = relative.split("/", 1)
            dependencies.append({"url": protocol + "://" + rest, "sha256": sha(path)})
    dependency_manifest = scratch / "dependencies.json"
    dependency_manifest.write_text(json.dumps(dependencies, indent=2) + "\n")
    runs = {}
    for name, extra in [("full", []), ("structural_diagnostic", ["--formula=none"])]:
        log = scratch / (name + "-log.json")
        # Arelle appends to existing log files; each evidence run must parse one fresh document.
        log.unlink(missing_ok=True)
        command = common + ["--internetConnectivity", "offline", "--validate", "--validationExitCode", "--utr", "--utrUrl", str(utr), "--logFile", str(log), *extra]
        result = subprocess.run(command, timeout=180)
        runs[name] = summarize(log, result.returncode)
        runs[name]["validation_arguments"] = ["--internetConnectivity=offline", "--validate", "--validationExitCode", "--utr", *extra]
    with ZipFile(sample) as archive:
        package_entries = archive.namelist()
    source_paths = [HERE / "generate_sample.php", HERE / "validate_with_arelle.py", HERE / "requirements-validation.txt"]
    web = REPO / "app/web"
    source_paths += [web / "app/Services/Report" / name for name in (
        "XhtmlIxbrlCandidateRenderer.php", "TaxonomyProfileRegistry.php", "TaxonomyConceptMetadata.php", "IxbrlReportPackage.php", "EvidenceBundleBuilder.php", "XbrlConceptMap.php")]
    source_paths += [web / "config/ixbrl.php", web / "data/atomizer_xbrl_concepts_v1.json"]
    summary = {
        "run_at": datetime.now(timezone.utc).isoformat(), "synthetic": True, "filing_ready": False,
        "arelle_version": subprocess.check_output([arelle, "--version"], text=True).strip(),
        "taxonomy_profile": "esrs_set1_2023-12-22", "taxonomy_package_source": "operator_supplied",
        "taxonomy_sha256": sha(taxonomy), "taxonomy_entrypoint": ENTRYPOINT,
        "dependency_manifest_sha256": sha(dependency_manifest), "utr_url": UTR_URL, "utr_sha256": sha(utr),
        "package_sha256": sha(sample), "package_entries": package_entries,
        "source_sha256": {str(path.relative_to(REPO)): sha(path) for path in source_paths},
        "runs": runs,
        "limits": ["Synthetic numeric fixture only; no real company or filing.",
                   "Full validation includes EFRAG formula assertions and determines fail-closed result.",
                   "Formula-disabled run is diagnostic only; never used by the HTTP download gate.",
                   "No ESMA filing-rule validation, ESEF/ESAP submission or 2026 draft mapping."],
    }
    evidence_prefix.parent.mkdir(parents=True, exist_ok=True)
    evidence_prefix.with_suffix(".json").write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n")
    full, structural = runs["full"], runs["structural_diagnostic"]
    md = f"""# Validación Arelle de una muestra sintética XHTML/iXBRL

Ejecución: {summary['run_at']}. {summary['arelle_version']}.
Taxonomía: {summary['taxonomy_profile']} (paquete oficial aportado por el operador).
SHA-256: `{summary['taxonomy_sha256']}`.

- Validación completa sin red: salida **{full['exit_code']}**, **{full['error_count']} errores**, **{full['warning_count']} avisos**.
- Diagnóstico sin fórmulas: salida **{structural['exit_code']}**, **{structural['error_count']} errores**, **{structural['warning_count']} avisos**.
- La muestra procede del generador PHP del producto, con datos y LEI sintéticos, periodo no natural y valores numéricos.
- Los errores completos se conservan como resultado negativo; no se inventan divulgaciones para satisfacer las reglas de la taxonomía.
- El diagnóstico sin fórmulas no habilita la descarga: el producto exige la validación completa y bloquea ante errores.
- No acredita conformidad ESEF/ESAP, cobertura completa de las NEIS ni validación de datos reales.

Primeros códigos de error: {', '.join('`'+error['code']+'`' for error in full['first_errors']) or 'ninguno'}.
El JSON adyacente registra mensajes, huellas del generador, paquete, registros y dependencias.
Taxonomía, dependencias, entorno Python, muestra, registros y esta evidencia permanecen fuera del repositorio.
"""
    evidence_prefix.with_suffix(".md").write_text(md)
    print(json.dumps({"scratch": str(scratch), "evidence_prefix": str(evidence_prefix),
                      "full": full, "structural_diagnostic": structural}, indent=2))
    return full["exit_code"] or (1 if full["error_count"] or not full["completed"] else 0)


if __name__ == "__main__":
    sys.exit(main())

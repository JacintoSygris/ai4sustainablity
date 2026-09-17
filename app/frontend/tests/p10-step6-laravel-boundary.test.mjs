import assert from "node:assert/strict"
import { existsSync, readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"
import {
  actionLabel,
  actionTarget,
  endpointHref,
  formatPercent,
  humanizeKey,
  limitationMessage,
  reportDownloadRows,
  sectionNumber,
  statusLabel,
  statusTone,
  uniqueLimitations,
} from "../lib/report-draft-state.mjs"

const root = dirname(dirname(fileURLToPath(import.meta.url)))

function read(relativePath) {
  return readFileSync(join(root, relativePath), "utf8")
}

test("wizard Step 6 is a Laravel P10 report page, not a local Better Auth/Turso report", () => {
  const relativePath = "app/(dashboard)/wizard/step-6/page.tsx"

  assert.equal(existsSync(join(root, relativePath)), true, `${relativePath} must exist`)

  const source = read(relativePath)

  assert.doesNotMatch(source, /@\/lib\/auth/, "Step 6 must not read Better Auth directly")
  assert.doesNotMatch(source, /@\/lib\/queries/, "Step 6 must not read reports from Turso")
  assert.doesNotMatch(source, /getUserReport|report\./, "Step 6 must not depend on local report flags")
  assert.match(source, /WizardSidebar/, "Step 6 must keep the imported wizard shell")
  assert.match(source, /ReportDraftPanel/, "Step 6 must delegate P10 rendering to the report component")
})

test("report draft component reads Laravel report readiness and draft APIs", () => {
  const relativePath = "components/wizard/report-draft-panel.tsx"

  assert.equal(existsSync(join(root, relativePath)), true, `${relativePath} must exist`)

  const source = read(relativePath)

  assert.match(source, /getLaravelReportReadiness/, "P10 component must load readiness from Laravel")
  assert.match(source, /getLaravelReportDraft/, "P10 component must load report draft data from Laravel")
  assert.match(source, /reportDownloadRows/, "P10 component must use tested download row helper")
  assert.match(source, /uniqueLimitations/, "P10 component must deduplicate backend limitations before rendering")
  assert.match(source, /sectionLabel/, "P10 component must use tested section labels")
  assert.match(source, /downloadLabel/, "P10 component must use tested download labels")
  assert.match(source, /limitationMessage/, "P10 component must translate backend limitation copy")
  assert.match(source, /isScopingOnly/, "P10 component must use tested scoping-only detection")
  assert.match(source, /visibleNextActions/, "P10 component must filter report self-links")
  assert.match(source, /allSectionsReady/, "P10 component must know when only the report step remains")
  assert.doesNotMatch(source, /finalGenerationPending/, "P10 component must not depend on the retired generation-pending guard")
  assert.doesNotMatch(
    read("lib/report-draft-state.mjs"),
    /final_report_generation_pending/,
    "P10 helper must not preserve the retired generation-pending limitation",
  )
  assert.match(source, /laravelApiUrl/, "P10 component must expose Laravel download endpoints")
  assert.match(source, /laravelApiUrl\("\/report\/package"\)/, "P10 component must expose the printable HTML package")
  assert.match(source, /reportPackageReady/, "P10 component must not show the package shortcut before it is ready")
  assert.match(
    source,
    /downloads\?\.report_package_html\?\.status === "ready"/,
    "P10 package shortcut must follow the backend download readiness state",
  )
  assert.doesNotMatch(
    source,
    /reportPackageReady[\s\S]*draft\?\.exports\?\.report_package_html/,
    "P10 package shortcut must not trust draft-side export signals over readiness",
  )
  assert.doesNotMatch(
    source,
    /reportPackageReady[\s\S]*draft\?\.generation_status/,
    "P10 package shortcut must not trust draft generation status over readiness",
  )
  assert.doesNotMatch(
    source,
    /readiness\.status !== "generation_pending"/,
    "P10 component must not silently hide Laravel generation-pending next_actions",
  )
})

test("Step 6 report panel renders the payoff layout with plain-language copy", () => {
  const panel = read("components/wizard/report-draft-panel.tsx")

  assert.match(panel, /Esto muestra qué asuntos materiales, indicadores\/datos y evidencias están registrados, qué falta y qué descargas pueden generarse\./)
  assert.match(panel, /Lo que está registrado/)
  assert.match(panel, /Una lista de/)
  assert.match(panel, /Modo alcance/)
  assert.match(panel, /¿Y ahora qué\?/)
  assert.match(panel, /Comparte las descargas como material de trabajo con las personas que revisen la información\./)
  assert.match(panel, /Paquete HTML/)
  assert.match(panel, /Cargando el resumen\.\.\./)
  assert.match(panel, /Ir a la encuesta inicial \(paso 1\)/)
  assert.doesNotMatch(panel, /Cargando paquete P10|Cobertura P9|Bloques P9|Volver a P5|volver a P10|paquete P10/)
  assert.doesNotMatch(panel, /humanizeKey\(key\)|humanizeKey\(limitation\.key\)/)
  // F3 orphan count render in summary row
  assert.match(panel, /respuestas conservadas fuera de alcance|orphaned_response_count/, "P10 must surface orphaned_response_count in datapoints summary when >0")
})

test("P10 helpers map report status, endpoints, actions, and section counts", () => {
  const apiUrl = (path) => `laravel:${path}`

  assert.equal(humanizeKey("p9_responses_csv"), "Responses Csv")
  assert.equal(statusLabel(null), "-")
  assert.equal(statusLabel("generation_pending"), "Pendiente de generación")
  assert.equal(statusLabel("not_implemented"), "No disponible en esta versión")
  assert.equal(statusLabel("scoping_only"), "Modo alcance")
  assert.equal(statusLabel("custom_status"), "Custom Status")
  assert.match(statusTone("ready"), /emerald/)
  assert.match(statusTone("generation_pending"), /blue/)
  assert.match(statusTone("blocked"), /amber/)
  assert.match(statusTone("unknown"), /muted/)
  assert.equal(formatPercent(0.764), "76%")
  assert.equal(formatPercent(Number.NaN), "-")

  assert.equal(endpointHref("/api/report/draft", apiUrl), "laravel:/report/draft")
  assert.equal(endpointHref("/characterization/summary?format=pdf", apiUrl), "/characterization/summary?format=pdf")
  assert.equal(endpointHref("/report", apiUrl), "laravel:/report")

  assert.equal(actionTarget("/api/characterization"), "/wizard/step-1")
  assert.equal(actionTarget("/api/materiality-proposal"), "/wizard/step-2")
  assert.equal(actionTarget("/api/materiality-confirmation"), "/wizard/step-4")
  assert.equal(actionTarget("/api/esrs-datapoints"), "/wizard/step-5")
  assert.equal(actionTarget("/api/report/draft"), "/wizard/step-6")
  assert.equal(actionTarget("/api/unknown"), "/wizard/step-1")
  assert.equal(actionLabel("/api/report/draft"), "Revisar el resumen (paso 6)")

  assert.equal(sectionNumber({ topic_count: 3 }), "3")
  assert.equal(sectionNumber({ completed_count: 2 }), "2")
  assert.equal(sectionNumber({ status: "ready" }), "-")
})

test("P10 helpers deduplicate limitations and preserve the package scope notice", () => {
  const packageScope = {
    key: "report_package_scope",
    message: "Report package scope.",
  }
  const mappingLimitation = {
    key: "exact_ar16_matter_to_dr_mapping_pending",
    message: "Exact matter mapping is pending.",
  }
  const readiness = { limitations: [packageScope, mappingLimitation] }
  const draft = { limitations: [{ ...packageScope, message: "Duplicate package limitation." }] }

  assert.deepEqual(
    uniqueLimitations(readiness, draft).map((limitation) => limitation.key),
    ["report_package_scope", "exact_ar16_matter_to_dr_mapping_pending"],
  )
})

test("P10 report draft state exposes F3 stale + orphan limitation messages (exact copy)", () => {
  // source presence of keys in LIMITATION_MESSAGES
  const stateSrc = read("lib/report-draft-state.mjs")
  assert.match(stateSrc, /materiality_confirmation_stale/, "report state must map the stale confirmation limitation key")
  assert.match(stateSrc, /orphaned_datapoint_responses/, "report state must map the orphaned datapoint responses limitation key")
  // behavior: limitationMessage falls back to provided message or the map
  const staleLim = { key: "materiality_confirmation_stale", message: "ignored" }
  assert.match(limitationMessage(staleLim), /paso 4/)
  const orphanLim = { key: "orphaned_datapoint_responses" }
  assert.match(limitationMessage(orphanLim), /Se conservan y volverán/)
})

test("P10 helpers merge download rows while preserving readiness status", () => {
  const readiness = {
    downloads: {
      p8_decision_sheet: {
        endpoint: "/api/materiality-confirmation/decision-sheet",
        content_type: "application/json",
        status: "ready",
      },
      p9_responses_csv: {
        endpoint: "/api/esrs-datapoints/responses/export.csv",
        content_type: "text/csv",
        status: "blocked",
      },
    },
  }
  const draft = {
    exports: {
      report_readiness: {
        endpoint: "/api/report",
        content_type: "application/json",
        status: "ready",
      },
      p9_responses_csv: {
        endpoint: "/api/esrs-datapoints/responses/export.csv",
        content_type: "text/csv",
        status: "ready",
      },
    },
  }

  assert.deepEqual(
    reportDownloadRows(readiness, draft).map(([key, download]) => [key, download.status]),
    [
      ["p8_decision_sheet", "ready"],
      ["p9_responses_csv", "blocked"],
      ["report_readiness", "ready"],
    ],
  )
  assert.deepEqual(
    reportDownloadRows(null, draft).map(([key]) => key),
    ["report_readiness", "p9_responses_csv"],
  )
})

test("Laravel API client exposes typed P10 report helpers", () => {
  const source = read("lib/laravel-api.ts")

  assert.match(source, /LaravelReportReadiness/, "client must type report readiness resources")
  assert.match(source, /LaravelReportDraft/, "client must type report draft resources")
  assert.match(source, /getLaravelReportReadiness/, "client must expose report readiness helper")
  assert.match(source, /getLaravelReportDraft/, "client must expose report draft helper")
  assert.match(source, /\/report\/draft/, "client must call Laravel report draft API")
})

test("Step 6 refreshes live Laravel report data instead of reusing stale client state", () => {
  const panel = read("components/wizard/report-draft-panel.tsx")
  const client = read("lib/laravel-api.ts")

  assert.match(panel, /window\.addEventListener\("focus"/, "Step 6 must reload when the user returns to the tab")
  assert.match(
    panel,
    /setReloadCounter\(\(current\) => current \+ 1\)/,
    "Step 6 must reuse the same refresh path for manual and automatic reloads",
  )
  assert.match(
    client,
    /getLaravelReportReadiness[\s\S]*cache: "no-store"/,
    "report readiness requests must bypass browser fetch caches",
  )
  assert.match(
    client,
    /getLaravelReportDraft[\s\S]*cache: "no-store"/,
    "report draft requests must bypass browser fetch caches",
  )
})

test("Step 6 report layout can shrink inside the wizard shell", () => {
  const page = read("app/(dashboard)/wizard/step-6/page.tsx")
  const panel = read("components/wizard/report-draft-panel.tsx")

  assert.match(page, /className="[^"]*min-w-0[^"]*lg:flex-row/, "Step 6 shell must allow its flex children to shrink")
  assert.match(panel, /className="[^"]*min-w-0[^"]*flex-1/, "P10 panel must not force horizontal overflow")
  assert.match(
    panel,
    /xl:grid-cols-\[minmax\(0,1fr\)_360px\]/,
    "P10 detail grid must use a shrinkable main column before the fixed downloads column",
  )
})

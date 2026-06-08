import assert from "node:assert/strict"
import { existsSync, readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"
import {
  actionLabel,
  actionTarget,
  endpointHref,
  finalGenerationPending,
  formatPercent,
  humanizeKey,
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
  assert.match(source, /finalGenerationPending/, "P10 component must use the tested final generation guard")
  assert.match(
    read("lib/report-draft-state.mjs"),
    /final_report_generation_pending/,
    "P10 helper must surface final generation limitation",
  )
  assert.match(source, /laravelApiUrl/, "P10 component must expose Laravel download endpoints")
  assert.doesNotMatch(
    source,
    /readiness\.status !== "generation_pending"/,
    "P10 component must not silently hide Laravel generation-pending next_actions",
  )
})

test("P10 helpers map report status, endpoints, actions, and section counts", () => {
  const apiUrl = (path) => `laravel:${path}`

  assert.equal(humanizeKey("p9_responses_csv"), "P9 Responses Csv")
  assert.equal(statusLabel(null), "-")
  assert.equal(statusLabel("generation_pending"), "Pendiente de generación")
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
  assert.equal(actionLabel("/api/report/draft"), "Borrador P10")

  assert.equal(sectionNumber({ topic_count: 3 }), "3")
  assert.equal(sectionNumber({ completed_count: 2 }), "2")
  assert.equal(sectionNumber({ status: "ready" }), "-")
})

test("P10 helpers deduplicate limitations and preserve the final generation guard", () => {
  const finalLimitation = {
    key: "final_report_generation_pending",
    message: "Final report generation is pending.",
  }
  const mappingLimitation = {
    key: "exact_ar16_matter_to_dr_mapping_pending",
    message: "Exact matter mapping is pending.",
  }
  const readiness = { limitations: [finalLimitation, mappingLimitation] }
  const draft = { limitations: [{ ...finalLimitation, message: "Duplicate final limitation." }] }

  assert.deepEqual(
    uniqueLimitations(readiness, draft).map((limitation) => limitation.key),
    ["final_report_generation_pending", "exact_ar16_matter_to_dr_mapping_pending"],
  )
  assert.equal(finalGenerationPending(readiness, { limitations: [] }), true)
  assert.equal(finalGenerationPending({ limitations: [] }, draft), true)
  assert.equal(finalGenerationPending(readiness, draft), true)
  assert.equal(finalGenerationPending({ limitations: [mappingLimitation] }, { limitations: [] }), false)
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

import assert from "node:assert/strict"
import { existsSync, readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"
import {
  compactDrafts,
  completionPlanItems,
  datapointApplicabilitySummary,
  flattenCorpus,
  p9ExportLinks,
  p9MappingSummary,
  phaseInSummary,
} from "../lib/esrs-datapoints-state.mjs"

const root = dirname(dirname(fileURLToPath(import.meta.url)))

function read(relativePath) {
  return readFileSync(join(root, relativePath), "utf8")
}

test("wizard Step 5 is a Laravel P9 datapoints page, not a local Better Auth/Turso placeholder", () => {
  const source = read("app/(dashboard)/wizard/step-5/page.tsx")

  assert.doesNotMatch(source, /@\/lib\/auth/, "Step 5 must not read Better Auth directly")
  assert.doesNotMatch(source, /@\/lib\/queries/, "Step 5 must not read reports from Turso")
  assert.doesNotMatch(source, /getUserReport|report\./, "Step 5 must not depend on local report flags")
  assert.doesNotMatch(source, /Esta sección está en desarrollo/, "Step 5 must no longer be a placeholder")
  assert.match(source, /WizardSidebar/, "Step 5 must keep the imported wizard shell")
  assert.match(source, /EsrsDatapointsForm/, "Step 5 must delegate P9 editing to the datapoints component")
})

test("ESRS datapoints component reads and writes Laravel P9 APIs", () => {
  const relativePath = "components/wizard/esrs-datapoints-form.tsx"

  assert.equal(existsSync(join(root, relativePath)), true, `${relativePath} must exist`)

  const source = read(relativePath)

  assert.match(source, /getLaravelEsrsDatapoints/, "P9 component must load datapoint corpus from Laravel")
  assert.match(source, /getLaravelEsrsDatapointResponses/, "P9 component must load response state from Laravel")
  assert.match(source, /updateLaravelEsrsDatapointResponses/, "P9 component must save responses to Laravel")
  assert.match(source, /p9MappingSummary/, "P9 component must render Laravel mapping transparency metadata")
  assert.match(source, /datapointApplicabilitySummary/, "P9 component must render datapoint applicability metadata")
  assert.match(source, /p9ExportLinks/, "P9 component must render the P9 export links from helper state")
})

test("P9 helpers compact response state with full-replacement clear semantics", () => {
  assert.deepEqual(compactDrafts({}), [])
  assert.deepEqual(
    compactDrafts({
      "BP-1_01": { status: "draft", value: "", evidence_reference: "", note: "" },
      "BP-1_02": { status: "draft", value: " consolidated ", evidence_reference: " pack ", note: "" },
      "BP-1_03": { status: "completed", value: "", evidence_reference: "", note: " done " },
      "BP-1_04": { status: "not_applicable", value: "", evidence_reference: "", note: "" },
    }),
    [
      { datapoint_id: "BP-1_02", evidence_reference: "pack", note: undefined, status: "draft", value: "consolidated" },
      { datapoint_id: "BP-1_03", evidence_reference: undefined, note: "done", status: "completed", value: undefined },
      { datapoint_id: "BP-1_04", evidence_reference: undefined, note: undefined, status: "not_applicable", value: undefined },
    ],
  )
})

test("P9 helpers expose corpus transparency, applicability, and export decisions", () => {
  const corpus = {
    generation: {
      coverage_status: "standard_level_partial",
      limitations: ["No approved AR16 matter to Disclosure Requirement mapping is loaded yet."],
      mapping_granularity: "standard_level",
      matter_to_dr_mapping_status: "pending",
    },
    matter_mapping: {
      current_filter: "activated_esrs_standard",
      status: "pending",
    },
    phase_in_assessment: {
      counts: {
        all_undertakings_phase_in_datapoint_count: 1,
        applicable_phase_in_datapoint_count: 3,
        less_than_750_relief_datapoint_count: 2,
      },
      employee_count: { estimate: 150, less_than_750: true, source: "employee_count_range" },
      note: "Advisory phase-in metadata.",
      status: "eligible_less_than_750",
    },
    completion_plan: {
      phases: [{ key: "always_required", title: "Complete ESRS 2 first", status: "ready", datapoint_count: 146 }],
    },
    blocks: {
      topical: {
        key: "topical",
        title: "Topical datapoints",
        datapoints: [
          {
            id: "E2.IRO-1_01",
            name: "Pollution IRO screening",
            applicability: {
              reason: "This topical datapoint belongs to an ESRS standard activated by final materiality.",
              mapping_basis: "activated_esrs_standard",
              reason_code: "material_esrs_standard",
              limitations: ["Included at activated ESRS standard level."],
            },
            phase_in: { less_than_750: "May phase in", all_undertakings: "" },
          },
        ],
      },
    },
  }

  assert.deepEqual(
    p9ExportLinks().map((link) => link.path),
    ["/esrs-datapoints/export.csv", "/esrs-datapoints/responses/export.csv"],
  )
  assert.deepEqual(flattenCorpus(corpus).map((row) => [row.blockTitle, row.datapoint.id]), [
    ["Topical datapoints", "E2.IRO-1_01"],
  ])
  assert.deepEqual(p9MappingSummary(corpus), {
    coverageStatus: "standard_level_partial",
    currentFilter: "activated_esrs_standard",
    limitations: ["No approved AR16 matter to Disclosure Requirement mapping is loaded yet."],
    mappingGranularity: "standard_level",
    mappingStatus: "pending",
  })
  assert.equal(phaseInSummary(corpus).applicablePhaseInCount, 3)
  assert.equal(completionPlanItems(corpus)[0].title, "Complete ESRS 2 first")
  assert.deepEqual(datapointApplicabilitySummary(corpus.blocks.topical.datapoints[0]), {
    limitations: ["Included at activated ESRS standard level."],
    mappingBasis: "activated_esrs_standard",
    phaseInAllUndertakings: "",
    phaseInLessThan750: "May phase in",
    reason: "This topical datapoint belongs to an ESRS standard activated by final materiality.",
    reasonCode: "material_esrs_standard",
  })
})

test("Laravel API client exposes typed P9 datapoint helpers", () => {
  const source = read("lib/laravel-api.ts")

  assert.match(source, /LaravelEsrsDatapointCorpus/, "client must type P9 corpus resources")
  assert.match(source, /LaravelEsrsDatapointResponses/, "client must type P9 response resources")
  assert.match(source, /getLaravelEsrsDatapoints/, "client must expose P9 corpus read helper")
  assert.match(source, /getLaravelEsrsDatapointResponses/, "client must expose P9 response read helper")
  assert.match(source, /updateLaravelEsrsDatapointResponses/, "client must expose P9 response update helper")
  assert.match(source, /\/esrs-datapoints/, "client must call Laravel datapoint API")
})

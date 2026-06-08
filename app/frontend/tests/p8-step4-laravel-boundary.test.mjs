import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"
import {
  CHANGE_REASON_OPTIONS,
  buildMaterialityConfirmationPayload,
  changedTopicIds,
  cleanNotes,
  cleanReasons,
  removesE1FromTopics,
} from "../lib/materiality-confirmation-state.mjs"

const root = dirname(dirname(fileURLToPath(import.meta.url)))

function read(relativePath) {
  return readFileSync(join(root, relativePath), "utf8")
}

test("wizard Step 4 is a Laravel P8 confirmation page, not a local Better Auth/Turso page", () => {
  const source = read("app/(dashboard)/wizard/step-4/page.tsx")

  assert.doesNotMatch(source, /@\/lib\/auth/, "Step 4 must not read Better Auth directly")
  assert.doesNotMatch(source, /@\/lib\/queries/, "Step 4 must not read reports from Turso")
  assert.doesNotMatch(source, /getUserReport|userReport|finalTopics|topicJustifications/, "Step 4 must not read local final-topic state")
  assert.match(source, /WizardSidebar/, "Step 4 must keep the imported wizard shell")
  assert.match(source, /FinalTopicsSelection/, "Step 4 must delegate P8 confirmation to the component")
})

test("final topics selection confirms Laravel P8 materiality and reads Laravel ESRS catalog", () => {
  const source = read("components/wizard/final-topics-selection.tsx")

  assert.match(source, /getLaravelMaterialityConfirmation/, "P8 component must load confirmation state from Laravel")
  assert.match(source, /updateLaravelMaterialityConfirmation/, "P8 component must save final confirmation to Laravel")
  assert.match(source, /getLaravelEsrsTopics/, "P8 component must read the Laravel ESRS catalog")
  assert.match(source, /confirmed_topic_ids/, "P8 component must persist canonical topic IDs")
  assert.match(source, /CHANGE_REASON_OPTIONS/, "P8 component must render controlled change reason options")
  assert.match(source, /change_reasons/, "P8 component must submit controlled change reasons")
  assert.doesNotMatch(source, /@\/lib\/esg-topics-data/, "P8 component must not use imported local ESG topic taxonomy")
  assert.doesNotMatch(source, /fetch\(["']\/api\/wizard\/step-4/, "P8 component must not post to local Next Step 4")
})

test("materiality confirmation helpers clean P8 delta reasons, notes, and E1 guard state", () => {
  const reasonKeys = CHANGE_REASON_OPTIONS.map((reason) => reason.key)
  const topics = [
    { id: 1, esrs_code: "E1" },
    { id: 2, esrs_code: "E2" },
    { id: 10, esrs_code: "S1" },
  ]

  assert.deepEqual(reasonKeys, ["new_data", "stakeholders", "scope_change", "threshold", "sector_requirement", "other"])
  assert.deepEqual(changedTopicIds([1, 2], new Set([2, 10])), [1, 10])
  assert.deepEqual(cleanReasons({ 1: ["threshold", "invalid", "threshold"], 2: ["other"], 10: ["stakeholders"] }, [1, 10]), {
    1: ["threshold"],
    10: ["stakeholders"],
  })
  assert.deepEqual(cleanNotes({ 1: " removed ", 2: "unchanged", 10: " added " }, [1, 10]), {
    1: "removed",
    10: "added",
  })
  assert.equal(removesE1FromTopics({ topics, p6TopicIds: [1, 2], selectedTopicIds: new Set([2, 10]) }), true)
  assert.equal(removesE1FromTopics({ topics, p6TopicIds: [1, 2], selectedTopicIds: new Set([1, 10]) }), false)

  assert.deepEqual(
    buildMaterialityConfirmationPayload({
      selectedTopicIds: new Set([2, 10]),
      p6TopicIds: [1, 2],
      changeReasons: { 1: ["threshold"], 2: ["other"], 10: ["stakeholders", "new_data"] },
      changeNotes: { 1: " below threshold ", 2: "stale", 10: " stakeholder evidence " },
      e1Explanation: " E1 below ADM threshold ",
    }),
    {
      confirmed_topic_ids: [2, 10],
      change_reasons: {
        1: ["threshold"],
        10: ["stakeholders", "new_data"],
      },
      change_reason_notes: {
        1: "below threshold",
        10: "stakeholder evidence",
      },
      e1_not_material_explanation: "E1 below ADM threshold",
    },
  )
})

test("Laravel API client exposes typed P8 materiality confirmation helpers", () => {
  const source = read("lib/laravel-api.ts")

  assert.match(source, /LaravelMaterialityConfirmation/, "client must type P8 confirmation resources")
  assert.match(source, /getLaravelMaterialityConfirmation/, "client must expose P8 read helper")
  assert.match(source, /updateLaravelMaterialityConfirmation/, "client must expose P8 update helper")
  assert.match(source, /getLaravelEsrsTopics/, "client must expose ESRS catalog helper")
  assert.match(source, /\/materiality-confirmation/, "client must call Laravel materiality confirmation API")
  assert.match(source, /\/esrs-topics/, "client must call Laravel ESRS topics API")
})

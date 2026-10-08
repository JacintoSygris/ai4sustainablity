import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"
import {
  CHANGE_REASON_OPTIONS,
  applyDirectTopicDecision,
  buildMaterialityConfirmationPayload,
  changedTopicIds,
  cleanNotes,
  cleanReasons,
  guidedReviewTopicIds,
  selectedTopicIdsForGuidedAnswer,
  isStaleConfirmation,
  removesE1FromTopics,
  createPreviewToken,
  previewTokenIsCurrent,
  topicSelectionKey,
} from "../lib/materiality-confirmation-state.mjs"
import { buildGuidedAnswerForUserEdit, validateGuidedAnswer } from "../lib/materiality-guided-state.mjs"
import {
  buildMaterialityConfirmationDraft,
  quarantineMaterialityConfirmationDraft,
  rebaseMaterialityConfirmationDraft,
  restoreMaterialityConfirmationDraft,
} from "../lib/materiality-confirmation-draft.mjs"

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

test("final topics selection confirms Laravel P8 materiality and reads Laravel ESRS catalog (two-mode A3)", () => {
  const source = read("components/wizard/final-topics-selection.tsx")

  assert.match(source, /getLaravelMaterialityConfirmation/, "P8 component must load confirmation state from Laravel")
  assert.match(source, /updateLaravelMaterialityConfirmation/, "P8 component must save final confirmation to Laravel")
  assert.match(source, /previewLaravelMaterialityConfirmation/, "P8 must call the preview endpoint for live datapoint estimates")
  assert.match(source, /getLaravelEsrsTopics/, "P8 component must read the Laravel ESRS catalog")
  assert.match(source, /confirmed_topic_ids/, "P8 component must persist canonical topic IDs")
  assert.match(source, /CHANGE_REASON_OPTIONS/, "P8 component must render controlled change reason options")
  assert.match(source, /change_reasons/, "P8 component must submit controlled change reasons")
  assert.match(source, /dimensions|guided_answers/, "P8 must round-trip dimensions and guided_answers")
  assert.match(source, /TopicSignalAssistant/, "P8 must use the 4-signal assistant component")
  assert.match(source, /Ya tengo mis conclusiones|Ayúdame a decidir tema por tema/, "P8 must offer the two modes with exact copy")
  assert.match(source, /En observación|revisar el próximo ciclo/, "P8 shared review must surface en_observacion group with badge")
  assert.match(source, /2000|explicación detallada si el cambio climático/, "P8 E1 speedbump must use 2000 limit and exact warning text")
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

  // extended payload with dimensions + guided_answers (cleaned) + e1 2000 note (no truncation in helper)
  const guidedValid = {
    impacto: "alto",
    financiero: "bajo",
    confianza: "baja",
    exposicion: "normal",
    suggested_result: "material",
    final_result: "material",
    revisar: true,
  }
  assert.equal(validateGuidedAnswer(guidedValid), true)
  const longE1 = "x".repeat(1500)
  const extended = buildMaterialityConfirmationPayload({
    selectedTopicIds: new Set([2, 10]),
    p6TopicIds: [1, 2],
    e1Explanation: longE1,
    dimensions: { "2": "impact", "10": "both" },
    guidedAnswers: { "2": guidedValid, "999": { impacto: "bad" } }, // 999 invalid dropped by clean
  })
  assert.equal(extended.e1_not_material_explanation, longE1)
  assert.deepEqual(extended.dimensions, { "2": "impact", "10": "both" })
  assert.ok(extended.guided_answers && "2" in extended.guided_answers)
  assert.ok(!("999" in (extended.guided_answers || {})))
})

test("guided final verdicts keep confirmed topics semantically consistent", () => {
  assert.deepEqual(
    Array.from(selectedTopicIdsForGuidedAnswer(new Set([2, 10]), 2, { final_result: "no_material" })),
    [10],
  )
  assert.deepEqual(
    Array.from(selectedTopicIdsForGuidedAnswer(new Set([2]), 10, { final_result: "material" })),
    [2, 10],
  )
  assert.deepEqual(
    Array.from(selectedTopicIdsForGuidedAnswer(new Set([2]), 10, { suggested_result: "material" })),
    [2],
  )
})

test("direct topic edits clear superseded guided verdicts and payloads remain consistent", () => {
  const guidedAnswers = {
    "2": {
      impacto: "alto",
      financiero: "bajo",
      confianza: "alta",
      exposicion: "normal",
      suggested_result: "material",
      final_result: "material",
      revisar: false,
    },
    "10": {
      impacto: "bajo",
      financiero: "bajo",
      confianza: "alta",
      exposicion: "normal",
      suggested_result: "no_material",
      final_result: "no_material",
      revisar: false,
    },
  }

  const removed = applyDirectTopicDecision(new Set([2]), guidedAnswers, 2, false)
  assert.deepEqual(Array.from(removed.selectedTopicIds), [])
  assert.equal(removed.guidedAnswers["2"], undefined)

  const added = applyDirectTopicDecision(new Set([2]), guidedAnswers, 10, true)
  assert.deepEqual(Array.from(added.selectedTopicIds), [2, 10])
  assert.equal(added.guidedAnswers["10"], undefined)

  const payload = buildMaterialityConfirmationPayload({
    selectedTopicIds: new Set([2, 10]),
    p6TopicIds: [2],
    guidedAnswers,
  })
  assert.deepEqual(Object.keys(payload.guided_answers ?? {}), ["2"])
})

test("guided assistant initialization is inert until the user edits a signal", () => {
  const signals = {
    impacto: "bajo",
    financiero: "bajo",
    confianza: "media",
    exposicion: "normal",
  }

  assert.equal(buildGuidedAnswerForUserEdit(signals, "", false), null)
  assert.deepEqual(buildGuidedAnswerForUserEdit(signals, "", true), {
    ...signals,
    suggested_result: "no_material",
    final_result: "no_material",
    revisar: false,
  })

  const source = read("components/wizard/topic-signal-assistant.tsx")
  assert.doesNotMatch(source, /useEffect/, "mounting the assistant must not emit a guided decision")
  assert.match(source, /buildGuidedAnswerForUserEdit/, "only explicit editor events may build and emit a decision")
})

test("Laravel API client exposes typed P8 materiality confirmation helpers + preview + guided fields", () => {
  const source = read("lib/laravel-api.ts")

  assert.match(source, /LaravelMaterialityConfirmation/, "client must type P8 confirmation resources")
  assert.match(source, /getLaravelMaterialityConfirmation/, "client must expose P8 read helper")
  assert.match(source, /updateLaravelMaterialityConfirmation/, "client must expose P8 update helper")
  assert.match(source, /previewLaravelMaterialityConfirmation/, "client must expose preview helper for candidate sets")
  assert.match(source, /guided_answers|dimensions/, "client must type guided_answers and dimensions in payload/response")
  assert.match(source, /\/materiality-confirmation\/preview/, "client must call the preview route")
  assert.match(source, /\/materiality-confirmation/, "client must call Laravel materiality confirmation API")
  assert.match(source, /\/esrs-topics/, "client must call Laravel ESRS topics API")
})

test("materiality confirmation state detects stale per F3 (is_stale tolerant)", () => {
  assert.equal(isStaleConfirmation(null), false)
  assert.equal(isStaleConfirmation({ is_confirmed: true }), false)
  assert.equal(isStaleConfirmation({ is_confirmed: true, is_stale: true }), true)
  assert.equal(isStaleConfirmation({ is_confirmed: true, is_stale: false }), false)
  // legacy without field treated non-stale
  assert.equal(isStaleConfirmation({ is_confirmed: true, p6_snapshot: null }), false)
})

test("P8 final topics selection surfaces stale banner (exact copy) when isStaleConfirmation", () => {
  const source = read("components/wizard/final-topics-selection.tsx")
  assert.match(source, /isStaleConfirmation|Tu confirmación es anterior a tus últimos cambios en la propuesta del paso 2/, "P8 must render stale confirmation amber banner with exact plan copy when is_stale")
})

test("P8 draft state is restored only against the exact server revision and P6 snapshot", () => {
  const draft = buildMaterialityConfirmationDraft({
    baseRevision: 3,
    p6TopicIds: [2, 1],
    selectedTopicIds: new Set([10, 2]),
    changeReasons: { "1": ["threshold"] },
    changeNotes: { "1": "Needs review" },
    e1Explanation: "Draft explanation",
    guidedAnswers: { "2": { final_result: "material" } },
    mode: "guided",
  })
  const raw = JSON.stringify(draft)

  assert.deepEqual(
    restoreMaterialityConfirmationDraft(raw, { baseRevision: 3, p6TopicIds: [1, 2] }),
    draft,
  )
  assert.equal(restoreMaterialityConfirmationDraft(raw, { baseRevision: 4, p6TopicIds: [1, 2] }), null)
  assert.equal(restoreMaterialityConfirmationDraft(raw, { baseRevision: 3, p6TopicIds: [1, 3] }), null)
  assert.equal(restoreMaterialityConfirmationDraft("not-json", { baseRevision: 3, p6TopicIds: [1, 2] }), null)
})

test("stale P8 draft is quarantined and only rebased after explicit recovery", () => {
  const draft = buildMaterialityConfirmationDraft({
    baseRevision: 3,
    p6TopicIds: [1, 2],
    selectedTopicIds: [2, 10],
    changeReasons: { "1": ["threshold"] },
    changeNotes: { "1": "Review later" },
    guidedAnswers: {},
    mode: "direct",
  })
  const raw = JSON.stringify(draft)

  assert.equal(restoreMaterialityConfirmationDraft(raw, { baseRevision: 4, p6TopicIds: [1, 2] }), null)
  assert.deepEqual(
    quarantineMaterialityConfirmationDraft(raw, { baseRevision: 4, p6TopicIds: [1, 2] }),
    draft,
  )

  const rebased = rebaseMaterialityConfirmationDraft(raw, { baseRevision: 4, p6TopicIds: [1, 2] })
  assert.equal(rebased.base_revision, 4)
  assert.deepEqual(rebased.selected_topic_ids, [2, 10])
  assert.deepEqual(rebased.change_notes, { "1": "Review later" })
  assert.equal(quarantineMaterialityConfirmationDraft("not-json", { baseRevision: 4, p6TopicIds: [1, 2] }), null)

  const source = read("components/wizard/final-topics-selection.tsx")
  assert.match(source, /conflictDraftKey/)
  assert.match(source, /Recuperar borrador/)
  assert.match(source, /Descartar borrador/)
})

test("P8 ignores preview responses that complete after a newer candidate request", () => {
  const source = read("components/wizard/final-topics-selection.tsx")

  assert.match(source, /previewRequestId/)
  assert.match(source, /requestId !== previewRequestId\.current/)
})

test("preview identity distinguishes empty and equal-size selections and invalidates pre-reload tokens", () => {
  assert.equal(topicSelectionKey([]), "empty")
  assert.equal(topicSelectionKey([2, 1, 2]), "1,2")
  assert.notEqual(topicSelectionKey([1, 2]), topicSelectionKey([1, 3]))

  const token = createPreviewToken([1, 2], 4)
  assert.equal(previewTokenIsCurrent(token, [2, 1], 4), true)
  assert.equal(previewTokenIsCurrent(token, [1, 3], 4), false)
  assert.equal(previewTokenIsCurrent(token, [1, 2], 5), false)

  const source = read("components/wizard/final-topics-selection.tsx")
  assert.match(source, /previewGeneration/)
  assert.match(source, /topicSelectionKey\(selectedTopics\)/)
  assert.doesNotMatch(source, /candidateIds\.length === 0/, "empty selections must receive their own server preview")
})

test("P8 preserves edits made while confirmation is in flight and quarantines the live in-memory draft", () => {
  const source = read("components/wizard/final-topics-selection.tsx")

  assert.match(source, /draftMutationVersion/)
  assert.match(source, /mutationVersionAtDispatch/)
  assert.match(source, /draftMutationVersion\.current !== mutationVersionAtDispatch/)
  assert.match(source, /latestDraftRaw\.current/)
  assert.doesNotMatch(
    source,
    /const draftRaw = window\.localStorage\.getItem\(draftKey\)/,
    "a 409 must quarantine the latest in-memory draft rather than a lagging storage effect",
  )
  assert.match(source, /<fieldset disabled=\{saving\}/)
})

test("P8 hydration stays clean and local guided state is authoritative after direct edits", () => {
  const source = read("components/wizard/final-topics-selection.tsx")

  assert.match(source, /draftDirty\.current/)
  assert.match(source, /if \(!draftDirty\.current\) return/)
  assert.doesNotMatch(source, /confirmation\?\.confirmation\?\.guided_answers\?\.\[String\(id\)\]/)
  assert.doesNotMatch(source, /\.\.\. \(confirmation\?\.confirmation\?\.guided_answers \|\| \{\}\)/)
})

test("P8 mode cards and topic controls are keyboard operable and named", () => {
  const source = read("components/wizard/final-topics-selection.tsx")

  assert.match(source, /aria-pressed=\{mode === "direct"\}/)
  assert.match(source, /aria-pressed=\{mode === "guided"\}/)
  assert.match(source, /aria-label=\{`\$\{selected \? tr\("Retirar"\) : tr\("Añadir"\)\}/)
  assert.match(source, /aria-label=\{formatUi\(locale, "Motivo: \{0\}", \[tr\(r\.label\)\]\)\}/)
})

test("P8 draft identity binds a conflict copy to its tab and save request", () => {
  const draft = buildMaterialityConfirmationDraft({
    baseRevision: 7,
    p6TopicIds: [1, 2],
    selectedTopicIds: [2],
    tabId: "tab-a",
    requestId: 11,
  })

  assert.equal(draft.tab_id, "tab-a")
  assert.equal(draft.request_id, 11)
  assert.deepEqual(
    restoreMaterialityConfirmationDraft(JSON.stringify(draft), { baseRevision: 7, p6TopicIds: [1, 2] }),
    draft,
  )
})

test("guided review keeps persisted non-material decisions visible after selection reconciliation", () => {
  const groups = guidedReviewTopicIds({
    selectedTopicIds: new Set([7, 8]),
    guidedAnswers: {
      7: { final_result: "material", suggested_result: "material" },
      8: { final_result: "material", suggested_result: "en_observacion" },
      42: { final_result: "no_material", suggested_result: "no_material" },
    },
  })

  assert.deepEqual(groups, {
    materialIds: [7],
    observationIds: [8],
    noMaterialIds: [42],
  })
})

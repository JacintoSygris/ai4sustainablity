import assert from "node:assert/strict"
import { readFileSync, writeFileSync } from "node:fs"
import { createRequire } from "node:module"
import * as recoveryState from "../lib/materiality-confirmation-state.mjs"
import * as recoveryDraft from "../lib/materiality-confirmation-draft.mjs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"
import {
  attachLearningTopicDraftState,
  buildMaterialityConfirmationPayload,
  guidedReviewTopicIds,
  guidedUniverseIsComplete,
  mergeMaterialityDimensions,
  mergeReviewedTopicIds,
  resolveLearningTopicHydration,
  restoreLearningTopicDraftState,
} from "../lib/materiality-confirmation-state.mjs"
import {
  buildMaterialityConfirmationDraft,
  restoreMaterialityConfirmationDraft,
} from "../lib/materiality-confirmation-draft.mjs"

const root = dirname(dirname(fileURLToPath(import.meta.url)))

// Run the actual current recovery callback, not a test-side reconstruction.
// State setters/storage are traced; business imports are the real public modules.
function runCurrentRecovery({ server, local = {}, raw }) {
  const ts = createRequire(import.meta.url)("typescript")
  const source = readFileSync(join(root, "components/wizard/final-topics-selection.tsx"), "utf8")
  const start = source.indexOf("  const recoverConflictedDraft =")
  const end = source.indexOf("  const discardConflictedDraft =", start)
  assert.ok(start >= 0 && end > start)
  const callback = ts.transpileModule(source.slice(start, end), {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS },
  }).outputText
  const observed = {}, storage = new Map()
  const bindings = {
    ...recoveryState, ...recoveryDraft,
    confirmation: server, conflictedDraftRaw: raw,
    reviewedTopics: new Set(local.reviewed_topic_ids ?? server.confirmation.reviewed_topic_ids),
    selectedTopics: new Set(local.selected_topic_ids ?? server.confirmed_topic_ids),
    changeReasons: local.change_reasons ?? server.confirmation.change_reasons ?? {},
    changeNotes: local.change_notes ?? server.confirmation.change_reason_notes ?? {},
    guidedDrafts: local.guided_answers ?? server.confirmation.guided_answers ?? {},
    dimensions: local.dimensions ?? server.confirmation.dimensions ?? {},
    tabId: { current: "synthetic-tab" }, latestDraftRaw: { current: null }, draftReadyFor: { current: null },
    markDraftChanged() { observed.dirty = true },
    getDraftKey: () => "draft", conflictDraftKey: () => "conflict",
    recoveryStorage: { setItem(k, v) { storage.set(k, v) }, removeItem(k) { storage.delete(k) } },
  }
  for (const name of ["SelectedTopics", "ChangeReasons", "ChangeNotes", "E1Explanation", "GuidedDrafts", "Dimensions", "ReviewedTopics", "ReviewedUniverse", "Mode", "HasUserChosenMode", "ConflictedDraftRaw"]) {
    bindings[`set${name}`] = value => { observed[name] = value }
  }
  new Function(...Object.keys(bindings), `${callback}\nrecoverConflictedDraft()`)(...Object.values(bindings))
  return { observed, persisted: JSON.parse(storage.get("draft")), latest: bindings.latestDraftRaw.current }
}

function recoveryFixture() {
  const rejected = guidedAnswer({ impacto: "bajo", financiero: "bajo", confianza: "alta", suggested_result: "no_material", final_result: "no_material", note: "synthetic rejected evidence" })
  const server = {
    characterization_id: 7, p6_topic_ids: [4], confirmed_topic_ids: [4],
    confirmation: { revision: 2, reviewed_topic_ids: [4, 28], change_reasons: { 28: ["stakeholders"] }, change_reason_notes: { 28: "server rejection note" }, dimensions: { 28: "impact" }, guided_answers: { 28: rejected } },
  }
  const raw = JSON.stringify(attachLearningTopicDraftState(buildMaterialityConfirmationDraft({ baseRevision: 1, p6TopicIds: [4], selectedTopicIds: [4] }), { reviewedTopicIds: [4], reviewedUniverse: true }))
  const local = { reviewed_topic_ids: [4, 28, 31], change_reasons: { ...server.confirmation.change_reasons, 31: ["scope_change"] }, change_notes: { ...server.confirmation.change_reason_notes, 31: "unsaved rejection note" }, dimensions: { ...server.confirmation.dimensions, 31: "financial" }, guided_answers: { ...server.confirmation.guided_answers, 31: { ...rejected, note: "unsaved guided note" } } }
  return { server, raw, local }
}

function payloadFromRecovered(draft) {
  return { ...buildMaterialityConfirmationPayload({ selectedTopicIds: draft.selected_topic_ids, p6TopicIds: draft.p6_topic_ids, reviewedTopicIds: draft.reviewed_topic_ids, reviewedUniverse: draft.reviewed_universe, changeReasons: draft.change_reasons, changeNotes: draft.change_notes, dimensions: draft.dimensions, guidedAnswers: draft.guided_answers, mode: draft.mode }), expected_revision: draft.base_revision }
}

function runCurrentAutosave(server, observed) {
  const ts = createRequire(import.meta.url)("typescript")
  const source = readFileSync(join(root, "components/wizard/final-topics-selection.tsx"), "utf8")
  const start = source.indexOf("  // Persist the whole unsaved P8 decision")
  const end = source.indexOf("  const p6TopicIds = useMemo", start)
  assert.ok(start >= 0 && end > start)
  let persisted
  const bindings = { ...recoveryState, ...recoveryDraft, confirmation: server,
    useEffect: fn => fn(), draftReadyFor: { current: server.characterization_id }, draftDirty: { current: true },
    selectedTopics: observed.SelectedTopics, reviewedTopics: observed.ReviewedTopics, reviewedUniverse: observed.ReviewedUniverse,
    changeReasons: observed.ChangeReasons, changeNotes: observed.ChangeNotes, e1Explanation: observed.E1Explanation,
    guidedDrafts: observed.GuidedDrafts, dimensions: observed.Dimensions, mode: observed.Mode,
    tabId: { current: "synthetic-tab" }, activeSaveRequestId: { current: 0 }, latestDraftRaw: { current: null },
    getDraftKey: () => "draft", recoveryStorage: { setItem(k, v) { persisted = JSON.parse(v) } },
  }
  const output = ts.transpileModule(source.slice(start, end), { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
  new Function(...Object.keys(bindings), output)(...Object.values(bindings))
  return persisted
}

test("F-T03-R1 actual recovery preserves newer server rejection evidence through draft and next payload", () => {
  const { server, raw } = recoveryFixture()
  const result = runCurrentRecovery({ server, raw })
  assert.deepEqual(result.persisted.reviewed_topic_ids, [4, 28])
  assert.deepEqual(result.persisted.change_reasons, server.confirmation.change_reasons)
  assert.deepEqual(result.persisted.change_notes, server.confirmation.change_reason_notes)
  assert.deepEqual(result.persisted.dimensions, server.confirmation.dimensions)
  assert.deepEqual(result.persisted.guided_answers, server.confirmation.guided_answers)
  assert.deepEqual(result.observed.ChangeReasons, result.persisted.change_reasons)
  assert.equal(result.latest, JSON.stringify(result.persisted))
  assert.equal(result.persisted.base_revision, 2)
  assert.equal(result.persisted.reviewed_universe, false)
  assert.equal(payloadFromRecovered(result.persisted).guided_answers[28].note, "synthetic rejected evidence")
})

test("F-T03-R2 actual recovery retains unsaved local rejection and repeated old drafts cannot narrow universe", () => {
  const { server, raw, local } = recoveryFixture()
  const first = runCurrentRecovery({ server, raw, local })
  assert.deepEqual(first.persisted.reviewed_topic_ids, [4, 28, 31])
  assert.deepEqual(first.persisted.change_reasons, local.change_reasons)
  assert.deepEqual(first.persisted.change_notes, local.change_notes)
  assert.deepEqual(first.persisted.dimensions, local.dimensions)
  assert.deepEqual(first.persisted.guided_answers, local.guided_answers)
  assert.deepEqual(runCurrentAutosave(server, first.observed), first.persisted)
  const second = runCurrentRecovery({ server, raw, local: first.persisted })
  assert.deepEqual(second.persisted, first.persisted)
  const restored = restoreMaterialityConfirmationDraft(JSON.stringify(second.persisted), { baseRevision: 2, p6TopicIds: [4] })
  const learning = restoreLearningTopicDraftState(second.persisted, { baseRevision: 2, p6TopicIds: [4] })
  assert.deepEqual(restored.guided_answers, local.guided_answers)
  assert.deepEqual(learning, { reviewed_topic_ids: [4, 28, 31], reviewed_universe: false })
  const payload = payloadFromRecovered(second.persisted)
  assert.deepEqual(payload.confirmed_topic_ids, [4])
  assert.equal(payload.universe_attestation.reviewed_universe, false)
  assert.equal(payload.change_reason_notes[31], "unsaved rejection note")
  if (process.env.T03_PAYLOAD_ARTIFACT) writeFileSync(process.env.T03_PAYLOAD_ARTIFACT, JSON.stringify({ server, payload }, null, 2))
})

test("pure recovery unions explicit evidence keys and applies per-key precedence without synthesizing guided negatives", () => {
  const { server, local } = recoveryFixture()
  const recovered = buildMaterialityConfirmationDraft({ baseRevision: 2, p6TopicIds: [4], selectedTopicIds: [4, 28], changeReasons: { 28: ["other"] }, guidedAnswers: { 4: guidedAnswer() } })
  const inputs = {
    serverDraft: { ...server.confirmation, selected_topic_ids: [4], change_notes: server.confirmation.change_reason_notes },
    currentDraft: { ...local, reviewed_topic_ids: [4], selected_topic_ids: [4, 40], change_notes: { ...local.change_notes, 37: "explicit evidence outside P6" } },
    recoveredDraft: recovered, recoveredReviewedTopicIds: [42],
  }
  const before = structuredClone(inputs)
  const result = recoveryState.mergeMaterialityRecoveryState(inputs)
  assert.deepEqual(inputs, before, "pure composition must not mutate inputs")
  assert.deepEqual(result.reviewed_topic_ids, [4, 28, 31, 37, 40, 42])
  assert.deepEqual(result.selected_topic_ids, [4, 28])
  assert.deepEqual(result.change_reasons, { 28: ["other"], 31: ["scope_change"] })
  assert.equal(result.change_notes[28], "server rejection note")
  assert.equal(result.change_notes[37], "explicit evidence outside P6")
  assert.equal(result.dimensions[31], "financial")
  assert.equal(result.guided_answers[28], undefined, "inconsistent prior verdict is retired, never inverted")
  assert.equal(result.guided_answers[31].note, "unsaved guided note")
  assert.equal(result.guided_answers[40], undefined, "absence must not create an evaluated rejection")
  assert.equal(result.guided_answers[42], undefined)
  assert.equal(result.reviewed_universe, false)
  const overridden = recoveryState.mergeMaterialityRecoveryState({ ...inputs, recoveredDraft: { ...recovered, selected_topic_ids: [4], change_notes: { 28: "recovered explicit note" }, dimensions: { 28: "both" }, guided_answers: { 28: guidedAnswer({ suggested_result: "no_material", final_result: "no_material", note: "recovered explicit verdict" }) } } })
  assert.equal(overridden.change_notes[28], "recovered explicit note")
  assert.equal(overridden.dimensions[28], "both")
  assert.equal(overridden.guided_answers[28].note, "recovered explicit verdict")
  assert.equal(overridden.guided_answers[31].note, "unsaved guided note")
})

test("explicit recovered guided decisions retain an unobserved prior guided note", () => {
  const { server, local } = recoveryFixture()
  const decision = guidedAnswer({ impacto: "bajo", financiero: "bajo", suggested_result: "no_material", final_result: "no_material" })
  const result = recoveryState.mergeMaterialityRecoveryState({
    serverDraft: { ...server.confirmation, change_notes: server.confirmation.change_reason_notes },
    currentDraft: { ...local, guided_answers: { ...local.guided_answers, 28: { ...decision, note: "live author note" } } },
    recoveredDraft: buildMaterialityConfirmationDraft({ baseRevision: 2, p6TopicIds: [4], selectedTopicIds: [4], guidedAnswers: { 28: decision } }),
  })
  assert.equal(result.guided_answers[28].note, "live author note")
  assert.equal(result.guided_answers[28].final_result, "no_material")
  assert.equal(result.guided_answers[31].note, "unsaved guided note")
})

function guidedAnswer(overrides = {}) {
  return {
    impacto: "medio",
    financiero: "medio",
    confianza: "media",
    exposicion: "normal",
    suggested_result: "material",
    final_result: "material",
    revisar: false,
    ...overrides,
  }
}

test("reviewed topic ids grow monotonically independently of final selection", () => {
  const presented = mergeReviewedTopicIds([], [2, 4])
  const added = mergeReviewedTopicIds(presented, [28])
  const rejectedButRetained = mergeReviewedTopicIds(added, [])
  const laterInteraction = mergeReviewedTopicIds(rejectedButRetained, [31, 28])

  assert.deepEqual(presented, [2, 4])
  assert.deepEqual(added, [2, 4, 28])
  assert.deepEqual(rejectedButRetained, [2, 4, 28])
  assert.deepEqual(laterInteraction, [2, 4, 28, 31])
  assert.deepEqual(mergeReviewedTopicIds(laterInteraction, ["32", true, 0, -1]), laterInteraction)
})

test("learning topic draft state restores only for the exact P8 revision and P6 snapshot", () => {
  const draft = buildMaterialityConfirmationDraft({
    baseRevision: 7,
    p6TopicIds: [4, 2],
    selectedTopicIds: [4],
    mode: "direct",
  })
  const enriched = attachLearningTopicDraftState(draft, {
    reviewedTopicIds: [2, 4, 28],
    reviewedUniverse: true,
  })
  const raw = JSON.stringify(enriched)

  assert.deepEqual(
    restoreLearningTopicDraftState(raw, { baseRevision: 7, p6TopicIds: [2, 4] }),
    { reviewed_topic_ids: [2, 4, 28], reviewed_universe: true },
  )
  assert.equal(restoreLearningTopicDraftState(raw, { baseRevision: 8, p6TopicIds: [2, 4] }), null)
  assert.equal(restoreLearningTopicDraftState(raw, { baseRevision: 7, p6TopicIds: [2, 5] }), null)
  assert.equal(restoreLearningTopicDraftState("not-json", { baseRevision: 7, p6TopicIds: [2, 4] }), null)
})

test("local P8 drafts never inherit a positive server universe attestation without valid local T03 state", () => {
  const server = {
    serverReviewedTopicIds: [4, 28],
    p6TopicIds: [4],
    serverAttestation: { version: 1, reviewed_universe: true, mode: "direct" },
    serverLearningTopicLabels: { 4: 1, 28: 0 },
    isStale: false,
  }

  assert.deepEqual(resolveLearningTopicHydration({
    ...server,
    hasLocalDraft: true,
    localLearningState: null,
  }), {
    reviewed_topic_ids: [4, 28],
    reviewed_universe: false,
  })

  assert.deepEqual(resolveLearningTopicHydration({
    ...server,
    hasLocalDraft: true,
    localLearningState: { reviewed_topic_ids: [4, "28"], reviewed_universe: true },
  }), {
    reviewed_topic_ids: [4, 28],
    reviewed_universe: false,
  })

  assert.deepEqual(resolveLearningTopicHydration({
    ...server,
    hasLocalDraft: true,
    localLearningState: { reviewed_topic_ids: [4, 31], reviewed_universe: true },
  }), {
    reviewed_topic_ids: [4, 28, 31],
    reviewed_universe: false,
  })

  assert.deepEqual(resolveLearningTopicHydration({
    ...server,
    hasLocalDraft: true,
    localLearningState: { reviewed_topic_ids: [4, 28, 31], reviewed_universe: true },
  }), {
    reviewed_topic_ids: [4, 28, 31],
    reviewed_universe: true,
  })
})

test("server attestation hydrates only without a local draft and with a valid closed label map", () => {
  const base = {
    hasLocalDraft: false,
    localLearningState: null,
    serverReviewedTopicIds: [4, 28],
    p6TopicIds: [4],
    serverAttestation: { version: 1, reviewed_universe: true, mode: "direct" },
    isStale: false,
  }

  assert.deepEqual(resolveLearningTopicHydration({
    ...base,
    serverLearningTopicLabels: { 4: 1, 28: 0 },
  }), {
    reviewed_topic_ids: [4, 28],
    reviewed_universe: true,
  })
  assert.equal(resolveLearningTopicHydration({ ...base, serverLearningTopicLabels: null }).reviewed_universe, false)
  assert.equal(resolveLearningTopicHydration({ ...base, serverLearningTopicLabels: { 4: 1 } }).reviewed_universe, false)
  assert.equal(resolveLearningTopicHydration({ ...base, serverLearningTopicLabels: { 4: 1, 28: false } }).reviewed_universe, false)
})

test("guided universe completion requires one consistent terminal non-observational answer per reviewed topic", () => {
  const context = {
    reviewedTopicIds: [4, 28],
    selectedTopicIds: [4],
  }
  const complete = {
    4: guidedAnswer(),
    28: guidedAnswer({
      impacto: "bajo",
      financiero: "bajo",
      confianza: "alta",
      suggested_result: "no_material",
      final_result: "no_material",
    }),
  }

  assert.equal(guidedUniverseIsComplete({ ...context, guidedAnswers: complete }), true)
  assert.equal(guidedUniverseIsComplete({ ...context, guidedAnswers: { 4: complete[4] } }), false)
  assert.equal(guidedUniverseIsComplete({
    ...context,
    guidedAnswers: { ...complete, 28: guidedAnswer({ final_result: "material" }) },
  }), false)
  assert.equal(guidedUniverseIsComplete({
    ...context,
    guidedAnswers: { ...complete, 28: guidedAnswer({ suggested_result: "en_observacion", final_result: "no_material" }) },
  }), false)
  assert.equal(guidedUniverseIsComplete({
    ...context,
    guidedAnswers: { ...complete, 28: guidedAnswer({ impacto: "no_lo_se", final_result: "no_material" }) },
  }), false)
})

test("P8 payload carries the explicit universe and preserves evidence for reviewed non-final topics", () => {
  const rejectedAnswer = guidedAnswer({
    impacto: "bajo",
    financiero: "bajo",
    confianza: "alta",
    suggested_result: "no_material",
    final_result: "no_material",
    note: "Reviewed after adding",
  })
  const payload = buildMaterialityConfirmationPayload({
    selectedTopicIds: [4],
    p6TopicIds: [4],
    reviewedTopicIds: [4, 28],
    reviewedUniverse: true,
    mode: "direct",
    changeReasons: { 28: ["stakeholders"] },
    changeNotes: { 28: " rejected after review " },
    dimensions: { 28: "impact" },
    guidedAnswers: { 28: rejectedAnswer },
  })

  assert.deepEqual(payload, {
    confirmed_topic_ids: [4],
    reviewed_topic_ids: [4, 28],
    universe_attestation: {
      version: 1,
      reviewed_universe: true,
      mode: "direct",
    },
    change_reasons: { 28: ["stakeholders"] },
    change_reason_notes: { 28: "rejected after review" },
    e1_not_material_explanation: null,
    dimensions: { 28: "impact" },
    guided_answers: { 28: rejectedAnswer },
  })
})

test("server dimensions survive local draft reload and the next P8 payload", () => {
  const serverDimensions = { 28: "impact" }
  const draft = attachLearningTopicDraftState(buildMaterialityConfirmationDraft({
    baseRevision: 9,
    p6TopicIds: [4],
    selectedTopicIds: [4],
    dimensions: serverDimensions,
    mode: "direct",
  }), {
    reviewedTopicIds: [4, 28],
    reviewedUniverse: false,
  })
  const raw = JSON.stringify(draft)
  const restoredDraft = restoreMaterialityConfirmationDraft(raw, { baseRevision: 9, p6TopicIds: [4] })
  const restoredLearning = restoreLearningTopicDraftState(raw, { baseRevision: 9, p6TopicIds: [4] })
  const payload = buildMaterialityConfirmationPayload({
    selectedTopicIds: restoredDraft.selected_topic_ids,
    p6TopicIds: restoredDraft.p6_topic_ids,
    reviewedTopicIds: restoredLearning.reviewed_topic_ids,
    reviewedUniverse: restoredLearning.reviewed_universe,
    mode: restoredDraft.mode,
    dimensions: restoredDraft.dimensions,
  })

  assert.deepEqual(restoredDraft.dimensions, serverDimensions)
  assert.deepEqual(payload.dimensions, serverDimensions)

  const source = readFileSync(join(root, "components/wizard/final-topics-selection.tsx"), "utf8")
  assert.match(source, /const \[dimensions, setDimensions\] = useState<Dimensions>/)
  assert.match(source, /\.\.\.\(conf\.confirmation\.dimensions \?\? \{\}\)[\s\S]*\.\.\.\(localDraft\?\.dimensions \?\? \{\}\)/)
  assert.match(source, /mergeMaterialityDimensions\(dimensions, guidedDrafts\)/)
})

test("guided dimension derivation replaces only its own topic and preserves rejected direct evidence across mode switches", () => {
  const stored = { 28: "impact", 31: "financial" }
  const guided = {
    4: guidedAnswer(),
    28: guidedAnswer({ impacto: "bajo", financiero: "bajo", final_result: "no_material" }),
    31: guidedAnswer({ impacto: "alto", financiero: "bajo", final_result: "no_material" }),
  }

  assert.deepEqual(mergeMaterialityDimensions(stored, guided), {
    4: "both",
    28: "impact",
    31: "impact",
  })
  assert.deepEqual(mergeMaterialityDimensions(mergeMaterialityDimensions(stored, guided), {}), {
    4: "both",
    28: "impact",
    31: "impact",
  })
})

test("direct review summary renders the complete reviewed universe and truthful material transition", () => {
  const direct = guidedReviewTopicIds({
    mode: "direct",
    reviewedTopicIds: [4, 28, 31],
    selectedTopicIds: new Set([4]),
    guidedAnswers: {
      28: guidedAnswer({ final_result: "material" }),
    },
  })
  const guided = guidedReviewTopicIds({
    mode: "guided",
    reviewedTopicIds: [4, 28, 31],
    selectedTopicIds: new Set([4]),
    guidedAnswers: {
      28: guidedAnswer({ suggested_result: "no_material", final_result: "no_material" }),
    },
  })

  assert.deepEqual(direct, {
    materialIds: [4],
    observationIds: [],
    noMaterialIds: [28, 31],
  })
  assert.deepEqual(guided.noMaterialIds, [28])

  const source = readFileSync(join(root, "components/wizard/final-topics-selection.tsx"), "utf8")
  assert.match(source, /Marcar como material/)
})

test("guided payload can attest only the completeness derived from current reviewed answers", () => {
  const reviewedTopicIds = [4, 28]
  const selectedTopicIds = [4]
  const guidedAnswers = {
    4: guidedAnswer(),
    28: guidedAnswer({
      impacto: "bajo",
      financiero: "bajo",
      confianza: "alta",
      suggested_result: "no_material",
      final_result: "no_material",
    }),
  }
  const reviewedUniverse = guidedUniverseIsComplete({ reviewedTopicIds, selectedTopicIds, guidedAnswers })
  const payload = buildMaterialityConfirmationPayload({
    selectedTopicIds,
    p6TopicIds: [4],
    reviewedTopicIds,
    reviewedUniverse,
    mode: "guided",
    guidedAnswers,
  })

  assert.equal(reviewedUniverse, true)
  assert.deepEqual(payload.universe_attestation, {
    version: 1,
    reviewed_universe: true,
    mode: "guided",
  })
})

test("P8 payload drops non-canonical topic-map keys instead of coercing them", () => {
  const payload = buildMaterialityConfirmationPayload({
    selectedTopicIds: [4],
    p6TopicIds: [4],
    reviewedTopicIds: [4],
    reviewedUniverse: false,
    mode: "direct",
    changeReasons: { "04": ["other"] },
    changeNotes: { "04": "must not alias topic 4" },
    dimensions: { "04": "impact" },
    guidedAnswers: { "04": guidedAnswer() },
  })

  assert.deepEqual(payload.change_reasons, {})
  assert.deepEqual(payload.change_reason_notes, {})
  assert.equal("dimensions" in payload, false)
  assert.equal("guided_answers" in payload, false)
})

test("Step 4 exposes a technical completeness checkbox without model or legal approval claims", () => {
  const source = readFileSync(join(root, "components/wizard/final-topics-selection.tsx"), "utf8")

  assert.match(source, /reviewedTopics/)
  assert.match(source, /guidedUniverseIsComplete/)
  assert.match(source, /He revisado todos los temas mostrados o añadidos/)
  assert.match(source, /universo técnico de revisión/)
  assert.match(source, /no aprueba un modelo ni constituye una declaración legal final/)
  assert.match(source, /reviewed_topic_ids/)
  assert.match(source, /universe_attestation/)
})

test("Laravel client and OpenAPI expose the same nullable derived labels and stored universe attestation", () => {
  const client = readFileSync(join(root, "lib/laravel-api.ts"), "utf8")
  const openapi = JSON.parse(readFileSync(join(root, "../contracts/api/frontend-characterization-openapi-v0.json"), "utf8"))
  const schemas = openapi.components.schemas
  const state = schemas.MaterialityConfirmationState
  const details = schemas.MaterialityConfirmationDetails
  const request = schemas.MaterialityConfirmationRequest
  const attestation = schemas.MaterialityUniverseAttestation
  const dimensions = schemas.MaterialityDimensions
  const guidedAnswers = schemas.MaterialityGuidedAnswers
  const putOperation = openapi.paths["/api/materiality-confirmation"].put

  assert.match(client, /learning_topic_labels:\s*Record<string,\s*0\s*\|\s*1>\s*\|\s*null/)
  assert.match(client, /reviewed_topic_ids:\s*number\[\]/)
  assert.match(client, /version:\s*1/)
  assert.match(client, /reviewed_universe:\s*boolean/)
  assert.match(client, /mode:\s*"direct"\s*\|\s*"guided"/)
  assert.match(client, /type LaravelMaterialityLearningUniversePair\s*=\s*\|\s*\{[\s\S]*reviewed_topic_ids:\s*number\[\][\s\S]*universe_attestation:\s*LaravelMaterialityUniverseAttestation[\s\S]*\}\s*\|\s*\{[\s\S]*reviewed_topic_ids\?:\s*never[\s\S]*universe_attestation\?:\s*never/)

  assert.ok(state.required.includes("learning_topic_labels"))
  assert.deepEqual(state.properties.learning_topic_labels.oneOf[1], { type: "null" })
  assert.equal(state.properties.learning_topic_labels.oneOf[0].additionalProperties.enum.join(","), "0,1")
  assert.ok(details.required.includes("reviewed_topic_ids"))
  assert.ok(details.required.includes("universe_attestation"))
  assert.equal(details.properties.universe_attestation.oneOf[0].$ref, "#/components/schemas/MaterialityUniverseAttestation")
  assert.equal(request.properties.reviewed_topic_ids.uniqueItems, true)
  assert.equal(request.properties.expected_revision.maximum, 9_007_199_254_740_991)
  assert.equal(request.properties.confirmed_topic_ids.maxItems, 89)
  assert.equal(request.properties.confirmed_topic_ids.items.minimum, 1)
  assert.equal(request.properties.reviewed_topic_ids.maxItems, 89)
  assert.equal(request.properties.reviewed_topic_ids.items.minimum, 1)
  assert.equal(request.properties.change_reasons.maxProperties, 89)
  assert.equal(request.properties.change_reasons.additionalProperties.maxItems, 6)
  assert.equal(request.properties.change_reasons.additionalProperties.uniqueItems, true)
  assert.equal(request.properties.change_reason_notes.maxProperties, 89)
  assert.equal(dimensions.maxProperties, 89)
  assert.equal(guidedAnswers.maxProperties, 89)
  assert.equal(details.properties.reviewed_topic_ids.maxItems, 89)
  assert.equal(details.properties.reviewed_topic_ids.items.minimum, 1)
  assert.equal(details.properties.change_reasons.maxProperties, 89)
  assert.equal(details.properties.change_reasons.additionalProperties.maxItems, 6)
  assert.equal(details.properties.change_reasons.additionalProperties.uniqueItems, true)
  assert.equal(putOperation.requestBody["x-max-content-length"], 1_048_576)
  assert.ok(putOperation.responses["413"])
  assert.equal(request.properties.universe_attestation.$ref, "#/components/schemas/MaterialityUniverseAttestation")
  assert.deepEqual(request.dependentRequired, {
    reviewed_topic_ids: ["universe_attestation"],
    universe_attestation: ["reviewed_topic_ids"],
  })
  assert.deepEqual(attestation.required, ["version", "reviewed_universe", "mode"])
  assert.deepEqual(attestation.properties.version.enum, [1])
  assert.deepEqual(attestation.properties.mode.enum, ["direct", "guided"])
  assert.equal(attestation.additionalProperties, false)
})

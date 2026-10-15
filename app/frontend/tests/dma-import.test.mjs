import assert from "node:assert/strict"
import test from "node:test"
import { readFileSync } from "node:fs"
import { applyDmaSuggestion, dmaReferencesFromDraft } from "../lib/dma-import-state.mjs"
import { ui } from "../lib/i18n/messages.mjs"
import { buildMaterialityConfirmationDraft, restoreMaterialityConfirmationDraft } from "../lib/materiality-confirmation-draft.mjs"
import { buildMaterialityConfirmationPayload, mergeMaterialityDimensions } from "../lib/materiality-confirmation-state.mjs"

const initial = () => ({ selectedTopicIds: new Set([1]), reviewedTopicIds: new Set([1]), guidedAnswers: { 2: { final_result: "no_material" } },
  dimensions: { 2: "financial" }, changeReasons: {}, dmaImportRefs: { 1: 5 } })

test("DMA suggestions patch the draft, preserve other evidence, and never confirm or invent signals", () => {
  const before = initial()
  const after = applyDmaSuggestion(before, { topic_id: 2, suggestion: "material", dimension: "impact" }, 7)
  assert.deepEqual([...after.selectedTopicIds], [1, 2])
  assert.deepEqual([...after.reviewedTopicIds], [1, 2])
  assert.deepEqual(after.dmaImportRefs, { 1: 5, 2: 7 })
  assert.deepEqual(after.guidedAnswers, {})
  assert.equal(after.dimensions[2], "impact")
  assert.deepEqual(after.changeReasons[2], ["new_data", "threshold"])
  assert.equal(after.reviewedUniverse, false)
  assert.equal(after.mode, "direct")
  assert.equal(after.confirmed_at, undefined)
  assert.deepEqual([...before.selectedTopicIds], [1])
  assert.equal(before.guidedAnswers[2].final_result, "no_material")
})

test("not-material removes only the imported topic; unknown numeric dimensions are left for user review", () => {
  const after = applyDmaSuggestion(initial(), { topic_id: 1, suggestion: "no_material", dimension: null }, 7)
  assert.deepEqual([...after.selectedTopicIds], [])
  assert.equal(after.dimensions[1], undefined)
  assert.equal(after.guidedAnswers[2].final_result, "no_material")
  const material = applyDmaSuggestion(initial(), { topic_id: 2, suggestion: "material", dimension: null }, 7)
  assert.equal(material.dimensions[2], undefined)
})

test("review and invalid imports never change a decision or attach provenance", () => {
  const state = initial()
  assert.equal(applyDmaSuggestion(state, { topic_id: 1, suggestion: "review" }, 7), state)
  assert.equal(applyDmaSuggestion(state, { topic_id: 1, suggestion: "material" }, -1), state)
})

test("a cleared import dimension survives persistence, reload and the explicit confirmation payload", () => {
  const serverDimensions = { 1: "impact", 2: "financial" }
  const state = { ...initial(), dimensions: serverDimensions }
  const applied = applyDmaSuggestion(state, { topic_id: 2, suggestion: "material", dimension: null }, 7)
  const draft = buildMaterialityConfirmationDraft({
    baseRevision: 3, p6TopicIds: [1, 2], selectedTopicIds: applied.selectedTopicIds,
    dimensions: mergeMaterialityDimensions(applied.dimensions, applied.guidedAnswers),
    guidedAnswers: applied.guidedAnswers, changeReasons: applied.changeReasons, mode: applied.mode,
  })
  const persisted = JSON.stringify({ ...draft, dma_import_refs: applied.dmaImportRefs })
  const restored = restoreMaterialityConfirmationDraft(persisted, { baseRevision: 3, p6TopicIds: [1, 2] })
  const reloadedDimensions = restored?.dimensions ?? serverDimensions
  assert.deepEqual(reloadedDimensions, { 1: "impact" })
  const payload = buildMaterialityConfirmationPayload({
    selectedTopicIds: restored.selected_topic_ids, p6TopicIds: [1, 2],
    dimensions: reloadedDimensions, guidedAnswers: restored.guided_answers,
    changeReasons: restored.change_reasons, reviewedTopicIds: [1, 2], reviewedUniverse: true, mode: restored.mode,
  })
  assert.deepEqual(payload.dimensions, { 1: "impact" })
  assert.deepEqual(dmaReferencesFromDraft(persisted), { 1: 5, 2: 7 })
  assert.equal(payload.universe_attestation.reviewed_universe, true)
  // Bind this transition to the component's actual hydration rule.
  const parent = readFileSync(new URL('../components/wizard/final-topics-selection.tsx', import.meta.url), 'utf8')
  assert.match(parent, /setDimensions\(localDraft\?\.dimensions \?\? conf\.confirmation\.dimensions \?\? \{\}\)/)

  const empty = applyDmaSuggestion({ ...initial(), dimensions: { 2: "financial" } },
    { topic_id: 2, suggestion: "material", dimension: null }, 7)
  const emptyDraft = buildMaterialityConfirmationDraft({ baseRevision: 3, p6TopicIds: [1, 2],
    selectedTopicIds: empty.selectedTopicIds, dimensions: empty.dimensions })
  const emptyRestored = restoreMaterialityConfirmationDraft(JSON.stringify(emptyDraft), { baseRevision: 3, p6TopicIds: [1, 2] })
  assert.deepEqual(emptyRestored?.dimensions ?? serverDimensions, {})
  assert.equal(restoreMaterialityConfirmationDraft(persisted, { baseRevision: 4, p6TopicIds: [1, 2] }), null)
})

test("validated recovery retains per-topic import IDs and rejects malformed reference maps", () => {
  assert.deepEqual(dmaReferencesFromDraft('{"dma_import_refs":{"1":7,"2":8}}'), { 1: 7, 2: 8 })
  for (const raw of ['bad', '{"dma_import_refs":[]}', '{"dma_import_refs":{"1":"7"}}', '{"dma_import_refs":{"-1":7}}']) {
    assert.deepEqual(dmaReferencesFromDraft(raw), {})
  }
})

test("DMA panel is conditional on the backend flag, labelled, localized, and has an explicit draft action", () => {
  const parent = readFileSync(new URL('../components/wizard/final-topics-selection.tsx', import.meta.url), 'utf8')
  const panel = readFileSync(new URL('../components/wizard/dma-import-panel.tsx', import.meta.url), 'utf8')
  assert.match(parent, /confirmation\.dma_import_enabled && <DmaImportPanel/)
  assert.match(panel, /htmlFor="dma-iro"/)
  assert.match(panel, /htmlFor="dma-stakeholders"/)
  assert.match(panel, /aria-live="polite"/)
  assert.match(panel, /suggestion\.suggestion === "review"/)
  assert.equal(ui('en', 'Resultados importados del ADM'), 'Imported DMA results')
  assert.equal(ui('es', 'Aplicar al borrador'), 'Aplicar al borrador')
})

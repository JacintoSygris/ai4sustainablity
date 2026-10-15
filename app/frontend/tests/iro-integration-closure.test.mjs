import test from "node:test"
import assert from "node:assert/strict"
import { compactDrafts, patchDatapointDrafts } from "../lib/esrs-datapoints-state.mjs"
import { applyDmaSuggestion, dmaReferencedImportIds, dmaReviewRows } from "../lib/dma-import-state.mjs"
import { iroReviewPayload } from "../lib/iro-traceability.mjs"
import { nlpIndicatorTarget } from "../lib/dma-nlp-state.mjs"
import { readFileSync } from "node:fs"
import vm from "node:vm"
import ts from "typescript"

test("authority confirmation restores the prior status while preserving public fact serialization", () => {
  const fact = { fact_id: "fact-1", value_kind: "monetary", value: " 12.50 ", decimals: 2,
    unit: { measure: " iso4217:EUR " }, context: { period_type: "duration", start_date: "2025-01-01",
      end_date: "2025-12-31", dimensions: [{ axis: " z ", member: " b " }, { axis: " a ", member: " c " }] },
    evidence_reference: " evidence " }
  const drafts = { A: { previous_status: "completed", status: "draft", facts: [fact] } }
  const expectedFact = { fact_id: "fact-1", value_kind: "monetary", value: "12.50", decimals: 2,
    unit: { measure: "iso4217:EUR" }, context: { period_type: "duration", start_date: "2025-01-01",
      end_date: "2025-12-31", instant_date: null, dimensions: [{ axis: "a", member: "c" }, { axis: "z", member: "b" }] },
    evidence_reference: "evidence" }
  assert.deepEqual(compactDrafts(drafts)[0].facts, [expectedFact])
  assert.equal(compactDrafts(drafts)[0].status, "draft")
  assert.deepEqual(compactDrafts(drafts, true)[0].facts, [expectedFact])
  assert.equal(compactDrafts(drafts, true)[0].status, "completed")
  const edited = patchDatapointDrafts(drafts, ["A"], { status: "draft" })
  assert.equal(edited.A.previous_status, undefined)
  assert.equal(compactDrafts(edited, true)[0].status, "draft")
  assert.equal(compactDrafts({ A: { status: "draft", previous_status: "not_applicable" } }, true)[0].status, "not_applicable")
})

test("replacing a DMA import retires only its superseded IRO reviews", () => {
  const reviews = { old: { iro_id: "old", topic_id: 4, import_id: 1, confirmed: true },
    current: { iro_id: "current", topic_id: 4, import_id: 2, confirmed: true },
    other: { iro_id: "other", topic_id: 5, import_id: 1, confirmed: true } }
  const next = applyDmaSuggestion({ selectedTopicIds: new Set(), reviewedTopicIds: new Set(),
    guidedAnswers: {}, dimensions: {}, changeReasons: {}, dmaImportRefs: { 5: 1 }, iroReviews: reviews },
  { topic_id: 4, suggestion: "material", dimension: "impact" }, 2)
  assert.deepEqual(Object.keys(next.iroReviews), ["current", "other"])
  assert.ok(reviews.old)
  assert.deepEqual(iroReviewPayload(next.iroReviews, next.dmaImportRefs).map(row => row.iro_id), ["current", "other"])
  assert.deepEqual(dmaReferencedImportIds({ a: 2, b: 1, c: 2, d: -1, e: "3" }), [1, 2])
  assert.deepEqual(dmaReviewRows([{ import_id: 1, iro_rows: [{ iro_id: "same" }, {}] },
    { import_id: 2, iro_rows: [{ iro_id: "same" }] }]).map(item => item.importId), [1, 2])
})

function p9Declaration(name) {
  const source = readFileSync(new URL("../components/wizard/esrs-datapoints-form.tsx", import.meta.url), "utf8")
  const tree = ts.createSourceFile("form.tsx", source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX)
  let found
  function visit(node) {
    if ((ts.isFunctionDeclaration(node) || ts.isVariableDeclaration(node)) && node.name?.getText(tree) === name) found = node
    ts.forEachChild(node, visit)
  }
  visit(tree)
  assert.ok(found, name)
  return ts.transpileModule((ts.isVariableDeclaration(found) ? "const " : "") + found.getText(tree),
    { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
}

test("actual P9 NLP review opens the accepted indicator and refreshes only matching authority", async () => {
  const authority = "a".repeat(64), nextContext = { cards: [] }
  const candidate = { id: "A", level: "datapoint", decision: "accepted", in_scope: true }
  let current = { p9_authority_digest: authority, blocks: { all: { datapoints: [{ id: "A", standard: "ESRS E1", dr: "E1-1" }] } } }
  const observed = {}
  const context = vm.createContext({ corpus: current, nlpIndicatorTarget, p9AuthorityRef: { current: authority },
    setViewMode: value => { observed.mode = value }, setObligationFilter: value => { observed.filter = value },
    setExpandedStandards: fn => { observed.standards = fn({}) }, setExpandedRequirements: fn => { observed.requirements = fn({}) },
    setExpandedDatapoints: fn => { observed.points = fn({}) }, setTimeout: fn => fn(),
    document: { getElementById: id => ({ focus() { observed.focus = id } }) },
    getLaravelEsrsDatapointWorkspace: async () => ({ data: { p9_authority_digest: authority, iro_context_v1: nextContext } }),
    setCorpus: fn => { current = fn(current) }, setErrorMessage: value => { observed.error = value }, systemCopy: value => value,
    candidate })
  vm.runInContext(p9Declaration("openNlpIndicator") + p9Declaration("handleNlpReview"), context)
  await vm.runInContext("handleNlpReview({}, candidate)", context)
  assert.equal(observed.mode, "respond")
  assert.equal(observed.filter, "all")
  assert.equal(observed.standards["ESRS E1"], true)
  assert.equal(observed.requirements["E1-1"], true)
  assert.equal(observed.points.A, true)
  assert.equal(observed.focus, "A-status")
  assert.equal(current.iro_context_v1, nextContext)
  const acknowledged = current
  context.p9AuthorityRef.current = "b".repeat(64)
  await vm.runInContext("handleNlpReview({}, candidate)", context)
  assert.equal(current, acknowledged)
  assert.equal(observed.error, undefined)
})

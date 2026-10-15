import test from 'node:test'
import assert from 'node:assert/strict'
import { nlpBatch, nlpRequest, nlpResponseCurrent, applyNlpTopicDraft, currentNlpDraft, nlpIroCandidates, nlpReviewTransition, nlpIndicatorTarget } from '../lib/dma-nlp-state.mjs'
import { applyDmaSuggestion } from '../lib/dma-import-state.mjs'
import { ui } from '../lib/i18n/messages.mjs'
import { hydrateIroReviews, iroReviewPayload } from '../lib/iro-traceability.mjs'
const context = 'a'.repeat(64)
const rows = Array.from({ length: 41 }, (_, i) => ({ iro_id: String(i).padStart(64, '0') }))

test('disabled feature dispatches nothing and explicitly paginates the full register', () => {
  assert.equal(nlpRequest(false, context, rows, 0, 'p9', 'es', 1), null)
  assert.equal(nlpRequest(true, '', rows, 0, 'p9', 'es', 1), null)
  assert.equal(nlpBatch(rows, 0).length, 20); assert.equal(nlpBatch(rows, 1).length, 20); assert.equal(nlpBatch(rows, 2).length, 1)
  assert.deepEqual([0, 1, 2].flatMap(page => nlpBatch(rows, page)), rows.map(row => row.iro_id))
  assert.deepEqual(nlpRequest(true, context, rows, 2, 'p9', 'es', 3), { stage: 'p9', locale: 'es', iro_ids: [rows[40].iro_id], expected_context: context, expected_revision: 3 })
})
test('accepting a topic changes associations only, with separate NLP references', () => {
  const draft = { reviewedTopicIds: new Set([11]), selectedTopicIds: new Set([11]), dmaImportRefs: { 11: 2 }, answers: { x: 'saved' } }
  const next = applyNlpTopicDraft(draft, { id: 1, stage: 'p8' }, { level: 'topic', id: '13', candidate_id: 'c', decision: 'accepted' })
  assert.deepEqual([...next.reviewedTopicIds], [11, 13]); assert.deepEqual(next.nlpRefs, ['1|c'])
  assert.equal(next.selectedTopicIds, draft.selectedTopicIds); assert.equal(next.dmaImportRefs, draft.dmaImportRefs); assert.equal(next.answers, draft.answers)
  assert.equal(applyNlpTopicDraft(draft, { stage: 'p9' }, { level: 'topic' }), draft)
})
test('immutable reviews do not flip accepted or rejected decisions', () => {
  const run = { candidates: [{ candidate_id: 'a', decision: null }, { candidate_id: 'b', decision: null }] }
  const one = nlpReviewTransition(run, 'a', 'accepted'); const two = nlpReviewTransition(one, 'a', 'rejected'); const three = nlpReviewTransition(two, 'b', 'rejected')
  assert.deepEqual(three.candidates.map(c => c.decision), ['accepted', 'rejected']); assert.equal(run.candidates[0].decision, null)
})
test('replacing register A with B retires only A draft links and confirms B without incompatible references', () => {
  const accepted = (iro_id, candidate_id) => ({ level: 'topic', id: '27', iro_id, candidate_id, decision: 'accepted' })
  let draft = { reviewedTopicIds: new Set([26]), selectedTopicIds: new Set([26, 27]), dmaImportRefs: { 26: 1, 11: 3 },
    changeReasons: {}, guidedAnswers: {}, dimensions: {}, iroReviews: { A: { iro_id: 'A', import_id: 1, topic_id: 26, confirmed: true } } }
  draft = applyNlpTopicDraft(draft, { id: 10, import_id: 1, stage: 'p8' }, accepted('A', 'a'), 26)
  draft = applyNlpTopicDraft(draft, { id: 30, import_id: 3, stage: 'p8' }, accepted('C', 'c'), 11)
  const history = structuredClone(draft.nlpSources)
  draft = applyDmaSuggestion(draft, { topic_id: 26, suggestion: 'material', dimension: 'impact' }, 2)
  draft = currentNlpDraft(draft, draft.dmaImportRefs)
  assert.deepEqual(draft.nlpRefs, ['30|c']); assert.equal(draft.inferredTopics.A, undefined)
  draft.iroReviews.B = { iro_id: 'B', import_id: 2, topic_id: 26, confirmed: true }
  draft = applyNlpTopicDraft(draft, { id: 20, import_id: 2, stage: 'p8' }, accepted('B', 'b'), 26)
  assert.deepEqual(iroReviewPayload(draft.iroReviews, draft.dmaImportRefs).map(row => row.iro_id), ['B'])
  assert.deepEqual(draft.nlpRefs, ['30|c', '20|b']); assert.deepEqual(draft.inferredTopics, { C: [27], B: [27] })
  // Delayed acceptance from the retired register must not re-enter the payload.
  draft = currentNlpDraft(applyNlpTopicDraft(draft, { id: 10, import_id: 1, stage: 'p8' }, accepted('A', 'a'), 26), draft.dmaImportRefs)
  assert.deepEqual(draft.nlpRefs, ['30|c', '20|b']); assert.equal(history['10|a'].importId, 1)
})
test('an inferred association exposes confirmed-topic indicators while retaining the CSV topic', () => {
  const row = { iro_id: 'iro', topic_id: 26 }
  const draft = { reviewedTopicIds: new Set([26]), selectedTopicIds: new Set([27]), dmaImportRefs: { 26: 2 } }
  const next = applyNlpTopicDraft(draft, { id: 1, stage: 'p8' },
    { level: 'topic', id: '27', iro_id: 'iro', candidate_id: 'c', decision: 'accepted' })
  const candidates = { 26: [{ id: 'source-only' }], 27: [{ id: 'E5-5_16' }] }
  assert.deepEqual(next.inferredTopics, { iro: [27] })
  assert.deepEqual(nlpIroCandidates(row, next.inferredTopics, next.selectedTopicIds, candidates), [{ id: 'E5-5_16' }])
  assert.deepEqual(nlpIroCandidates(row, {}, next.selectedTopicIds, candidates), [])
  assert.equal(row.topic_id, 26); assert.equal(next.dmaImportRefs, draft.dmaImportRefs)
  assert.equal(next.selectedTopicIds, draft.selectedTopicIds)
})
test('only current responses can update the review view', () => {
  assert.equal(nlpResponseCurrent(context, context, { context_digest: context }), true)
  assert.equal(nlpResponseCurrent(context, 'b'.repeat(64), { context_digest: context }), false)
  assert.equal(nlpResponseCurrent(context, context, { context_digest: 'old' }), false)
})
test('hydrated inferred reviews retain their source import reference and strip server associations', () => {
  const review = { import_id: 2, iro_id: 'iro', topic_id: 27, source_topic_id: 26,
    associated_topic_ids: [27], decision: 'material', focus_datapoint_ids: ['E5-5_16'] }
  const restored = hydrateIroReviews([review])
  assert.equal(restored.iro.topic_id, 26)
  const payload = iroReviewPayload(restored, { 26: 2 })
  assert.equal(payload[0].iro_id, 'iro'); assert.deepEqual(payload[0].focus_datapoint_ids, ['E5-5_16'])
  assert.equal(payload[0].source_topic_id, undefined); assert.equal(payload[0].associated_topic_ids, undefined)
})
test('E5-5 indicators navigate to their exact existing editors and never alter obligations', () => {
  const points = ['E5-5_03', 'E5-5_15', 'E5-5_16'].map(id => ({ id, dr: 'E5-5', standard: 'E5' }))
  for (const point of points) assert.deepEqual(nlpIndicatorTarget({ level: 'datapoint', id: point.id, decision: 'accepted', in_scope: true }, points), { ...point, editorId: `${point.id}-status` })
  assert.equal(nlpIndicatorTarget({ level: 'datapoint', id: 'E5-5_15', decision: 'rejected', in_scope: true }, points), null)
  assert.equal(nlpIndicatorTarget({ level: 'datapoint', id: 'E5-5_15', decision: 'accepted', in_scope: false }, points), null)
  assert.equal(points.length, 3)
})
test('ES and EN messages preserve advisory semantics', () => {
  for (const [es, en] of [['Analizar textos de doble importancia relativa','Analyse double-materiality text'], ['Indicadores NEIS propuestos','Suggested ESRS indicators'], ['Aceptar vínculo','Accept link'], ['Rechazar propuesta','Reject suggestion'], ['Sin evidencia suficiente','Insufficient evidence'], ['Estas propuestas no modifican las obligaciones de información.','These suggestions do not change disclosure obligations.']]) {
    assert.equal(ui('es', es), es); assert.equal(ui('en', es), en)
  }
})

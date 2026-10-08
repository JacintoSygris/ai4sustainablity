import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import {
  validateDatapointWorkspace, hydrateDatapointDrafts, datapointFeedbackPacket,
  mergeDatapointRecovery, createResponseSaveQueue, datapointDisplayName,
} from '../lib/esrs-datapoints-state.mjs'

function workspace(locale) {
  const authority = 'a'.repeat(64)
  return {
    snapshot_version: 'p9-workspace-v1',
    data: {
      locale, characterization_id: 9, mapping_snapshot_digest: 'b'.repeat(64),
      learning_authority_digest: authority,
      blocks: { all: { datapoints: [{ id: 'BP-1_01', name: 'Basis for preparation',
        display: { locale, name: locale === 'es' ? 'Bases de elaboración' : 'Basis for preparation' } }] } },
    },
    response_state: {
      characterization_id: 9, revision: 7, source_digest: 'c'.repeat(64),
      learning_authority_digest: authority,
      responses: { 'BP-1_01': { status: 'draft', value: 'Texto libre unchanged', note: '=1+1' } },
      learning_feedback: { schema_version: 'datapoint-feedback-v1', authority_digest: authority,
        reviewed_datapoint_ids: ['BP-1_01'], decisions: [{ datapoint_id: 'BP-1_01',
          relevant: false, selected_to_answer: true, reason_codes: ['scope'], note: 'Unchanged review' }] },
    },
  }
}

test('both locales keep atomic authority, explicit negative decisions and verbatim drafts', () => {
  let prior
  for (const locale of ['es', 'en']) {
    const snapshot = workspace(locale)
    const original = structuredClone(snapshot)
    validateDatapointWorkspace(snapshot)
    const state = snapshot.response_state
    const drafts = hydrateDatapointDrafts(state.responses, state.learning_feedback)
    const packet = datapointFeedbackPacket(drafts, snapshot.data.learning_authority_digest)
    assert.deepEqual(packet, state.learning_feedback)
    if (prior) assert.deepEqual(drafts, prior)
    prior = drafts
    assert.equal(datapointDisplayName(snapshot.data.blocks.all.datapoints[0], locale),
      locale === 'es' ? 'Bases de elaboración' : 'Basis for preparation')
    assert.deepEqual(snapshot, original)
    const invalid = structuredClone(snapshot)
    invalid.response_state.learning_authority_digest = 'd'.repeat(64)
    assert.throws(() => validateDatapointWorkspace(invalid), /coherent/)
  }
})

test('recovery after authority drift preserves text but requires both decisions again', () => {
  const snapshot = workspace('en')
  const state = snapshot.response_state
  const local = hydrateDatapointDrafts(state.responses, state.learning_feedback)
  local.orphan = { value: 'Must not restore an obsolete row' }
  const recovered = mergeDatapointRecovery({}, local, ['BP-1_01'], 'old-authority', state.learning_authority_digest)
  assert.equal(recovered['BP-1_01'].value, 'Texto libre unchanged')
  assert.equal(recovered['BP-1_01'].note, '=1+1')
  assert.equal(recovered.orphan, undefined)
  assert.deepEqual(datapointFeedbackPacket(recovered, state.learning_authority_digest).decisions, [])
})

test('locale projection cannot make failed, old or invalidated save acknowledgements authoritative', async () => {
  const queue = createResponseSaveQueue(7)
  const edit = queue.markEdited()
  await assert.rejects(queue.enqueue([], edit, async () => { throw Error('synthetic failure') }), /failure/)
  assert.equal(queue.revision(), 7)
  let release
  const saving = queue.enqueue([], edit, () => new Promise(resolve => { release = resolve }))
  await Promise.resolve()
  validateDatapointWorkspace(workspace('en'))
  queue.markEdited()
  release({ data: { revision: 8 } })
  assert.equal((await saving).isLatestEdit, false)
  const discarded = queue.enqueue([], 2, () => new Promise(resolve => { release = resolve }))
  await Promise.resolve()
  queue.invalidate()
  release({ data: { revision: 9 } })
  assert.equal((await discarded).discarded, true)
  assert.equal(queue.revision(), 8)
})

test('locale contract remains additive to private learning and P8 review contracts', () => {
  const contract = JSON.parse(readFileSync(new URL('../../contracts/api/frontend-characterization-openapi-v0.json', import.meta.url)))
  assert.ok(contract.paths['/api/locale'])
  for (const path of ['/api/learning-case/draft', '/api/learning-case/close', '/api/learning-case/withdraw']) {
    assert.ok(contract.paths[path])
  }
  const request = contract.components.schemas.MaterialityConfirmationRequest
  assert.ok(request.properties.reviewed_topic_ids)
  assert.ok(request.properties.universe_attestation)
  assert.deepEqual(request.dependentRequired.reviewed_topic_ids, ['universe_attestation'])
})

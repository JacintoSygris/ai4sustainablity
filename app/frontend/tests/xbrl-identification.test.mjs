import test from 'node:test'
import assert from 'node:assert/strict'
import { initialIdentificationState, identificationReducer, canReviewIdentification, identificationRequest, identificationReview, datapointIdentificationSubject } from '../lib/xbrl-identification-state.mjs'
import { ui } from '../lib/i18n/messages.mjs'
const candidate = { qname: 'esrs:WaterConsumption', validation_status: 'map_only' }
const run = { response_json: { abstained: false, candidates: [candidate] }, reviews: [] }
test('datapoint prefills use the active language and preserve canonical IDs and source text', () => {
  const point = { id: 'E3-4_01', name: 'Water consumption', display: { locale: 'es', name: 'Consumo de agua' } }
  assert.deepEqual(datapointIdentificationSubject(point), { key: point.id, datapoint_id: point.id, label: 'Consumo de agua', description: 'Consumo de agua', language: 'es' })
  assert.equal(datapointIdentificationSubject(point, 'en').label, 'Water consumption')
  assert.equal(datapointIdentificationSubject({ ...point, display: undefined }).label, '')
  assert.equal(point.name, 'Water consumption')
})
test('receiving candidates never accepts them or selects a concept', () => {
  const state = identificationReducer(initialIdentificationState(), { type: 'result', generation: 0, run })
  assert.equal(state.run.reviews.length, 0)
  assert.equal(state.selected, undefined)
  assert.equal(canReviewIdentification(state, candidate, '', true, 'accepted'), false)
})
test('review controls require enabled idle nonabstained eligible state and a reason', () => {
  const state = { ...initialIdentificationState(), run }
  assert.equal(canReviewIdentification(state, candidate, 'Reviewed', true, 'accepted'), true)
  for (const [s, c, enabled] of [[state, candidate, false], [{ ...state, busy: true }, candidate, true], [state, { ...candidate, validation_status: 'unverified' }, true], [{ ...state, run: { ...run, response_json: { ...run.response_json, abstained: true } } }, candidate, true]]) {
    assert.equal(canReviewIdentification(s, c, 'Reviewed', enabled, 'accepted'), false)
  }
  assert.equal(canReviewIdentification(state, { ...candidate, validation_status: 'incompatible' }, 'Reviewed', true, 'rejected'), true)
})
test('input changes invalidate late inference and review responses', () => {
  let state = identificationReducer(initialIdentificationState(), { type: 'start', generation: 0 })
  state = identificationReducer(state, { type: 'input' })
  assert.deepEqual(identificationReducer(state, { type: 'result', generation: 0, run }), state)
  assert.deepEqual(identificationReducer(state, { type: 'error', generation: 0, status: 409 }), state)
})
test('conflicts disable old decisions and review payloads use persisted sequence', () => {
  const state = identificationReducer({ ...initialIdentificationState(), run }, { type: 'error', generation: 0, status: 409 })
  assert.equal(state.run, null); assert.equal(state.error, 'conflict')
  assert.deepEqual(identificationReview({ ...run, reviews: [{ sequence: 3 }] }, candidate, 'rejected', ' Keep wording '), { candidate_qname: candidate.qname, decision: 'rejected', reason: ' Keep wording ', expected_sequence: 3 })
})
test('ES/EN system copy and requests preserve user text and exclude values and identity', () => {
  const indicator = { label: '  Árbol & <tree>  ', description: 'My words', language: 'es' }
  assert.deepEqual(identificationRequest(indicator, { reporting_fact_id: 3, value: 25, user_id: 7 }), { indicator, reporting_fact_id: 3 })
  for (const [es, en] of [['Identificar concepto XBRL de las NEIS', 'Identify an ESRS XBRL concept'], ['Puntuación sin calibrar', 'Uncalibrated score'], ['Aceptar identificación', 'Accept identification'], ['No cambia la doble importancia relativa ni etiqueta automáticamente el informe.', 'This does not change double materiality or automatically tag the report.']]) {
    assert.equal(ui('es', es), es); assert.equal(ui('en', es), en)
  }
})

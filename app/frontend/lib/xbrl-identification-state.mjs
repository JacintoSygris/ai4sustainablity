import { datapointDisplayName } from "./esrs-datapoints-state.mjs"

export function datapointIdentificationSubject(point, locale = "es") {
  const label = datapointDisplayName(point, locale)
  return { key: point.id, datapoint_id: point.id, label, description: label, language: locale }
}

// Shared with the panel; server responses never constitute an acceptance.
export function initialIdentificationState() {
  return { generation: 0, busy: false, run: null, error: null }
}
export function identificationReducer(state, action) {
  if (action.type === "input") return { ...initialIdentificationState(), generation: state.generation + 1 }
  if (action.type === "history") return { ...initialIdentificationState(), generation: state.generation + 1, run: action.run }
  if (action.generation !== state.generation) return state
  if (action.type === "start") return { ...state, busy: true, error: null }
  if (action.type === "result") return { ...state, busy: false, run: action.run }
  if (action.type === "error") return { ...state, busy: false, error: action.status === 409 ? "conflict" : "unavailable", run: action.status === 409 ? null : state.run }
  return state
}
export function canReviewIdentification(state, candidate, reason, enabled, decision) {
  if (!enabled || state.busy || !state.run || !reason.trim() || reason.length > 1000) return false
  if (decision === "rejected") return true
  return !state.run.response_json.abstained && ["map_only", "verified_taxonomy"].includes(candidate.validation_status)
}
export function identificationRequest(indicator, subject) {
  return { indicator: { ...indicator }, ...(subject?.reporting_fact_id ? { reporting_fact_id: subject.reporting_fact_id } : {}),
    ...(subject?.datapoint_id ? { datapoint_id: subject.datapoint_id } : {}) }
}
export function identificationReview(run, candidate, decision, reason) {
  return { candidate_qname: candidate.qname, decision, reason,
    expected_sequence: Math.max(0, ...run.reviews.map(review => review.sequence)) }
}

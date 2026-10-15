/** Shared by production controls and behavioural tests; never changes materiality or answers. */
export function nlpBatch(rows, page = 0) {
  if (!Number.isInteger(page) || page < 0) return []
  return rows.slice(page * 20, (page + 1) * 20).map(row => row.iro_id).filter(Boolean)
}
export function nlpRequest(enabled, context, rows, page, stage, locale, revision) {
  const ids = nlpBatch(rows, page)
  return enabled && /^[a-f0-9]{64}$/.test(context ?? '') && ids.length
    ? { stage, locale, iro_ids: ids, expected_context: context, expected_revision: revision } : null
}
export function nlpResponseCurrent(dispatched, current, run) {
  return dispatched === current && run.context_digest === current
}
export function applyNlpTopicDraft(draft, run, candidate, sourceTopicId) {
  if (run.stage !== 'p8' || candidate.level !== 'topic' || candidate.decision !== 'accepted') return draft
  return { ...draft, reviewedTopicIds: new Set([...draft.reviewedTopicIds, Number(candidate.id)]),
    nlpRefs: [...new Set([...(draft.nlpRefs ?? []), `${run.id}|${candidate.candidate_id}`])],
    nlpSources: { ...(draft.nlpSources ?? {}), [`${run.id}|${candidate.candidate_id}`]:
      { importId: run.import_id, iroId: candidate.iro_id, sourceTopicId, topicId: Number(candidate.id) } },
    inferredTopics: { ...(draft.inferredTopics ?? {}), [candidate.iro_id]:
      [...new Set([...(draft.inferredTopics?.[candidate.iro_id] ?? []), Number(candidate.id)])] } }
}
export function nlpIroCandidates(row, inferredTopics, selectedTopics, candidates) {
  const ids = [row.topic_id, ...(inferredTopics?.[row.iro_id] ?? [])]
  return [...new Map(ids.filter(id => selectedTopics.has(id))
    .flatMap(id => candidates[id] ?? []).map(point => [point.id, point])).values()]
}
export function nlpReviewTransition(run, candidateId, decision) {
  if (!['accepted', 'rejected'].includes(decision)) return run
  return { ...run, candidates: run.candidates.map(candidate => candidate.candidate_id === candidateId && !candidate.decision
    ? { ...candidate, decision } : candidate) }
}
export function nlpIndicatorTarget(candidate, points) {
  if (candidate.level !== 'datapoint' || candidate.decision !== 'accepted' || !candidate.in_scope) return null
  const point = points.find(item => item.id === candidate.id)
  return point ? { id: point.id, standard: point.standard, dr: point.dr, editorId: `${point.id}-status` } : null
}

/** Retire replaced draft references only; immutable server reviews remain history.
 * Call with the next source references when accepting a link from another import.
 */
export function currentNlpDraft(draft, importRefs) {
  const entries = Object.entries(draft.nlpSources ?? {}).filter(([, source]) =>
    importRefs[source.sourceTopicId] === undefined || importRefs[source.sourceTopicId] === source.importId)
  if (entries.length === Object.keys(draft.nlpSources ?? {}).length) return draft
  const inferredTopics = {}
  for (const [, source] of entries) inferredTopics[source.iroId] =
    [...new Set([...(inferredTopics[source.iroId] ?? []), source.topicId])]
  return { ...draft, nlpRefs: entries.map(([ref]) => ref), nlpSources: Object.fromEntries(entries), inferredTopics }
}

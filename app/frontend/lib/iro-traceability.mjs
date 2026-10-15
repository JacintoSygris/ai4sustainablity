const reviewKeys = ['import_id', 'iro_id', 'decision', 'type', 'dimension', 'locations', 'groups', 'description', 'group', 'channel', 'reason', 'focus_datapoint_ids']

export function editableIroReview(review) {
  return Object.fromEntries(reviewKeys.filter(key => key in review).map(key => [key, review[key]]))
}

export function hydrateIroReviews(reviews = []) {
  return Object.fromEntries(reviews.map(review => [review.iro_id, { ...editableIroReview(review), topic_id: review.source_topic_id ?? review.topic_id, confirmed: true }]))
}

export function replaceIroReview(reviews, review, confirmed = false) {
  const alternative = reviews[review.iro_id]?.recovery_alternative
  return { ...reviews, [review.iro_id]: { ...editableIroReview(review), topic_id: review.topic_id,
    local_edit: true, confirmed: alternative ? false : confirmed,
    ...(alternative ? { recovery_alternative: alternative } : {}) } }
}

export function resolveIroRecovery(review, useAlternative) {
  const selected = useAlternative ? review.recovery_alternative : review
  return { ...editableIroReview(selected), import_id: selected.import_id,
    topic_id: review.topic_id, local_edit: true, confirmed: false }
}

export function iroReviewPayload(reviews, references) {
  const selected = Object.values(reviews).filter(review => references[String(review.topic_id)] === review.import_id)
  if (selected.some(review => !review.confirmed || review.recovery_alternative)) throw new Error('iro_review_required')
  return selected.map(editableIroReview).sort((a, b) => a.iro_id.localeCompare(b.iro_id))
}

export function restoreIroDraft(raw, compatible, fallback = []) {
  if (!compatible) return hydrateIroReviews(fallback)
  try { return JSON.parse(raw)?.iro_reviews_draft ?? hydrateIroReviews(fallback) } catch { return hydrateIroReviews(fallback) }
}

// Server-generated questions are already bilingual; user text never enters a translator.
/** @param {import("./laravel-api").LaravelIroContext | undefined} context */
export function iroCards(context, locale = 'es') {
  return (context?.cards ?? []).map(card => ({ ...card, links: card.links.map(link => ({ ...link,
    questions: link.questions.map(question => ({ ...question, text: question.text[locale === 'en' ? 'en' : 'es'] })),
  })) }))
}

export function isIroAuthorityConflict(error) {
  return error?.status === 409 && ['p8_confirmation_required', 'p8_confirmation_stale', 'iro_review_required', 'p9_mapping_unavailable'].includes(error?.payload?.code)
}

export function invalidateIroSaveQueue(error, queue) {
  if (!isIroAuthorityConflict(error)) return false
  queue.invalidate()
  return true
}

/** Explicit recovery keeps text/links but retires the old row confirmation. */
export function recoverIroDraft(raw, current) {
  const recovered = restoreIroDraft(raw, true)
  const next = { ...current }
  for (const [id, review] of Object.entries(recovered)) {
    const existing = current[id]
    // Hydrated server rows are not later local edits. Keep both versions until
    // the user resolves a difference, then require a fresh row confirmation.
    const selected = existing?.local_edit ? existing : review
    const alternative = existing?.local_edit ? review : existing
    const differs = alternative && reviewKeys.some(key => JSON.stringify(selected[key]) !== JSON.stringify(alternative[key]))
    next[id] = { ...selected, confirmed: false,
      ...(differs ? { recovery_alternative: alternative } : {}) }
  }
  return next
}

export function iroConflictMessage(error) {
  return error?.payload?.code === 'p9_mapping_unavailable'
    ? 'El mapa normativo aprobado no está disponible. Contacta con la administración.'
    : 'Confirma de nuevo la doble materialidad'
}

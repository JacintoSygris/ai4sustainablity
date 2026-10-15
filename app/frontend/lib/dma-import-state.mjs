/** A suggestion only patches a local P8 draft. It never creates a confirmation or four-signal answers. */
export function applyDmaSuggestion(state, suggestion, importId) {
  if (!Number.isSafeInteger(importId) || importId < 1 || !Number.isSafeInteger(suggestion.topic_id)
    || suggestion.topic_id < 1 || !["material", "no_material"].includes(suggestion.suggestion)) return state
  const key = String(suggestion.topic_id)
  const selected = new Set(state.selectedTopicIds)
  if (suggestion.suggestion === "material") selected.add(suggestion.topic_id)
  else selected.delete(suggestion.topic_id)
  const guided = { ...state.guidedAnswers }
  delete guided[key] // Avoid a contradictory pre-existing assistant answer; never invent signals.
  const dimensions = { ...state.dimensions }
  delete dimensions[key]
  if (suggestion.suggestion === "material" && ["impact", "financial", "both"].includes(suggestion.dimension)) {
    dimensions[key] = suggestion.dimension
  }
  return {
    ...state,
    ...(state.iroReviews ? { iroReviews: Object.fromEntries(Object.entries(state.iroReviews)
      .filter(([, review]) => review.topic_id !== suggestion.topic_id || review.import_id === importId)) } : {}),
    selectedTopicIds: selected,
    reviewedTopicIds: new Set([...state.reviewedTopicIds, suggestion.topic_id]),
    guidedAnswers: guided,
    dimensions,
    changeReasons: { ...state.changeReasons, [key]: [...new Set([...(state.changeReasons[key] ?? []), "new_data", "threshold"])] },
    dmaImportRefs: { ...state.dmaImportRefs, [key]: importId },
    reviewedUniverse: false,
    mode: "direct",
  }
}

/** Called only after the existing draft revision/P6 validation has succeeded. */
export function dmaReferencesFromDraft(raw) {
  try {
    const refs = JSON.parse(raw)?.dma_import_refs
    if (!refs || Array.isArray(refs) || typeof refs !== "object" || Object.keys(refs).length > 89) return {}
    if (!Object.entries(refs).every(([topic, id]) => /^[1-9][0-9]{0,8}$/.test(topic) && Number.isSafeInteger(id) && id > 0)) return {}
    return refs
  } catch { return {} }
}

/** Stable request key: editing row text must not refetch immutable imports. */
export function dmaReferencedImportIds(references = {}) {
  return [...new Set(Object.values(references))].filter(id => Number.isSafeInteger(id) && id > 0).sort((a, b) => a - b)
}

/** Full owned imports restore editors even when current P8 evidence refers to another source. */
export function dmaReviewRows(sources) {
  const rows = new Map()
  for (const source of sources) for (const row of source.iro_rows) {
    if (row.iro_id) rows.set(`${source.import_id}|${row.iro_id}`, { importId: source.import_id, row })
  }
  return [...rows.values()]
}

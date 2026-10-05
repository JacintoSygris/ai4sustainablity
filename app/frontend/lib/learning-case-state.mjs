export function learningCaseConflict(state) {
  return { ...state, needsReload: true, requiresReview: true, replay: false }
}

export function learningCaseRead(state, server, hydrate) {
  const draft = hydrate && server.draft ? server.draft : state.draft
  const bound = typeof draft.source_token === 'string' && draft.source_token.length > 0 && draft.source_token === server.source_token
  return { ...state, draft, needsReload: false, requiresReview: state.requiresReview || !bound }
}

export function learningCaseCanSubmit(state, server, action) {
  return !!server?.can_close && !state.needsReload && !state.requiresReview
    && !!server.source_token && state.draft.source_token === server.source_token
    && !!state.draft.idempotency_key
    && (action === 'save' || (state.draft.reviewed_universe === true && state.draft.final_for_period_scope === true))
}

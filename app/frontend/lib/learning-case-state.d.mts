export function learningCaseConflict<T extends { draft: unknown }>(state: T): T & { needsReload: true; requiresReview: true; replay: false }

export function learningCaseRead<T extends { draft: { source_token?: string | null }; requiresReview: boolean }>(state: T, server: { source_token: string | null; draft: T['draft'] | null }, hydrate: boolean): T & { needsReload: false; requiresReview: boolean }
export function learningCaseCanSubmit(state: { draft: { source_token?: string | null; idempotency_key: string; reviewed_universe: boolean; final_for_period_scope: boolean }; needsReload: boolean; requiresReview: boolean }, server: { can_close: boolean; source_token: string | null } | null, action: 'save' | 'close'): boolean

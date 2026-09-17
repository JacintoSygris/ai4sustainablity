export const REVIEW_ACTIONS = ["accepted", "rejected", "unsure"]

export const REVIEW_REASON_KEYS = [
  "sector_fit",
  "not_relevant",
  "threshold",
  "stakeholder_input",
  "needs_adm",
  "other",
]

const reviewActionSet = new Set(REVIEW_ACTIONS)
const reasonKeySet = new Set(REVIEW_REASON_KEYS)

function canonicalTopicKey(value) {
  const key = String(value)

  return /^[1-9][0-9]*$/.test(key) ? key : null
}

function proposalTopicKeys(proposalTopicIds) {
  return proposalTopicIds.map(canonicalTopicKey).filter(Boolean)
}

function currentTopicKeySet(proposalTopicIds) {
  return new Set(proposalTopicKeys(proposalTopicIds))
}

export function actionsFromProposal(proposal) {
  const currentKeys = currentTopicKeySet(proposal.proposal_topic_ids ?? [])
  const actions = proposal.review?.topic_actions ?? {}

  return Object.fromEntries(
    Object.entries(actions)
      .map(([topicId, action]) => [canonicalTopicKey(topicId), action])
      .filter(([topicId, action]) => topicId && currentKeys.has(topicId) && reviewActionSet.has(action)),
  )
}

export function reasonsFromProposal(proposal) {
  return cleanReasons(proposal.review?.action_reasons ?? {}, currentTopicKeySet(proposal.proposal_topic_ids ?? []))
}

export function notesFromProposal(proposal) {
  const currentKeys = currentTopicKeySet(proposal.proposal_topic_ids ?? [])

  return Object.fromEntries(
    Object.entries(proposal.review?.action_notes ?? {})
      .map(([topicId, note]) => [canonicalTopicKey(topicId), typeof note === "string" ? note : ""])
      .filter(([topicId]) => topicId && currentKeys.has(topicId)),
  )
}

export function cleanNotes(notes, validTopicKeys = null) {
  return Object.fromEntries(
    Object.entries(notes ?? {})
      .map(([topicId, note]) => [canonicalTopicKey(topicId), typeof note === "string" ? note.trim() : ""])
      .filter(([topicId, note]) => topicId && note && (!validTopicKeys || validTopicKeys.has(topicId))),
  )
}

export function cleanReasons(reasons, validTopicKeys = null) {
  return Object.fromEntries(
    Object.entries(reasons ?? {})
      .map(([topicId, selectedReasons]) => {
        const canonicalKey = canonicalTopicKey(topicId)
        const cleanSelectedReasons = Array.isArray(selectedReasons)
          ? selectedReasons.filter((reason) => reasonKeySet.has(reason))
          : []

        return [canonicalKey, Array.from(new Set(cleanSelectedReasons))]
      })
      .filter(
        ([topicId, selectedReasons]) =>
          topicId && selectedReasons.length > 0 && (!validTopicKeys || validTopicKeys.has(topicId)),
      ),
  )
}

export function missingReviewTopicIds(proposalTopicIds, topicActions) {
  const actions = Object.fromEntries(
    Object.entries(topicActions ?? {})
      .map(([topicId, action]) => [canonicalTopicKey(topicId), action])
      .filter(([topicId, action]) => topicId && reviewActionSet.has(action)),
  )

  return proposalTopicKeys(proposalTopicIds).filter((topicId) => !actions[topicId])
}

export function buildReviewPayload({ proposalTopicIds, topicActions, actionReasons = {}, actionNotes = {} }) {
  const validTopicKeys = currentTopicKeySet(proposalTopicIds ?? [])
  const cleanActions = Object.fromEntries(
    Object.entries(topicActions ?? {})
      .map(([topicId, action]) => [canonicalTopicKey(topicId), action])
      .filter(([topicId, action]) => topicId && validTopicKeys.has(topicId) && reviewActionSet.has(action)),
  )
  const missingTopicIds = missingReviewTopicIds(proposalTopicIds ?? [], cleanActions)

  if (missingTopicIds.length > 0) {
    return {
      ok: false,
      missingTopicIds,
    }
  }

  return {
    ok: true,
    payload: {
      topic_actions: cleanActions,
      action_reasons: cleanReasons(actionReasons, validTopicKeys),
      action_notes: cleanNotes(actionNotes, validTopicKeys),
    },
  }
}

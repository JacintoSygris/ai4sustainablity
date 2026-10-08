import { validateGuidedAnswer } from "./materiality-guided-state.mjs"

export const CHANGE_REASON_OPTIONS = [
  { key: "new_data", label: "Nuevos datos" },
  { key: "stakeholders", label: "Aportación de grupos de interés" },
  { key: "scope_change", label: "Cambio de alcance" },
  { key: "threshold", label: "No supera el umbral de importancia" },
  { key: "sector_requirement", label: "Requisito sectorial" },
  { key: "other", label: "Otro" },
]

const changeReasonKeys = new Set(CHANGE_REASON_OPTIONS.map((reason) => reason.key))

const DIMENSION_VALUES = new Set(["impact", "financial", "both"])

export function localized(value, locale = "es") {
  return value?.[locale === "en" ? "en" : "es"] || ""
}

export function topicTitle(topic, locale = "es") {
  return localized(topic?.subtopic, locale) || localized(topic?.subtheme, locale) || localized(topic?.theme, locale) || `${locale === "en" ? "Topic" : "Tema"} ${topic?.id ?? ""}`
}

export function topicSubtitle(topic, locale = "es") {
  return [localized(topic?.theme, locale), localized(topic?.subtheme, locale)].filter(Boolean).join(" / ")
}

export function topicMatches(topic, query, locale = "es") {
  const normalizedQuery = query.trim().toLowerCase()

  if (!normalizedQuery) {
    return true
  }

  return [topic.esrs_code, topicTitle(topic, locale), localized(topic.theme, locale), localized(topic.subtheme, locale), localized(topic.subtopic, locale)]
    .join(" ")
    .toLowerCase()
    .includes(normalizedQuery)
}

export function sortTopics(topics) {
  return [...topics].sort((left, right) => {
    const codeCompare = left.esrs_code.localeCompare(right.esrs_code)

    return codeCompare === 0 ? left.id - right.id : codeCompare
  })
}

export function topicSelectionKey(topicIds) {
  const key = Array.from(topicIdSet(topicIds)).sort((left, right) => left - right).join(",")
  return key || "empty"
}

export function createPreviewToken(topicIds, generation) {
  return {
    selection_key: topicSelectionKey(topicIds),
    generation: Number.isInteger(generation) && generation >= 0 ? generation : 0,
  }
}

export function previewTokenIsCurrent(token, topicIds, generation) {
  return token?.selection_key === topicSelectionKey(topicIds)
    && token?.generation === generation
}

function topicIdSet(topicIds) {
  return new Set((Array.isArray(topicIds) ? topicIds : Array.from(topicIds ?? [])).map(Number).filter((id) => id > 0))
}

function strictTopicIds(topicIds) {
  if (!Array.isArray(topicIds) && !(topicIds instanceof Set)) return []

  return Array.from(new Set(Array.from(topicIds).filter((id) => Number.isInteger(id) && id > 0)))
}

function isCanonicalTopicIdKey(topicId) {
  return /^[1-9][0-9]*$/.test(String(topicId))
}

export function mergeReviewedTopicIds(...topicIdGroups) {
  const reviewed = []

  for (const group of topicIdGroups) {
    for (const topicId of strictTopicIds(group)) {
      if (!reviewed.includes(topicId)) reviewed.push(topicId)
    }
  }

  return reviewed
}

/**
 * Explicit recovery keeps the recovered final selection (user intent), but never
 * treats missing evidence keys as deletions. Per-key precedence is server < live
 * local < recovered; only an explicitly present key replaces earlier evidence.
 * Guided answers merge per field too, preserving an unobserved author note.
 * Guided verdicts inconsistent with that final selection are retired, not turned
 * into negative answers. Recovery always requires a new universe attestation.
 * @param {{serverDraft: any, currentDraft: any, recoveredDraft: any, recoveredReviewedTopicIds?: number[]}} options
 */
export function mergeMaterialityRecoveryState({ serverDraft, currentDraft, recoveredDraft, recoveredReviewedTopicIds = [] }) {
  const drafts = [serverDraft, currentDraft, recoveredDraft]
  const evidenceFields = ["change_reasons", "change_notes", "dimensions", "guided_answers"]
  const evidence = Object.fromEntries(evidenceFields.map(field => [field,
    Object.assign({}, ...drafts.map(draft => draft?.[field] ?? {})),
  ]))
  const guidedAnswers = {}
  for (const draft of drafts) {
    for (const [topicId, answer] of Object.entries(draft?.guided_answers ?? {})) {
      if (!isCanonicalTopicIdKey(topicId) || !answer || typeof answer !== "object" || Array.isArray(answer)) continue
      guidedAnswers[topicId] = { ...guidedAnswers[topicId], ...answer }
    }
  }
  const evidenceIds = drafts.flatMap(draft => evidenceFields.flatMap(field => (
    Object.keys(draft?.[field] ?? {}).filter(isCanonicalTopicIdKey).map(Number)
  )))
  const reviewed = mergeReviewedTopicIds(
    ...drafts.flatMap(draft => [draft?.reviewed_topic_ids, draft?.p6_topic_ids, draft?.selected_topic_ids]),
    recoveredReviewedTopicIds,
    evidenceIds,
  )
  return attachLearningTopicDraftState({
    ...recoveredDraft,
    ...evidence,
    guided_answers: consistentGuidedAnswers(guidedAnswers, recoveredDraft.selected_topic_ids),
  }, { reviewedTopicIds: reviewed, reviewedUniverse: false })
}

export function guidedUniverseIsComplete({ reviewedTopicIds, selectedTopicIds, guidedAnswers }) {
  const reviewed = strictTopicIds(reviewedTopicIds)
  const selected = new Set(strictTopicIds(selectedTopicIds))
  const answers = guidedAnswers && typeof guidedAnswers === "object" && !Array.isArray(guidedAnswers)
    ? guidedAnswers
    : {}
  const answerKeys = Object.keys(answers)

  if (answerKeys.length !== reviewed.length) return false

  const reviewedKeys = new Set(reviewed.map(String))
  if (answerKeys.some((topicId) => !/^[1-9][0-9]*$/.test(topicId) || !reviewedKeys.has(topicId))) return false

  return reviewed.every((topicId) => {
    const answer = answers[String(topicId)]
    if (!validateGuidedAnswer(answer)) return false
    if (answer.impacto === "no_lo_se" || answer.financiero === "no_lo_se") return false
    if (answer.suggested_result === "en_observacion") return false

    return answer.final_result === (selected.has(topicId) ? "material" : "no_material")
  })
}

function guidedDimension(answer) {
  const impactHigh = answer?.impacto === "medio" || answer?.impacto === "alto"
  const financialHigh = answer?.financiero === "medio" || answer?.financiero === "alto"
  if (impactHigh && financialHigh) return "both"
  if (impactHigh) return "impact"
  if (financialHigh) return "financial"
  return null
}

export function mergeMaterialityDimensions(storedDimensions, guidedAnswers) {
  const merged = Object.fromEntries(
    Object.entries(storedDimensions ?? {}).filter(([topicId, dimension]) => (
      isCanonicalTopicIdKey(topicId) && DIMENSION_VALUES.has(dimension)
    )),
  )

  for (const [topicId, answer] of Object.entries(guidedAnswers ?? {})) {
    if (!isCanonicalTopicIdKey(topicId)) continue
    const derived = guidedDimension(answer)
    if (derived) merged[topicId] = derived
  }

  return merged
}

export function attachLearningTopicDraftState(draft, { reviewedTopicIds, reviewedUniverse }) {
  return {
    ...draft,
    reviewed_topic_ids: strictTopicIds(reviewedTopicIds).sort((left, right) => left - right),
    reviewed_universe: reviewedUniverse === true,
  }
}

export function restoreLearningTopicDraftState(raw, { baseRevision, p6TopicIds }) {
  let parsed
  try {
    parsed = typeof raw === "string" ? JSON.parse(raw) : raw
  } catch {
    return null
  }

  if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) return null
  if (parsed.base_revision !== baseRevision) return null

  const storedP6TopicIds = strictTopicIds(parsed.p6_topic_ids).sort((left, right) => left - right)
  const expectedP6TopicIds = strictTopicIds(p6TopicIds).sort((left, right) => left - right)
  if (JSON.stringify(storedP6TopicIds) !== JSON.stringify(expectedP6TopicIds)) return null
  if (!Array.isArray(parsed.reviewed_topic_ids) || typeof parsed.reviewed_universe !== "boolean") return null

  const reviewedTopicIds = strictTopicIds(parsed.reviewed_topic_ids).sort((left, right) => left - right)
  if (reviewedTopicIds.length !== parsed.reviewed_topic_ids.length) return null

  return {
    reviewed_topic_ids: reviewedTopicIds,
    reviewed_universe: parsed.reviewed_universe,
  }
}

export function resolveLearningTopicHydration({
  hasLocalDraft,
  localLearningState,
  serverReviewedTopicIds,
  p6TopicIds,
  serverAttestation,
  serverLearningTopicLabels,
  isStale,
}) {
  const serverReviewed = mergeReviewedTopicIds(serverReviewedTopicIds, p6TopicIds)
  const localIds = localLearningState?.reviewed_topic_ids
  const validLocalState = localLearningState
    && typeof localLearningState === "object"
    && !Array.isArray(localLearningState)
    && Array.isArray(localIds)
    && typeof localLearningState.reviewed_universe === "boolean"
    && strictTopicIds(localIds).length === localIds.length

  if (hasLocalDraft) {
    const localReviewed = validLocalState ? strictTopicIds(localIds) : []
    const localIncludesServer = validLocalState === true
      && serverReviewed.every((topicId) => localReviewed.includes(topicId))

    return {
      reviewed_topic_ids: validLocalState
        ? mergeReviewedTopicIds(serverReviewed, localReviewed, p6TopicIds)
        : serverReviewed,
      reviewed_universe: validLocalState === true
        && localIncludesServer
        && localLearningState.reviewed_universe === true,
    }
  }

  const attestationKeys = serverAttestation && typeof serverAttestation === "object" && !Array.isArray(serverAttestation)
    ? Object.keys(serverAttestation).sort()
    : []
  const validAttestation = JSON.stringify(attestationKeys) === JSON.stringify(["mode", "reviewed_universe", "version"])
    && serverAttestation.version === 1
    && serverAttestation.reviewed_universe === true
    && (serverAttestation.mode === "direct" || serverAttestation.mode === "guided")
  const labelKeys = serverLearningTopicLabels && typeof serverLearningTopicLabels === "object" && !Array.isArray(serverLearningTopicLabels)
    ? Object.keys(serverLearningTopicLabels)
    : []
  const reviewedKeys = serverReviewed.map(String)
  const validLabels = labelKeys.length === reviewedKeys.length
    && labelKeys.every((topicId) => reviewedKeys.includes(topicId)
      && (serverLearningTopicLabels[topicId] === 0 || serverLearningTopicLabels[topicId] === 1))

  return {
    reviewed_topic_ids: serverReviewed,
    reviewed_universe: isStale !== true && validAttestation && validLabels,
  }
}

export function changedTopicIds(p6TopicIds, selectedTopicIds) {
  const p6 = topicIdSet(p6TopicIds)
  const selected = topicIdSet(selectedTopicIds)
  const changed = new Set()

  for (const topicId of p6) {
    if (!selected.has(topicId)) {
      changed.add(topicId)
    }
  }

  for (const topicId of selected) {
    if (!p6.has(topicId)) {
      changed.add(topicId)
    }
  }

  return Array.from(changed).sort((left, right) => left - right)
}

export function selectedTopicIdsForGuidedAnswer(selectedTopicIds, topicId, answer) {
  const selected = topicIdSet(selectedTopicIds)
  const normalizedTopicId = Number(topicId)

  if (normalizedTopicId <= 0) {
    return selected
  }

  if (answer?.final_result === "material") {
    selected.add(normalizedTopicId)
  } else if (answer?.final_result === "no_material") {
    selected.delete(normalizedTopicId)
  }

  return selected
}

export function guidedReviewTopicIds({ mode = "guided", reviewedTopicIds, selectedTopicIds, guidedAnswers }) {
  const selected = Array.from(selectedTopicIds).map(Number).filter(Number.isInteger)
  if (mode === "direct") {
    const selectedSet = new Set(selected)

    return {
      materialIds: [...selected].sort((a, b) => a - b),
      observationIds: [],
      noMaterialIds: strictTopicIds(reviewedTopicIds)
        .filter((topicId) => !selectedSet.has(topicId))
        .sort((a, b) => a - b),
    }
  }

  const noMaterialIds = Object.entries(guidedAnswers || {})
    .filter(([, answer]) => answer?.final_result === "no_material")
    .map(([topicId]) => Number(topicId))
    .filter(Number.isInteger)
    .sort((a, b) => a - b)
  const noMaterial = new Set(noMaterialIds)
  const observationIds = selected
    .filter((topicId) => {
      const answer = guidedAnswers?.[String(topicId)]
      return !noMaterial.has(topicId) && answer?.suggested_result === "en_observacion"
    })
    .sort((a, b) => a - b)
  const observation = new Set(observationIds)
  const materialIds = selected
    .filter((topicId) => !noMaterial.has(topicId) && !observation.has(topicId))
    .sort((a, b) => a - b)

  return { materialIds, observationIds, noMaterialIds }
}

export function applyDirectTopicDecision(selectedTopicIds, guidedAnswers, topicId, selected) {
  const nextSelected = topicIdSet(selectedTopicIds)
  const normalizedTopicId = Number(topicId)

  if (normalizedTopicId > 0) {
    if (selected) nextSelected.add(normalizedTopicId)
    else nextSelected.delete(normalizedTopicId)
  }

  const nextGuidedAnswers = { ...(guidedAnswers ?? {}) }
  delete nextGuidedAnswers[String(normalizedTopicId)]

  return { selectedTopicIds: nextSelected, guidedAnswers: nextGuidedAnswers }
}

export function consistentGuidedAnswers(guidedAnswers, selectedTopicIds) {
  const selected = topicIdSet(selectedTopicIds)

  return Object.fromEntries(
    Object.entries(guidedAnswers ?? {}).filter(([topicId, answer]) => {
      if (!isCanonicalTopicIdKey(topicId)) return false
      if (!validateGuidedAnswer(answer)) return false

      const isSelected = selected.has(Number(topicId))
      return answer.final_result === "material" ? isSelected : !isSelected
    }),
  )
}

export function cleanNotes(notes, validTopicIds) {
  const validIds = topicIdSet(validTopicIds)

  return Object.fromEntries(
    Object.entries(notes ?? {})
      .map(([topicId, note]) => [topicId, typeof note === "string" ? note.trim() : ""])
      .filter(([topicId, note]) => note && isCanonicalTopicIdKey(topicId) && validIds.has(Number(topicId))),
  )
}

export function cleanReasons(reasons, validTopicIds) {
  const validIds = topicIdSet(validTopicIds)

  return Object.fromEntries(
    Object.entries(reasons ?? {})
      .map(([topicId, selectedReasons]) => [
        topicId,
        Array.from(new Set(Array.isArray(selectedReasons) ? selectedReasons.filter((reason) => changeReasonKeys.has(reason)) : [])),
      ])
      .filter(([topicId, selectedReasons]) => selectedReasons.length > 0 && isCanonicalTopicIdKey(topicId) && validIds.has(Number(topicId))),
  )
}

export function removesE1FromTopics({ topics, p6TopicIds, selectedTopicIds }) {
  const p6 = topicIdSet(p6TopicIds)
  const selected = topicIdSet(selectedTopicIds)
  const p6HasE1 = topics.some((topic) => p6.has(topic.id) && topic.esrs_code === "E1")
  const selectedHasE1 = topics.some((topic) => selected.has(topic.id) && topic.esrs_code === "E1")

  return p6HasE1 && !selectedHasE1
}

/**
 * @param {{
 *   selectedTopicIds: Iterable<number> | number[],
 *   p6TopicIds?: number[],
 *   changeReasons?: Record<string, string[]>,
 *   changeNotes?: Record<string, string>,
 *   e1Explanation?: string,
 *   dimensions?: Record<string, string> | undefined,
 *   guidedAnswers?: Record<string, object> | undefined,
 *   reviewedTopicIds?: number[] | Set<number> | undefined,
 *   reviewedUniverse?: boolean | undefined,
 *   mode?: "direct" | "guided" | undefined,
 * }} options
 */
export function buildMaterialityConfirmationPayload({
  selectedTopicIds,
  p6TopicIds,
  changeReasons = {},
  changeNotes = {},
  e1Explanation = "",
  dimensions = undefined,
  guidedAnswers = undefined,
  reviewedTopicIds = undefined,
  reviewedUniverse = false,
  mode = "direct",
}) {
  const selected = Array.isArray(selectedTopicIds) ? selectedTopicIds.map(Number) : Array.from(selectedTopicIds ?? []).map(Number)
  const changedIds = changedTopicIds(p6TopicIds ?? [], selected)
  const hasReviewedUniverse = reviewedTopicIds !== undefined
  const reviewed = hasReviewedUniverse
    ? mergeReviewedTopicIds(reviewedTopicIds, p6TopicIds ?? [], selected)
    : []
  const validEvidenceIds = hasReviewedUniverse ? reviewed : changedIds
  const trimmedExplanation = typeof e1Explanation === "string" ? e1Explanation.trim() : ""

  const payload = {
    confirmed_topic_ids: selected.filter((topicId) => topicId > 0),
    change_reasons: cleanReasons(changeReasons, validEvidenceIds),
    change_reason_notes: cleanNotes(changeNotes, validEvidenceIds),
    e1_not_material_explanation: trimmedExplanation || null,
  }

  if (hasReviewedUniverse) {
    const normalizedMode = mode === "guided" ? "guided" : "direct"
    const guidedComplete = normalizedMode === "guided"
      ? guidedUniverseIsComplete({
          reviewedTopicIds: reviewed,
          selectedTopicIds: selected,
          guidedAnswers,
        })
      : true

    payload.reviewed_topic_ids = reviewed
    payload.universe_attestation = {
      version: 1,
      reviewed_universe: reviewedUniverse === true && guidedComplete,
      mode: normalizedMode,
    }
  }

  // pass-through cleaned (per plan F3): dimensions and guided_answers only for valid
  if (dimensions && typeof dimensions === "object") {
    const cleanDims = {}
    for (const [k, v] of Object.entries(dimensions)) {
      const key = String(k)
      if (isCanonicalTopicIdKey(key)
        && DIMENSION_VALUES.has(v)
        && (!hasReviewedUniverse || reviewed.includes(Number(key)))) cleanDims[key] = v
    }
    if (Object.keys(cleanDims).length > 0) payload.dimensions = cleanDims
  }

  if (guidedAnswers && typeof guidedAnswers === "object") {
    const cleanGuided = consistentGuidedAnswers(guidedAnswers, selected)
    const reviewedGuided = hasReviewedUniverse
      ? Object.fromEntries(Object.entries(cleanGuided).filter(([topicId]) => reviewed.includes(Number(topicId))))
      : cleanGuided
    if (Object.keys(reviewedGuided).length > 0) payload.guided_answers = reviewedGuided
  }

  return payload
}

/**
 * @param {any} confirmation
 */
export function isStaleConfirmation(confirmation) {
  if (!confirmation) return false
  if (confirmation.is_stale === true) return true
  if (confirmation.is_stale === false) return false
  // tolerant of absence (legacy or pre-snapshot)
  return false
}

export const CHANGE_REASON_OPTIONS = [
  { key: "new_data", label: "Nuevos datos" },
  { key: "stakeholders", label: "Stakeholders" },
  { key: "scope_change", label: "Cambio de alcance" },
  { key: "threshold", label: "Umbral ADM" },
  { key: "sector_requirement", label: "Requisito sectorial" },
  { key: "other", label: "Otro" },
]

const changeReasonKeys = new Set(CHANGE_REASON_OPTIONS.map((reason) => reason.key))

export function localized(value) {
  return value?.es || value?.en || ""
}

export function topicTitle(topic) {
  return localized(topic?.subtopic) || localized(topic?.subtheme) || localized(topic?.theme) || `Tema ${topic?.id ?? ""}`
}

export function topicSubtitle(topic) {
  return [localized(topic?.theme), localized(topic?.subtheme)].filter(Boolean).join(" / ")
}

export function topicMatches(topic, query) {
  const normalizedQuery = query.trim().toLowerCase()

  if (!normalizedQuery) {
    return true
  }

  return [topic.esrs_code, topicTitle(topic), localized(topic.theme), localized(topic.subtheme), localized(topic.subtopic)]
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

function topicIdSet(topicIds) {
  return new Set((Array.isArray(topicIds) ? topicIds : Array.from(topicIds ?? [])).map(Number).filter((id) => id > 0))
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

export function cleanNotes(notes, validTopicIds) {
  const validIds = topicIdSet(validTopicIds)

  return Object.fromEntries(
    Object.entries(notes ?? {})
      .map(([topicId, note]) => [topicId, typeof note === "string" ? note.trim() : ""])
      .filter(([topicId, note]) => note && validIds.has(Number(topicId))),
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
      .filter(([topicId, selectedReasons]) => selectedReasons.length > 0 && validIds.has(Number(topicId))),
  )
}

export function removesE1FromTopics({ topics, p6TopicIds, selectedTopicIds }) {
  const p6 = topicIdSet(p6TopicIds)
  const selected = topicIdSet(selectedTopicIds)
  const p6HasE1 = topics.some((topic) => p6.has(topic.id) && topic.esrs_code === "E1")
  const selectedHasE1 = topics.some((topic) => selected.has(topic.id) && topic.esrs_code === "E1")

  return p6HasE1 && !selectedHasE1
}

export function buildMaterialityConfirmationPayload({
  selectedTopicIds,
  p6TopicIds,
  changeReasons = {},
  changeNotes = {},
  e1Explanation = "",
}) {
  const selected = Array.isArray(selectedTopicIds) ? selectedTopicIds.map(Number) : Array.from(selectedTopicIds ?? []).map(Number)
  const changedIds = changedTopicIds(p6TopicIds ?? [], selected)
  const trimmedExplanation = typeof e1Explanation === "string" ? e1Explanation.trim() : ""

  return {
    confirmed_topic_ids: selected.filter((topicId) => topicId > 0),
    change_reasons: cleanReasons(changeReasons, changedIds),
    change_reason_notes: cleanNotes(changeNotes, changedIds),
    e1_not_material_explanation: trimmedExplanation || null,
  }
}

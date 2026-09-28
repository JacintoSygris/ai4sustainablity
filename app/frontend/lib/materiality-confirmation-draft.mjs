const DRAFT_VERSION = 1

function isPlainObject(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value)
}

function positiveIntegerIds(values) {
  if (!Array.isArray(values) && !(values instanceof Set)) return []

  return Array.from(new Set(Array.from(values).filter((value) => Number.isInteger(value) && value > 0))).sort((a, b) => a - b)
}

function stringRecord(value) {
  if (!isPlainObject(value)) return {}

  return Object.fromEntries(
    Object.entries(value).filter(([key, entry]) => /^[1-9][0-9]*$/.test(key) && typeof entry === "string"),
  )
}

function stringArrayRecord(value) {
  if (!isPlainObject(value)) return {}

  return Object.fromEntries(
    Object.entries(value)
      .filter(([key, entry]) => /^[1-9][0-9]*$/.test(key) && Array.isArray(entry))
      .map(([key, entry]) => [key, Array.from(new Set(entry.filter((item) => typeof item === "string")))]),
  )
}

function objectRecord(value) {
  if (!isPlainObject(value)) return {}

  return Object.fromEntries(
    Object.entries(value).filter(([key, entry]) => /^[1-9][0-9]*$/.test(key) && isPlainObject(entry)),
  )
}

export function buildMaterialityConfirmationDraft({
  baseRevision,
  p6TopicIds,
  selectedTopicIds,
  changeReasons = {},
  changeNotes = {},
  e1Explanation = "",
  guidedAnswers = {},
  mode = "direct",
  tabId = "",
  requestId = 0,
}) {
  return {
    version: DRAFT_VERSION,
    base_revision: Number.isInteger(baseRevision) && baseRevision >= 0 ? baseRevision : 0,
    p6_topic_ids: positiveIntegerIds(p6TopicIds),
    selected_topic_ids: positiveIntegerIds(selectedTopicIds),
    change_reasons: stringArrayRecord(changeReasons),
    change_notes: stringRecord(changeNotes),
    e1_explanation: typeof e1Explanation === "string" ? e1Explanation : "",
    guided_answers: objectRecord(guidedAnswers),
    mode: mode === "guided" ? "guided" : "direct",
    tab_id: typeof tabId === "string" && /^[A-Za-z0-9_-]{1,128}$/.test(tabId) ? tabId : null,
    request_id: Number.isInteger(requestId) && requestId >= 0 ? requestId : 0,
  }
}

function parseMaterialityConfirmationDraft(raw) {
  let parsed = raw
  if (typeof raw === "string") {
    try {
      parsed = JSON.parse(raw)
    } catch {
      return null
    }
  }

  if (
    !isPlainObject(parsed)
    || parsed.version !== DRAFT_VERSION
    || !Number.isInteger(parsed.base_revision)
    || parsed.base_revision < 0
  ) return null

  return buildMaterialityConfirmationDraft({
    baseRevision: parsed.base_revision,
    p6TopicIds: parsed.p6_topic_ids,
    selectedTopicIds: parsed.selected_topic_ids,
    changeReasons: parsed.change_reasons,
    changeNotes: parsed.change_notes,
    e1Explanation: parsed.e1_explanation,
    guidedAnswers: parsed.guided_answers,
    mode: parsed.mode,
    tabId: parsed.tab_id,
    requestId: parsed.request_id,
  })
}

export function restoreMaterialityConfirmationDraft(raw, { baseRevision, p6TopicIds }) {
  const parsed = parseMaterialityConfirmationDraft(raw)
  if (!parsed || parsed.base_revision !== baseRevision) return null

  const expectedP6TopicIds = positiveIntegerIds(p6TopicIds)
  const storedP6TopicIds = positiveIntegerIds(parsed.p6_topic_ids)
  if (JSON.stringify(storedP6TopicIds) !== JSON.stringify(expectedP6TopicIds)) {
    return null
  }

  return parsed
}

export function quarantineMaterialityConfirmationDraft(raw, context) {
  const parsed = parseMaterialityConfirmationDraft(raw)
  if (!parsed || restoreMaterialityConfirmationDraft(JSON.stringify(parsed), context)) return null
  return parsed
}

export function rebaseMaterialityConfirmationDraft(raw, { baseRevision, p6TopicIds }) {
  const parsed = parseMaterialityConfirmationDraft(raw)
  if (!parsed) return null

  return buildMaterialityConfirmationDraft({
    baseRevision,
    p6TopicIds,
    selectedTopicIds: parsed.selected_topic_ids,
    changeReasons: parsed.change_reasons,
    changeNotes: parsed.change_notes,
    e1Explanation: parsed.e1_explanation,
    guidedAnswers: parsed.guided_answers,
    mode: parsed.mode,
    tabId: parsed.tab_id,
    requestId: parsed.request_id,
  })
}

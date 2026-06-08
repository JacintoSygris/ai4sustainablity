export const P9_EXPORT_LINKS = [
  { key: "corpus", label: "Corpus CSV", path: "/esrs-datapoints/export.csv" },
  { key: "responses", label: "Respuestas CSV", path: "/esrs-datapoints/responses/export.csv" },
]

export const RESPONSE_STATUS_LABELS = {
  draft: "Borrador",
  completed: "Completado",
  not_applicable: "No aplica",
}

export function flattenCorpus(corpus) {
  if (!corpus) {
    return []
  }

  return Object.values(corpus.blocks ?? {}).flatMap((block) =>
    (block.datapoints ?? []).map((datapoint) => ({
      blockKey: block.key ?? "",
      blockTitle: block.title ?? block.key ?? "Bloque P9",
      datapoint,
    })),
  )
}

export function emptyDraft() {
  return {
    evidence_reference: "",
    note: "",
    status: "draft",
    value: "",
  }
}

export function compactDrafts(drafts) {
  return Object.entries(drafts ?? {})
    .map(([datapoint_id, draft]) => ({
      datapoint_id,
      evidence_reference: trimOptional(draft?.evidence_reference),
      note: trimOptional(draft?.note),
      status: draft?.status ?? "draft",
      value: trimOptional(draft?.value),
    }))
    .filter(
      (draft) =>
        draft.status !== "draft" || Boolean(draft.value) || Boolean(draft.evidence_reference) || Boolean(draft.note),
    )
}

export function responseLabel(status) {
  return RESPONSE_STATUS_LABELS[status] ?? status
}

export function p9ExportLinks() {
  return P9_EXPORT_LINKS
}

export function p9MappingSummary(corpus) {
  const generation = objectValue(corpus?.generation)
  const matterMapping = objectValue(corpus?.matter_mapping)
  const limitations = [
    ...arrayValue(generation.limitations),
    matterMapping.limitation,
  ].filter((value, index, values) => typeof value === "string" && value.trim() && values.indexOf(value) === index)

  return {
    coverageStatus: stringValue(matterMapping.coverage_status ?? generation.coverage_status),
    currentFilter: stringValue(matterMapping.current_filter),
    mappingGranularity: stringValue(generation.mapping_granularity),
    mappingStatus: stringValue(matterMapping.status ?? generation.matter_to_dr_mapping_status),
    limitations,
  }
}

export function phaseInSummary(corpus) {
  const assessment = objectValue(corpus?.phase_in_assessment)
  const counts = objectValue(assessment.counts)
  const employeeCount = objectValue(assessment.employee_count)

  return {
    status: stringValue(assessment.status),
    source: stringValue(employeeCount.source),
    estimate: employeeCount.estimate ?? null,
    lessThan750: employeeCount.less_than_750,
    lessThan750ReliefCount: numberValue(counts.less_than_750_relief_datapoint_count),
    allUndertakingsCount: numberValue(counts.all_undertakings_phase_in_datapoint_count),
    applicablePhaseInCount: numberValue(counts.applicable_phase_in_datapoint_count),
    note: stringValue(assessment.note),
  }
}

export function completionPlanItems(corpus) {
  const phases = arrayValue(objectValue(corpus?.completion_plan).phases)

  return phases.map((phase) => ({
    key: stringValue(phase?.key),
    title: stringValue(phase?.title),
    status: stringValue(phase?.status),
    datapointCount: numberValue(phase?.datapoint_count),
  }))
}

export function datapointApplicabilitySummary(datapoint) {
  const applicability = objectValue(datapoint?.applicability)
  const phaseIn = objectValue(datapoint?.phase_in)

  return {
    reason: stringValue(applicability.reason),
    reasonCode: stringValue(applicability.reason_code),
    mappingBasis: stringValue(applicability.mapping_basis),
    limitations: arrayValue(applicability.limitations).filter((limitation) => typeof limitation === "string" && limitation.trim()),
    phaseInLessThan750: stringValue(phaseIn.less_than_750),
    phaseInAllUndertakings: stringValue(phaseIn.all_undertakings),
  }
}

function trimOptional(value) {
  return typeof value === "string" ? value.trim() || undefined : undefined
}

function objectValue(value) {
  return value && typeof value === "object" && !Array.isArray(value) ? value : {}
}

function arrayValue(value) {
  return Array.isArray(value) ? value : []
}

function stringValue(value) {
  return typeof value === "string" ? value : ""
}

function numberValue(value) {
  return typeof value === "number" && Number.isFinite(value) ? value : 0
}

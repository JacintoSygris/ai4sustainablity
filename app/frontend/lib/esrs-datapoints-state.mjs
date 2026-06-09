export const P9_EXPORT_LINKS = [
  { key: "corpus", label: "Corpus CSV", path: "/esrs-datapoints/export.csv" },
  { key: "responses", label: "Respuestas CSV", path: "/esrs-datapoints/responses/export.csv" },
]

export const RESPONSE_STATUS_LABELS = {
  draft: "Borrador",
  completed: "Completado",
  not_applicable: "No aplica",
}

export const P9_MAPPING_STATUS_LABELS = {
  loaded: "Mapa aprobado cargado",
  partial: "Mapa incompleto",
  pending: "Mapa pendiente",
}

export const P9_COVERAGE_STATUS_LABELS = {
  dr_level: "Filtrado por Disclosure Requirement",
  topical_mapping_required: "Falta mapa AR16 a DR",
  standard_level_partial: "Cobertura parcial por estándar",
}

export const P9_FILTER_LABELS = {
  mapped_disclosure_requirements: "Disclosure Requirements mapeados",
  topical_blocked_until_dr_mapping: "Bloqueado hasta mapear AR16 a DR",
  activated_esrs_standard: "Estándar ESRS activado",
}

export const P9_GRANULARITY_LABELS = {
  disclosure_requirement_level: "Nivel Disclosure Requirement",
  disclosure_requirement_mapping_required: "Requiere mapa a Disclosure Requirement",
  standard_level: "Nivel estándar",
}

export const COMPLETION_STATUS_LABELS = {
  blocked: "Bloqueado",
  conditional: "Condicional",
  not_applicable: "No aplica",
  ready: "Listo",
  satisfied: "Satisfecho",
}

export const APPLICABILITY_MAPPING_BASIS_LABELS = {
  always_required: "Siempre requerido",
  conditional_mdr_for_material_topics: "MDR condicional por temas materiales",
  mapped_disclosure_requirements: "Disclosure Requirement mapeado",
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
  const coverageStatus = stringValue(matterMapping.coverage_status ?? generation.coverage_status)
  const mappingStatus = stringValue(matterMapping.status ?? generation.matter_to_dr_mapping_status)
  const limitations = [
    ...arrayValue(generation.limitations),
    matterMapping.limitation,
  ].filter((value, index, values) => typeof value === "string" && value.trim() && values.indexOf(value) === index)

  return {
    coverageStatus,
    coverageStatusLabel: labelFor(P9_COVERAGE_STATUS_LABELS, coverageStatus),
    currentFilter: stringValue(matterMapping.current_filter),
    currentFilterLabel: labelFor(P9_FILTER_LABELS, matterMapping.current_filter),
    mappingGranularity: stringValue(generation.mapping_granularity),
    mappingGranularityLabel: labelFor(P9_GRANULARITY_LABELS, generation.mapping_granularity),
    mappingStatus,
    mappingStatusLabel: labelFor(P9_MAPPING_STATUS_LABELS, mappingStatus),
    limitations: localizeMappingLimitations(limitations, coverageStatus, mappingStatus),
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
    statusLabel: labelFor(COMPLETION_STATUS_LABELS, phase?.status),
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
    mappingBasisLabel: labelFor(APPLICABILITY_MAPPING_BASIS_LABELS, applicability.mapping_basis),
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

function labelFor(labels, value) {
  const key = stringValue(value)

  return key ? labels[key] ?? key : ""
}

function localizeMappingLimitations(limitations, coverageStatus, mappingStatus) {
  if (coverageStatus !== "topical_mapping_required") {
    return limitations
  }

  if (mappingStatus === "partial") {
    return [
      "El mapa AR16 a DR configurado está incompleto o no es válido para todos los temas confirmados. P9 bloquea los datapoints tópicos hasta corregirlo.",
    ]
  }

  return [
    "Falta el mapa aprobado AR16 a DR. P9 no incluirá datapoints tópicos para evitar convertir un tema material en todo el estándar ESRS.",
  ]
}

export const P9_EXPORT_LINKS = [
  { key: "corpus", label: "Lista de información CSV", path: "/esrs-datapoints/export.csv" },
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
      blockTitle: block.title ?? block.key ?? "Bloque de información",
      datapoint,
    })),
  )
}

export function emptyDraft() {
  return {
    evidence_reference: "",
    facts: [],
    note: "",
    status: "draft",
    value: "",
  }
}

export function compactDrafts(drafts) {
  return Object.entries(drafts ?? {})
    .map(([datapoint_id, draft]) => {
      const base = {
        datapoint_id,
        evidence_reference: trimOptional(draft?.evidence_reference),
        facts: compactFacts(draft?.facts),
        note: trimOptional(draft?.note),
        status: draft?.status ?? "draft",
        value: trimOptional(draft?.value),
      }
      if (draft?.triage) {
        base.triage = draft.triage
      }
      return base
    })
    .filter((draft) => {
      const hasContent =
        draft.status !== "draft" ||
        Boolean(draft.value) ||
        Boolean(draft.evidence_reference) ||
        Boolean(draft.note) ||
        draft.facts.length > 0
      const hasTriageOnly = draft.triage && !hasContent && draft.status === "draft"
      return hasContent || hasTriageOnly
    })
}

export function createDefaultFact(valueKind = "string") {
  const numeric = ["integer", "decimal", "monetary", "percent"].includes(valueKind)
  return {
    value_kind: valueKind,
    value: valueKind === "boolean" ? false : "",
    decimals: numeric ? 0 : null,
    unit: valueKind === "monetary"
      ? { measure: "iso4217:EUR" }
      : valueKind === "percent"
        ? { measure: "pure" }
        : valueKind === "decimal"
          ? { measure: "pure" }
          : null,
    context: {
      period_type: "duration",
      start_date: "",
      end_date: "",
      instant_date: "",
      dimensions: [],
    },
    evidence_reference: "",
  }
}

export function factKindRequiresDecimals(valueKind) {
  return ["integer", "decimal", "monetary", "percent"].includes(valueKind)
}

export function factKindRequiresUnit(valueKind) {
  return ["decimal", "monetary", "percent"].includes(valueKind)
}

export function factKindUnitPlaceholder(valueKind) {
  if (valueKind === "monetary") return "iso4217:EUR"
  if (valueKind === "percent") return "pure"
  if (valueKind === "decimal") return "pure"
  return ""
}

function compactFacts(facts) {
  if (!Array.isArray(facts)) return []
  return facts
    .map((fact) => {
      const kind = fact?.value_kind || "string"
      const numeric = factKindRequiresDecimals(kind)
      const unitMeasure = trimOptional(fact?.unit?.measure)
      const periodType = fact?.context?.period_type === "instant" ? "instant" : "duration"
      const dimensions = Array.isArray(fact?.context?.dimensions)
        ? fact.context.dimensions
            .map((dimension) => ({
              axis: trimOptional(dimension?.axis),
              member: trimOptional(dimension?.member),
            }))
            .filter((dimension) => dimension.axis && dimension.member)
            .sort((a, b) => a.axis.localeCompare(b.axis))
        : []
      return {
        ...(fact?.fact_id ? { fact_id: fact.fact_id } : {}),
        value_kind: kind,
        value: kind === "boolean" ? Boolean(fact?.value) : trimOptional(fact?.value) ?? "",
        decimals: numeric && Number.isInteger(fact?.decimals) ? fact.decimals : numeric ? 0 : null,
        unit: factKindRequiresUnit(kind) ? { measure: unitMeasure ?? factKindUnitPlaceholder(kind) } : null,
        context: {
          period_type: periodType,
          start_date: periodType === "duration" ? trimOptional(fact?.context?.start_date) ?? null : null,
          end_date: periodType === "duration" ? trimOptional(fact?.context?.end_date) ?? null : null,
          instant_date: periodType === "instant" ? trimOptional(fact?.context?.instant_date) ?? null : null,
          dimensions,
        },
        evidence_reference: trimOptional(fact?.evidence_reference) ?? "",
      }
    })
    .filter((fact) => Boolean(fact.value) || Boolean(fact.evidence_reference) || hasContextDates(fact) || fact.context.dimensions.length > 0)
}

function hasContextDates(fact) {
  return Boolean(fact?.context?.start_date || fact?.context?.end_date || fact?.context?.instant_date)
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
      "La correspondencia AR16 a requisito de divulgación configurada está incompleta o no es válida para todos los temas confirmados. La información temática queda bloqueada hasta corregirlo.",
    ]
  }

  return [
    "Falta una correspondencia AR16 a requisito de divulgación completa y válida. La información temática no se incluye para evitar convertir un tema material en todo el estándar ESRS.",
  ]
}

export const DEFAULT_OBLIGATION_FILTER = "mandatory_only"

export const TRIAGE_OPTIONS = {
  have_it: "Lo tengo",
  need_to_find: "Tengo que buscarlo",
  not_applicable_candidate: "Creo que no aplica",
}

/**
 * @param {Array<{datapoint?: any, standard?: string, may_disclose?: any, [key:string]:any}>} rows
 */
export function groupRowsByStandard(rows) {
  const list = Array.isArray(rows) ? rows : []
  const byStandard = new Map()

  for (const r of list) {
    const dp = r && r.datapoint ? r.datapoint : r
    const std = (dp && typeof dp.standard === "string" && dp.standard.trim()) ? dp.standard.trim() : "Otros"
    if (!byStandard.has(std)) byStandard.set(std, [])
    byStandard.get(std).push(r)
  }

  const orderedStandards = []
  if (byStandard.has("ESRS 2")) {
    orderedStandards.push("ESRS 2")
  }
  const others = Array.from(byStandard.keys()).filter((s) => s !== "ESRS 2").sort((a, b) => a.localeCompare(b))
  for (const s of others) orderedStandards.push(s)

  return orderedStandards.map((standard) => {
    const actualRows = byStandard.get(standard) || []
    const total = actualRows.length
    let decided = 0
    let mandatory = 0
    let voluntary = 0
    for (const r of actualRows) {
      const dp = r && r.datapoint ? r.datapoint : r
      if (isDefaultSelected(dp)) mandatory += 1
      else voluntary += 1
      if (dp && (dp._decided || (dp.response && dp.response.status && dp.response.status !== "draft"))) decided += 1
    }
    return {
      standard,
      rows: actualRows,
      counts: { total, decided, mandatory, voluntary },
    }
  })
}

/**
 * Backend-authoritative default selection: voluntary and permitted phase-in
 * deferrals arrive default-unselected. Falls back to may_disclose for
 * payloads without the selection block.
 * @param {any} datapoint
 */
function isDefaultSelected(datapoint) {
  const selection = datapoint && datapoint.selection
  if (selection && typeof selection === "object" && typeof selection.default_selected === "boolean") {
    return selection.default_selected
  }
  return !(datapoint && datapoint.may_disclose)
}

/**
 * @param {any} datapoint
 */
function selectionReasonCodes(datapoint) {
  const selection = datapoint && datapoint.selection
  return selection && Array.isArray(selection.reason_codes) ? selection.reason_codes : []
}

/**
 * @param {any} datapoint
 */
export function obligationBadge(datapoint) {
  const reasons = selectionReasonCodes(datapoint)
  const may = reasons.includes("voluntary_may_disclose") || !!(datapoint && datapoint.may_disclose)
  const deferred = reasons.includes("phase_in_less_than_750") || reasons.includes("phase_in_all_undertakings")
  const cond = datapoint && datapoint.conditional_or_alternative
  if (may) {
    return { kind: "voluntary", label: "Voluntario (opcional)" }
  }
  if (deferred) {
    return { kind: "deferred", label: "Aplazable (phase-in)" }
  }
  if (cond) {
    return { kind: "conditional", label: "Condicional" }
  }
  return { kind: "mandatory", label: "Obligatorio" }
}

/**
 * @param {Array} rows
 * @param {"mandatory_only" | "all" | "phase_in"} filter
 */
export function applyObligationFilter(rows, filter = DEFAULT_OBLIGATION_FILTER) {
  const list = Array.isArray(rows) ? rows : []
  if (filter === "all") return list
  if (filter === "phase_in") {
    return list.filter((r) => {
      const dp = r && r.datapoint ? r.datapoint : r
      const ph = dp && dp.phase_in
      return !!(ph && (ph.less_than_750 || ph.all_undertakings))
    })
  }
  // mandatory_only: backend default-selected (fallback: not may_disclose)
  return list.filter((r) => {
    const dp = r && r.datapoint ? r.datapoint : r
    return isDefaultSelected(dp)
  })
}

/**
 * @param {{total_datapoint_count?: number, voluntary_datapoint_count?: number, required_datapoint_count?: number, default_unselected_datapoint_count?: number}} summary
 */
export function honestCountsLabel(summary) {
  const s = summary || {}
  if (typeof s.required_datapoint_count === "number" && typeof s.default_unselected_datapoint_count === "number") {
    return `${s.required_datapoint_count} requeridos + ${s.default_unselected_datapoint_count} no seleccionados por defecto (voluntarios o aplazables)`
  }
  const total = typeof s.total_datapoint_count === "number" ? s.total_datapoint_count : 0
  const vol = typeof s.voluntary_datapoint_count === "number" ? s.voluntary_datapoint_count : 0
  const mand = Math.max(0, total - vol)
  return `${mand} obligatorios + ${vol} voluntarios (opcionales)`
}

/**
 * @param {Record<string, {triage?: string}>} drafts
 */
export function triageSummary(drafts) {
  const counts = { have_it: 0, need_to_find: 0, not_applicable_candidate: 0, untriaged: 0 }
  const entries = Object.values(drafts || {})
  for (const d of entries) {
    const t = d && d.triage
    if (t && TRIAGE_OPTIONS[t]) counts[t] += 1
    else counts.untriaged += 1
  }
  return counts
}

/**
 * @param {any} datapoint
 * @param {boolean} lessThan750
 */
export function phaseInBadgeLabel(datapoint, lessThan750) {
  const ph = datapoint && datapoint.phase_in
  if (ph && ph.less_than_750 && lessThan750) {
    return "Menos de 750 empleados: puedes aplazar este dato"
  }
  return null
}

/**
 * @param {{standard?: string, counts?: {total?: number}}} group
 */
export function sectionProgressLabel(group) {
  const std = (group && group.standard) || ""
  const total = (group && group.counts && typeof group.counts.total === "number") ? group.counts.total : 0
  // progress uses decided if present else total for "X de Y" where Y often global-ish but per group total
  const decided = (group && group.counts && typeof group.counts.decided === "number") ? group.counts.decided : 0
  const shown = decided > 0 ? decided : total
  return `${std} — ${shown} de ${total}`
}

export function localStorageDraftKey(characterizationId) {
  const id = characterizationId != null ? characterizationId : "unknown"
  return `p9_drafts_${id}`
}

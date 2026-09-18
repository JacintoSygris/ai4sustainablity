export type LaravelApiOptions = Omit<RequestInit, "body" | "credentials" | "signal"> & {
  body?: BodyInit | Record<string, unknown> | unknown[] | null
  csrfToken?: string
  timeoutMs?: number
}

export class LaravelApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly payload: unknown,
  ) {
    super(message)
    this.name = "LaravelApiError"
  }
}

export type LaravelApiEnvelope<T> = {
  data: T
}

export type LaravelFrontendSession = {
  authenticated: true
  csrf_header: "X-XSRF-TOKEN"
  csrf_token: string
  // Present when the platform enforces email verification (feature detection):
  // when true AND user.email_verified is false the app routes to /verify-email.
  require_email_verification?: boolean
  user: {
    id: number
    name: string | null
    email: string
    email_verified?: boolean
  }
}

export type LaravelRegisterConfig = {
  turnstile_site_key: string | null
  require_email_verification: boolean
  honeypot_field: string
}

export type LaravelOptionMap = Record<string, string>

export type LaravelNaceCode = {
  code: string
  level: number | null
  parent_code: string | null
  title: {
    en: string | null
    es: string | null
  }
}

export type LaravelCharacterizationCompanyProfile = {
  company_name?: string | null
  headquarters_country?: string | null
  reporting_year?: number | null
  reporting_scope?: string | null
  num_subsidiaries_countries?: number | null
  stock_listed?: boolean | null
  reporting_currency?: string | null
  product_service_type?: string | null
  entity_identifier?: string | null
  entity_identifier_scheme?: string | null
}

export type LaravelCharacterizationOperations = {
  regions?: string[] | null
  value_chain?: string[] | null
  employee_count_range?: string | null
  revenue_range?: string | null
}

export type LaravelCharacterizationFormData = {
  company_profile?: LaravelCharacterizationCompanyProfile | null
  operations?: LaravelCharacterizationOperations | null
  activity_questions?: Record<string, string> | null
  notes?: string | null
  [key: string]: unknown
}

export type LaravelCharacterization = {
  id: number
  status: string
  nace_code: string | null
  esrs_topic_ids: number[]
  form_data: LaravelCharacterizationFormData
  result_data: unknown
  last_error: string | null
  retry_count: number
  next_retry_at: string | null
  last_job_attempted_at: string | null
  submitted_at: string | null
  completed_at: string | null
  created_at: string | null
  updated_at: string | null
}

export type LaravelCharacterizationOptions = {
  levels: {
    core: {
      required_fields: string[]
      draft_clearable_fields: string[]
      company_profile: {
        headquarters_countries: LaravelOptionMap
        reporting_scopes: LaravelOptionMap
        reporting_currencies: LaravelOptionMap
        product_service_types: LaravelOptionMap
      }
      operations: {
        regions: LaravelOptionMap
        value_chain: LaravelOptionMap
        employee_count_ranges: LaravelOptionMap
        revenue_ranges: LaravelOptionMap
        numeric_estimates?: Record<string, unknown>
      }
    }
    activity_questions?: {
      fields_prefix?: string
      fields: LaravelOptionMap
      values: LaravelOptionMap
      note?: string
    }
    csrd_orientation?: Record<string, unknown>
    data_readiness?: Record<string, unknown>
  }
}

export type LaravelCharacterizationDraftPayload = {
  action: "save_draft"
  step: "company" | "operations" | "esg" | "review"
  nace_code?: string | null
  esrs_topic_ids?: number[]
  form_data: LaravelCharacterizationFormData
}

export type LaravelCharacterizationSubmitPayload = {
  action: "submit"
  step: "company" | "operations" | "esg" | "review"
  nace_code?: string | null
  esrs_topic_ids?: number[]
  form_data: LaravelCharacterizationFormData
}

export type LaravelTopicAction = "accepted" | "rejected" | "unsure"

export type LaravelMaterialityTopic = {
  id: number
  esrs_code: string
  theme: {
    en: string | null
    es: string | null
  }
  subtheme: {
    en: string | null
    es: string | null
  }
  subtopic: {
    en: string | null
    es: string | null
  }
}

export type LaravelCharacterizationDocumentStatus =
  | "uploaded"
  | "extracting"
  | "extracted"
  | "failed"
  | "no_usable_evidence"

export type LaravelCharacterizationDocument = {
  id: number
  original_filename: string
  status: LaravelCharacterizationDocumentStatus
  size_bytes: number
  created_at: string | null
}

export type LaravelDocumentEvidenceDocument = {
  id: number
  original_filename: string
  // "deleted" appears only on tombstoned documents inside document_evidence
  status: LaravelCharacterizationDocumentStatus | "deleted"
  stale: boolean
  deleted: boolean
}

export type LaravelDocumentEvidenceKind = "positive" | "negative"

// Tombstoned (deleted-document) evidence carries provenance only: document_id
// without page/confidence/snippet (content is hard-purged with the document).
export type LaravelDocumentEvidenceItem = {
  document_id: number
  page?: number | null
  confidence?: number | null
  snippet?: string
}

export type LaravelDocumentEvidenceTopic = {
  esrs_code: string | null
  standard: string
  source: "document"
  kind: LaravelDocumentEvidenceKind
  evidence: LaravelDocumentEvidenceItem[]
}

export type LaravelDocumentEvidence = {
  documents: LaravelDocumentEvidenceDocument[]
  topics: LaravelDocumentEvidenceTopic[]
}

export type LaravelMaterialityProposalReview = {
  status: "not_started" | "in_progress" | "reviewed"
  topic_actions: Record<string, LaravelTopicAction>
  action_reasons: Record<string, string[]>
  action_notes: Record<string, string>
  reviewed_at: string | null
}

export type LaravelMaterialityProposal = {
  characterization_id: number
  status: string
  source: "ai_prediction" | "stored_topic_ids"
  proposal_topic_ids: number[]
  proposal_topics: LaravelMaterialityTopic[]
  ready_for_confirmation: boolean
  submitted_at: string | null
  completed_at: string | null
  updated_at: string | null
  review: LaravelMaterialityProposalReview
  ai: {
    status: string | null
    summary: string | null
    candidate_topics: unknown[]
    review_required_prediction_keys: string[]
    raw_prediction_key_count: number
  }
  // Present only when the platform enables document extraction (feature detection):
  // absence means the feature is off and the UI must render nothing document-related.
  document_evidence?: LaravelDocumentEvidence
}

export type LaravelMaterialityProposalReviewPayload = {
  topic_actions: Record<string, LaravelTopicAction>
  action_reasons?: Record<string, string[]>
  action_notes?: Record<string, string>
}

export type LaravelLocalizedText = {
  en: string | null
  es: string | null
}

export type LaravelDoubleMaterialityGuideStep = {
  key: string
  title: LaravelLocalizedText
  checks: string[]
  body?: LaravelLocalizedText
}

export type LaravelDoubleMaterialityGuideSection = {
  key: string
  title: LaravelLocalizedText
  steps: LaravelDoubleMaterialityGuideStep[]
}

export type LaravelDoubleMaterialityGuideTemplateColumn = {
  key: string
  label: LaravelLocalizedText
}

export type LaravelDoubleMaterialityGuideTemplate = {
  key: string
  title: LaravelLocalizedText
  columns: LaravelDoubleMaterialityGuideTemplateColumn[]
}

export type LaravelDoubleMaterialityGuide = {
  type: "double_materiality_guide"
  phase: "P7"
  content_format: "structured_prose_v2" | "structured_json"
  warning: LaravelLocalizedText
  sections: LaravelDoubleMaterialityGuideSection[]
  templates?: LaravelDoubleMaterialityGuideTemplate[]
  next_step: {
    next_phase: "P8"
    next_api: string
    note: LaravelLocalizedText
  }
}

export type LaravelDoubleMaterialityProcessChecklist = {
  identified_stakeholders: boolean
  assessed_impacts: boolean
  assessed_financial_effects: boolean
  reached_conclusions: boolean
}

export type LaravelDoubleMaterialityProcessActa = {
  completed_on: string | null
  method: string | null
  participants: string | null
}

export type LaravelDoubleMaterialityProcessState = {
  checklist: LaravelDoubleMaterialityProcessChecklist
  acta: LaravelDoubleMaterialityProcessActa
  acta_registered: boolean
  guide_status: "missing" | "in_progress" | "ready"
  updated_at: string | null
}

export type LaravelEsrsTopic = LaravelMaterialityTopic & {
  examples?: {
    en: string[] | null
    es: string[] | null
  }
  tags?: Record<string, Record<string, boolean>>
  consolidated?: boolean
}

export type LaravelPaginatedEnvelope<T> = {
  data: T[]
  links?: unknown
  meta?: {
    current_page: number
    per_page: number
    total: number
    [key: string]: unknown
  }
}

// (LaravelMaterialityConfirmation extended above with P7/P8 A2-A3 fields; old duplicate removed)

export type LaravelMaterialityConfirmationPayload = {
  confirmed_topic_ids: number[]
  change_reasons?: Record<string, string[]>
  change_reason_notes?: Record<string, string>
  e1_not_material_explanation?: string | null
  dimensions?: Record<string, "impact" | "financial" | "both">
  guided_answers?: Record<string, {
    impacto: "bajo" | "medio" | "alto" | "no_lo_se"
    financiero: "bajo" | "medio" | "alto" | "no_lo_se"
    confianza: "baja" | "media" | "alta"
    exposicion: "normal" | "fuerte" | "descartada"
    suggested_result: "material" | "no_material" | "en_observacion"
    final_result: "material" | "no_material"
    revisar: boolean
    note?: string | null
  }>
}

export type LaravelMaterialityConfirmationDecisionBasis = "guided_questionnaire" | "adm_registered" | "none"

export type LaravelP6Snapshot = {
  topic_ids: number[]
  captured_at: string | null
}

export type LaravelAdmSummary = {
  acta_registered: boolean
  acta: { completed_on: string | null; method: string | null; participants: string | null } | null
}

export type LaravelExposicionDefaults = Record<string, "normal" | "fuerte">

export type LaravelGuidedAnswer = {
  impacto: "bajo" | "medio" | "alto" | "no_lo_se"
  financiero: "bajo" | "medio" | "alto" | "no_lo_se"
  confianza: "baja" | "media" | "alta"
  exposicion: "normal" | "fuerte" | "descartada"
  suggested_result: "material" | "no_material" | "en_observacion"
  final_result: "material" | "no_material"
  revisar: boolean
  note?: string | null
}

export type LaravelMaterialityConfirmation = {
  characterization_id: number
  is_confirmed: boolean
  confirmation_status: "confirmed" | "defaulted_from_p6"
  p6_anchor_date: string | null
  p6_topic_ids: number[]
  confirmed_topic_ids: number[]
  delta: {
    added: number[]
    removed: number[]
    unchanged: number[]
  }
  topics: LaravelMaterialityTopic[]
  confirmation: {
    change_reasons: Record<string, string[]>
    change_reason_notes: Record<string, string>
    e1_not_material_explanation: string | null
    confirmed_at: string | null
    dimensions?: Record<string, "impact" | "financial" | "both">
    guided_answers?: Record<string, LaravelGuidedAnswer>
  }
  preview: {
    material_topic_count: number
    activated_esrs_standards: string[]
    datapoint_estimate: {
      total_datapoint_count: number
      topical_datapoint_count: number
      always_required_datapoint_count: number
      minimum_disclosure_requirement_datapoint_count: number
      voluntary_datapoint_count: number
      conditional_datapoint_count: number
      phase_in_datapoint_count: number
      label: string
    }
    effort_level: "low" | "medium" | "high" | string
    mapping_granularity: string
    coverage_status: string
  }
  // Materiality confirmation fields.
  decision_basis?: LaravelMaterialityConfirmationDecisionBasis
  p6_snapshot?: LaravelP6Snapshot | null
  adm?: LaravelAdmSummary
  exposicion_defaults?: LaravelExposicionDefaults
  is_stale?: boolean
}

export type LaravelEsrsDatapoint = {
  id: string
  name: string
  standard?: string
  dr?: string
  paragraph?: string | null
  related_ar?: string | null
  data_type?: string | null
  applicability?: Record<string, unknown>
  [key: string]: unknown
}

export type LaravelEsrsDatapointBlock = {
  key?: string
  title?: string
  standards?: string[]
  datapoints?: LaravelEsrsDatapoint[]
  disclosure_requirements?: Array<Record<string, unknown>>
  [key: string]: unknown
}

export type LaravelEsrsDatapointCorpus = {
  characterization_id: number
  material_topic_ids: number[]
  activated_esrs_standards: string[]
  summary: {
    always_required_datapoint_count: number
    topical_datapoint_count: number
    minimum_disclosure_requirement_datapoint_count: number
    total_datapoint_count: number
    voluntary_datapoint_count: number
    conditional_datapoint_count: number
    phase_in_datapoint_count: number
    [key: string]: unknown
  }
  generation: Record<string, unknown>
  blocks: Record<string, LaravelEsrsDatapointBlock>
  [key: string]: unknown
}

export type LaravelEsrsDatapointResponseStatus = "draft" | "completed" | "not_applicable"
export type LaravelEsrsFactValueKind = "narrative" | "string" | "boolean" | "date" | "integer" | "decimal" | "monetary" | "percent"

export type LaravelEsrsReportingEntity = {
  identifier_scheme: string
  identifier: string
  name?: string | null
}

export type LaravelEsrsFactContext = {
  period_type: "duration" | "instant"
  start_date: string | null
  end_date: string | null
  instant_date: string | null
  dimensions: Array<{ axis: string; member: string }>
}

export type LaravelEsrsDatapointFact = {
  fact_id?: string
  value_kind: LaravelEsrsFactValueKind
  value: string | boolean
  decimals?: number | null
  unit?: { measure: string } | null
  context: LaravelEsrsFactContext
  evidence_reference: string
  concept?: {
    concept_id: string | null
    taggable_state: string
    reason_code: string | null
  }
}

export type LaravelEsrsDatapointResponse = {
  datapoint_id: string
  status: LaravelEsrsDatapointResponseStatus
  value?: string
  legacy_value?: string
  evidence_reference?: string
  note?: string
  updated_at?: string
  triage?: "have_it" | "need_to_find" | "not_applicable_candidate"
  facts?: LaravelEsrsDatapointFact[]
  concept?: {
    concept_id: string | null
    taggable_state: string
    reason_code: string | null
  }
  suggested_value_kind?: LaravelEsrsFactValueKind
  fact_readiness?: {
    state: string
    fact_count: number
  }
}

export type LaravelEsrsDatapointResponses = {
  characterization_id: number
  schema_version: string
  updated_at: string | null
  reporting_entity: LaravelEsrsReportingEntity
  datapoints?: Record<string, {
    concept: {
      concept_id: string | null
      taggable_state: string
      reason_code: string | null
    }
    suggested_value_kind: LaravelEsrsFactValueKind
  }>
  responses: Record<string, LaravelEsrsDatapointResponse>
  summary: {
    applicable_datapoint_count: number
    response_count: number
    completed_count: number
    draft_count: number
    not_applicable_count: number
    invalid_completed_count?: number
    invalid_not_applicable_count?: number
    facts_count?: number
    taggable_facts_count?: number
    unmapped_or_not_taggable_facts_count?: number
    completion_ratio: number
    completion_status: "not_started" | "in_progress" | "completed"
  }
  orphaned?: {
    count: number
    responses: Record<string, LaravelEsrsDatapointResponse>
  }
}

export type LaravelEsrsDatapointResponsesPayload = {
  reporting_entity?: LaravelEsrsReportingEntity
  responses: LaravelEsrsDatapointResponse[]
}

export type LaravelReportSectionStatus =
  | "ready"
  | "complete"
  | "incomplete"
  | "missing"
  | "not_started"
  | "in_progress"
  | "not_implemented"
  | string

export type LaravelReportSection = {
  status: LaravelReportSectionStatus
  endpoint?: string
  reason_code?: string
  [key: string]: unknown
}

export type LaravelReportDownload = {
  endpoint: string
  content_type: string
  status: "ready" | "incomplete" | "blocked" | string
  depends_on: string[]
  blocking_sections: string[]
}

export type LaravelReportLimitation = {
  key: string
  message: string
}

export type LaravelReportReadiness = {
  type: "report_package_readiness"
  version: string
  characterization_id: number
  status: "incomplete" | "ready" | string
  sections: Record<string, LaravelReportSection>
  downloads: Record<string, LaravelReportDownload>
  next_actions: string[]
  limitations: LaravelReportLimitation[]
}

export type LaravelReportTaxonomyStatus = {
  type: "report_taxonomy_status"
  version: string
  taxonomy: {
    name: "EFRAG ESRS XBRL Taxonomy Set 1" | string
    version: "2023-12-22" | string
  }
  reporting_profile: "esrs-2023-preparatory-v1" | string
  availability: {
    state: "verified" | "blocked" | string
    reason_code?: string
  }
}

export type LaravelReportDraftCompany = {
  name: string | null
  nace_code: string | null
  status: string
  reporting_year: number | null
  product_service_type: string | null
  employee_count_range: string | null
  revenue_range: string | null
  regions: string[]
}

export type LaravelReportDraftMateriality = {
  proposal_source: "p6_ai_candidate_topics" | string
  is_confirmed: boolean
  confirmation_status: string
  proposed_topic_count: number
  confirmed_topic_count: number
  confirmed_at: string | null
  confirmed_topics: LaravelMaterialityTopic[]
}

export type LaravelReportDraftDatapointBlock = {
  key: string | null
  title: string | null
  datapoint_count: number
  response_count: number
  completed_count: number
  not_applicable_count: number
  decided_count: number
}

export type LaravelReportDraftDatapoints = {
  total_datapoint_count: number
  response_status: "not_started" | "in_progress" | "complete" | string
  response_count: number
  completed_count: number
  not_applicable_count: number
  decided_count: number
  completion_ratio: number
  coverage_status: string | null
  matter_to_dr_mapping_status: string | null
  blocks: LaravelReportDraftDatapointBlock[]
  orphaned_response_count?: number
}

export type LaravelReportDraft = {
  type: "report_draft"
  version: string
  characterization_id: number
  generation_status: "frontend_rendered_draft" | "report_preparation_package_ready" | string
  readiness_status: "incomplete" | "ready" | string
  company: LaravelReportDraftCompany
  materiality: LaravelReportDraftMateriality
  datapoints: LaravelReportDraftDatapoints
  exports: Record<string, LaravelReportDownload>
  limitations: LaravelReportLimitation[]
}

const defaultApiBase = process.env.NEXT_PUBLIC_LARAVEL_API_BASE_URL || "/api"
const defaultTimeoutMs = 15000

function joinApiPath(path: string): string {
  const cleanBase = defaultApiBase.replace(/\/$/, "")
  const cleanPath = path.startsWith("/") ? path : `/${path}`

  return `${cleanBase}${cleanPath}`
}

function readCookie(name: string): string | null {
  if (typeof document === "undefined") {
    return null
  }

  const encoded = document.cookie
    .split(";")
    .map((cookie) => cookie.trim())
    .find((cookie) => cookie.startsWith(`${name}=`))

  if (!encoded) {
    return null
  }

  return decodeURIComponent(encoded.slice(name.length + 1))
}

function needsCsrf(method: string): boolean {
  return !["GET", "HEAD", "OPTIONS"].includes(method.toUpperCase())
}

function isBodyInit(body: LaravelApiOptions["body"]): body is BodyInit {
  return (
    typeof body === "string" ||
    (typeof Blob !== "undefined" && body instanceof Blob) ||
    (typeof ArrayBuffer !== "undefined" && body instanceof ArrayBuffer) ||
    (typeof FormData !== "undefined" && body instanceof FormData) ||
    (typeof URLSearchParams !== "undefined" && body instanceof URLSearchParams) ||
    (typeof ReadableStream !== "undefined" && body instanceof ReadableStream)
  )
}

export async function laravelApi<T>(path: string, options: LaravelApiOptions = {}): Promise<T> {
  const {
    body: rawBody,
    csrfToken,
    headers: inputHeaders,
    method = "GET",
    timeoutMs = defaultTimeoutMs,
    ...requestInit
  } = options
  const headers = new Headers(inputHeaders)
  const controller = new AbortController()
  const timeout = setTimeout(() => controller.abort(), timeoutMs)

  headers.set("Accept", headers.get("Accept") ?? "application/json")
  headers.set("X-Requested-With", headers.get("X-Requested-With") ?? "XMLHttpRequest")

  let body: BodyInit | undefined

  if (rawBody != null) {
    if (isBodyInit(rawBody)) {
      body = rawBody
    } else {
      headers.set("Content-Type", headers.get("Content-Type") ?? "application/json")
      body = JSON.stringify(rawBody)
    }
  }

  if (needsCsrf(method)) {
    if (csrfToken) {
      headers.set("X-CSRF-TOKEN", csrfToken)
    } else {
      const xsrfToken = readCookie("XSRF-TOKEN")

      if (xsrfToken) {
        headers.set("X-XSRF-TOKEN", xsrfToken)
      }
    }
  }

  try {
    const response = await fetch(joinApiPath(path), {
      ...requestInit,
      body,
      credentials: "include",
      headers,
      method,
      signal: controller.signal,
    })

    const contentType = response.headers.get("Content-Type") ?? ""
    const responseText = await response.text()
    const payload = contentType.includes("application/json") && responseText ? JSON.parse(responseText) : responseText

    if (!response.ok) {
      const error = new LaravelApiError(
        `Platform API request failed with status ${response.status}`,
        response.status,
        payload,
      )

      // When enforcement is on, protected wizard endpoints answer 409
      // {code:"email_unverified"}. Route the signed-in user to the verify-email
      // screen from this shared layer so every caller is covered; still throw so
      // in-flight callers unwind cleanly.
      if (
        isEmailUnverifiedError(error) &&
        typeof window !== "undefined" &&
        window.location.pathname !== "/verify-email"
      ) {
        window.location.assign("/verify-email")
      }

      throw error
    }

    return payload as T
  } finally {
    clearTimeout(timeout)
  }
}

export function laravelApiUrl(path: string): string {
  return joinApiPath(path)
}

// A protected wizard endpoint answers HTTP 409 {code:"email_unverified"} when
// enforcement is on and the signed-in user has not verified their email yet.
// Callers treat this as "route to the verify-email screen", not a generic error.
export function isEmailUnverifiedError(error: unknown): boolean {
  return (
    error instanceof LaravelApiError &&
    error.status === 409 &&
    typeof error.payload === "object" &&
    error.payload !== null &&
    (error.payload as { code?: unknown }).code === "email_unverified"
  )
}

export function getLaravelRegisterConfig(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelRegisterConfig>> {
  return laravelApi<LaravelApiEnvelope<LaravelRegisterConfig>>("/auth/register-config", {
    cache: "no-store",
    ...options,
    method: "GET",
  })
}

export function getLaravelSession(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelFrontendSession>> {
  return laravelApi<LaravelApiEnvelope<LaravelFrontendSession>>("/auth/session", {
    ...options,
    method: "GET",
  })
}

export function getLaravelCharacterization(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelCharacterization | null>> {
  return laravelApi<LaravelApiEnvelope<LaravelCharacterization | null>>("/characterization", {
    ...options,
    method: "GET",
  })
}

export function getLaravelCharacterizationOptions(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelCharacterizationOptions>> {
  return laravelApi<LaravelApiEnvelope<LaravelCharacterizationOptions>>("/characterization/options", {
    ...options,
    method: "GET",
  })
}

export function getLaravelNaceCodes(
  params: { search?: string; level?: number; parent_code?: string; per_page?: number } = {},
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelPaginatedEnvelope<LaravelNaceCode>> {
  const searchParams = new URLSearchParams()

  if (params.search) {
    searchParams.set("search", params.search)
  }

  if (typeof params.level === "number") {
    searchParams.set("level", String(params.level))
  }

  if (params.parent_code) {
    searchParams.set("parent_code", params.parent_code)
  }

  if (params.per_page) {
    searchParams.set("per_page", String(params.per_page))
  }

  const query = searchParams.toString()

  return laravelApi<LaravelPaginatedEnvelope<LaravelNaceCode>>(`/nace-codes${query ? `?${query}` : ""}`, {
    ...options,
    method: "GET",
  })
}

export function saveLaravelCharacterizationDraft(
  payload: LaravelCharacterizationDraftPayload,
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelCharacterization>> {
  return laravelApi<LaravelApiEnvelope<LaravelCharacterization>>("/characterization", {
    ...options,
    body: payload,
    method: "PUT",
  })
}

export function submitLaravelCharacterization(
  payload: LaravelCharacterizationSubmitPayload,
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelCharacterization>> {
  return laravelApi<LaravelApiEnvelope<LaravelCharacterization>>("/characterization/submit", {
    ...options,
    body: payload,
    method: "POST",
  })
}

export function getLaravelMaterialityProposal(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelMaterialityProposal | null>> {
  return laravelApi<LaravelApiEnvelope<LaravelMaterialityProposal | null>>("/materiality-proposal", {
    ...options,
    method: "GET",
  })
}

export function updateLaravelMaterialityProposalReview(
  payload: LaravelMaterialityProposalReviewPayload,
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelMaterialityProposal>> {
  return laravelApi<LaravelApiEnvelope<LaravelMaterialityProposal>>("/materiality-proposal", {
    ...options,
    body: payload,
    method: "PUT",
  })
}

export function listLaravelCharacterizationDocuments(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<{ documents: LaravelCharacterizationDocument[] }>> {
  // The API wraps the list as data.documents (E2E lane finding: the earlier
  // data-as-array typing crashed the panel at runtime).
  return laravelApi<LaravelApiEnvelope<{ documents: LaravelCharacterizationDocument[] }>>(
    "/characterization/documents",
    {
      ...options,
      method: "GET",
    },
  )
}

export function uploadLaravelCharacterizationDocument(
  file: File,
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelCharacterizationDocument>> {
  const body = new FormData()

  body.append("document", file, file.name)

  return laravelApi<LaravelApiEnvelope<LaravelCharacterizationDocument>>("/characterization/documents", {
    timeoutMs: 120000,
    ...options,
    body,
    method: "POST",
  })
}

export function deleteLaravelCharacterizationDocument(
  documentId: number,
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<unknown> {
  return laravelApi<unknown>(`/characterization/documents/${documentId}`, {
    ...options,
    method: "DELETE",
  })
}

export function getLaravelDoubleMaterialityGuide(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelDoubleMaterialityGuide>> {
  return laravelApi<LaravelApiEnvelope<LaravelDoubleMaterialityGuide>>("/double-materiality-guide", {
    ...options,
    method: "GET",
  })
}

export function getLaravelMaterialityConfirmation(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelMaterialityConfirmation | null>> {
  return laravelApi<LaravelApiEnvelope<LaravelMaterialityConfirmation | null>>("/materiality-confirmation", {
    ...options,
    method: "GET",
  })
}

export function updateLaravelMaterialityConfirmation(
  payload: LaravelMaterialityConfirmationPayload,
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelMaterialityConfirmation>> {
  return laravelApi<LaravelApiEnvelope<LaravelMaterialityConfirmation>>("/materiality-confirmation", {
    ...options,
    body: payload,
    method: "PUT",
  })
}

export function getLaravelEsrsTopics(
  params: { search?: string; esrs_code?: string; per_page?: number } = {},
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelPaginatedEnvelope<LaravelEsrsTopic>> {
  const searchParams = new URLSearchParams()

  if (params.search) {
    searchParams.set("search", params.search)
  }

  if (params.esrs_code) {
    searchParams.set("esrs_code", params.esrs_code)
  }

  if (params.per_page) {
    searchParams.set("per_page", String(params.per_page))
  }

  const query = searchParams.toString()

  return laravelApi<LaravelPaginatedEnvelope<LaravelEsrsTopic>>(`/esrs-topics${query ? `?${query}` : ""}`, {
    ...options,
    method: "GET",
  })
}

export function getLaravelEsrsDatapoints(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelEsrsDatapointCorpus | null>> {
  return laravelApi<LaravelApiEnvelope<LaravelEsrsDatapointCorpus | null>>("/esrs-datapoints", {
    ...options,
    method: "GET",
  })
}

export function getLaravelEsrsDatapointResponses(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelEsrsDatapointResponses | null>> {
  return laravelApi<LaravelApiEnvelope<LaravelEsrsDatapointResponses | null>>("/esrs-datapoints/responses", {
    ...options,
    method: "GET",
  })
}

export function updateLaravelEsrsDatapointResponses(
  payload: LaravelEsrsDatapointResponsesPayload,
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelEsrsDatapointResponses>> {
  return laravelApi<LaravelApiEnvelope<LaravelEsrsDatapointResponses>>("/esrs-datapoints/responses", {
    ...options,
    body: payload,
    method: "PUT",
  })
}

export function getLaravelReportReadiness(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelReportReadiness | null>> {
  return laravelApi<LaravelApiEnvelope<LaravelReportReadiness | null>>("/report", {
    cache: "no-store",
    ...options,
    method: "GET",
  })
}

export function getLaravelReportTaxonomyStatus(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelReportTaxonomyStatus>> {
  return laravelApi<LaravelApiEnvelope<LaravelReportTaxonomyStatus>>("/report/taxonomy", {
    cache: "no-store",
    ...options,
    method: "GET",
  })
}

export function getLaravelReportDraft(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelReportDraft | null>> {
  return laravelApi<LaravelApiEnvelope<LaravelReportDraft | null>>("/report/draft", {
    cache: "no-store",
    ...options,
    method: "GET",
  })
}

export function getLaravelDoubleMaterialityGuideState(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelDoubleMaterialityProcessState>> {
  return laravelApi<LaravelApiEnvelope<LaravelDoubleMaterialityProcessState>>("/double-materiality-guide/state", {
    ...options,
    method: "GET",
  })
}

export function updateLaravelDoubleMaterialityGuideState(
  payload: { checklist?: Partial<LaravelDoubleMaterialityProcessChecklist>; acta?: Partial<LaravelDoubleMaterialityProcessActa> },
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelDoubleMaterialityProcessState>> {
  return laravelApi<LaravelApiEnvelope<LaravelDoubleMaterialityProcessState>>("/double-materiality-guide/state", {
    ...options,
    body: payload,
    method: "PUT",
  })
}

export function previewLaravelMaterialityConfirmation(
  payload: { candidate_topic_ids: number[] },
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<{ preview: LaravelMaterialityConfirmation["preview"] }>> {
  return laravelApi<LaravelApiEnvelope<{ preview: LaravelMaterialityConfirmation["preview"] }>>("/materiality-confirmation/preview", {
    ...options,
    body: payload,
    method: "POST",
  })
}

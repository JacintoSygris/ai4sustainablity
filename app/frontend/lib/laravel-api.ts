import { validateDatapointWorkspace } from "./esrs-datapoints-state.mjs"

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
  locale: "es" | "en"
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
  registration_enabled: boolean
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
  revision: number
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
    topic_sync_status: "no_ai_candidates" | "legacy_candidate_fallback" | "synced" | "stored_override"
    model_profile: string | null
    model_key_count: number | null
    mapped_key_count: number | null
    feature_metadata: {
      derived_fields: Record<string, unknown> | []
      defaulted_fields: Record<string, unknown> | []
      missing_required_fields: string[]
    }
    mapping_metadata: Record<string, unknown> | []
    evidence_refs: unknown[]
  }
  // Present only when the platform enables document extraction (feature detection):
  // absence means the feature is off and the UI must render nothing document-related.
  document_evidence?: LaravelDocumentEvidence
}

export type LaravelMaterialityProposalReviewPayload = {
  expected_revision: number
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

type LaravelMaterialityLearningUniversePair =
  | {
      reviewed_topic_ids: number[]
      universe_attestation: LaravelMaterialityUniverseAttestation
    }
  | {
      reviewed_topic_ids?: never
      universe_attestation?: never
    }

export type LaravelMaterialityConfirmationPayload = {
  expected_revision: number
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
} & LaravelMaterialityLearningUniversePair

export type LaravelMaterialityConfirmationDecisionBasis = "guided_questionnaire" | "adm_registered" | "none"

export type LaravelMaterialityUniverseAttestation = {
  version: 1
  reviewed_universe: boolean
  mode: "direct" | "guided"
}

export type LaravelP6Snapshot = {
  topic_ids: number[]
  captured_at: string | null
}

export type LaravelAdmSummary = {
  acta_registered: boolean
  acta: { completed_on: string | null; method: string | null; participants: string | null }
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
  learning_topic_labels: Record<string, 0 | 1> | null
  delta: {
    added: number[]
    removed: number[]
    unchanged: number[]
  }
  topics: LaravelMaterialityTopic[]
  confirmation: {
    revision: number
    reviewed_topic_ids: number[]
    universe_attestation: LaravelMaterialityUniverseAttestation | null
    change_reasons: Record<string, string[]>
    change_reason_notes: Record<string, string>
    e1_not_material_explanation: string | null
    confirmed_at: string | null
    dimensions: Record<string, "impact" | "financial" | "both">
    guided_answers: Record<string, LaravelGuidedAnswer>
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
    effort_level: "low" | "medium" | "high"
    mapping_granularity: "disclosure_requirement_mapping_required" | "disclosure_requirement_level"
    coverage_status: "topical_mapping_required" | "dr_level"
  }
  decision_basis: LaravelMaterialityConfirmationDecisionBasis
  p6_snapshot: LaravelP6Snapshot | null
  adm: LaravelAdmSummary
  exposicion_defaults: LaravelExposicionDefaults
  is_stale: boolean
}

export type LaravelEsrsDatapoint = {
  display?: { locale: "es" | "en"; name: string; data_type: string; disclosure_requirement_title?: string | null; qualification?: string | null; conditional_or_alternative: string; phase_in: Record<string, string>; applicability_reason: string; mapping_basis: string; limitations: string[] }
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
  locale: "es" | "en"
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

export type LaravelDatapointReview = {
  relevant?: boolean
  selected_to_answer?: boolean
  reason_codes?: string[]
  note?: string | null
}
export type LaravelDatapointFeedback = {
  schema_version: "datapoint-feedback-v1"
  authority_digest: string
  reviewed_datapoint_ids: string[]
  decisions: Array<{ datapoint_id: string; relevant: boolean; selected_to_answer: boolean; reason_codes: string[]; note: string | null }>
}

export type LaravelEsrsDatapointResponse = {
  datapoint_id: string
  status: LaravelEsrsDatapointResponseStatus
  value?: string
  evidence_reference?: string
  note?: string
  updated_at?: string
  triage?: "have_it" | "need_to_find" | "not_applicable_candidate"
}

export type LaravelEsrsDatapointResponses = {
  source_digest: string
  learning_authority_digest: string
  learning_feedback: LaravelDatapointFeedback
  characterization_id: number
  schema_version: "v0" | "v1"
  revision: number
  updated_at: string | null
  responses: Record<string, LaravelEsrsDatapointResponse>
  summary: {
    applicable_datapoint_count: number
    response_count: number
    completed_count: number
    draft_count: number
    not_applicable_count: number
    completion_ratio: number
    completion_status: "not_started" | "in_progress" | "completed"
    decided_count: number
    decided_required_count: number
    optional_response_count: number
  }
  orphaned: {
    count: number
    responses: Record<string, LaravelEsrsDatapointResponse>
  }
}

export type LaravelEsrsDatapointResponsesPayload = {
  learning_feedback?: LaravelDatapointFeedback
  expected_revision: number
  responses: LaravelEsrsDatapointResponse[]
}

export type LaravelLearningRevision = {
  generation: number
  revision: number
  digest: string
}

export type LaravelLearningSourceRevisions = {
  p5: LaravelLearningRevision
  p6: LaravelLearningRevision
  p8: LaravelLearningRevision
  p9: LaravelLearningRevision
}

export type LaravelLearningTopicLabel =
  | { topic_id: string; value: 0 | 1; observed_mask: 1 }
  | { topic_id: string; value: null; observed_mask: 0 }

export type LaravelLearningDatapointDecision = {
  datapoint_id: string
  relevant: boolean
  selected_to_answer: boolean
  reason_codes: string[]
  note: string | null
}

export type LaravelLearningCaseV1 = {
  schema_version: "learning-case-v1"
  case_id: string
  case_hash: string
  company_group_key: string
  period_scope: {
    period_key: string
    perimeter_key: string
  }
  authority: {
    framework_version: string
    catalog_version: string
    catalog_digest: string
    mapping_version: string
    mapping_digest: string
  }
  provenance: {
    source_kind: "human_product" | "report" | "synthetic"
    source_record_digest: string
    source_revision: string
  }
  source_revisions: LaravelLearningSourceRevisions
  p5_snapshot: {
    schema_version: string
    digest: string
  }
  p6_snapshot: {
    model_profile: string
    model_digest: string
    policy_digest: string
  }
  topic_universe: {
    reviewed_topic_ids: string[]
    outside_scope_topic_ids: string[]
  }
  topic_labels: LaravelLearningTopicLabel[]
  datapoint_universe: {
    reviewed_datapoint_ids: string[]
    outside_scope_datapoint_ids: string[]
  }
  datapoint_decisions: LaravelLearningDatapointDecision[]
  rights: {
    policy_version: string
    policy_digest: string
    policy_status: "approved" | "unapproved"
    state: "granted" | "revoked" | "deleted" | "unapproved"
    authorization_generation: number
  }
  closure_evidence: {
    declaration_version: string
    declaration_status: "accepted" | "unaccepted"
    reviewed_universe: boolean
    final_for_period_scope: boolean
    server_actor_id: string
    recorded_at: string
  }
}

export type LaravelLearningEligibilityCase = {
  case_id: string
  case_hash: string
  source_revisions: LaravelLearningSourceRevisions
  rights_digest: string
  policy_digest: string
}

export type LaravelLearningEligibilityTombstone = {
  case_id: string
  case_hash: string
  at: string
}

export type LaravelLearningEligibilityManifestV1 = {
  schema_version: "learning-eligibility-v1"
  generation: number
  issued_at: string
  valid_until: string
  cases: LaravelLearningEligibilityCase[]
  eligible_case_ids: string[]
  tombstones: {
    revoked: LaravelLearningEligibilityTombstone[]
    deleted: LaravelLearningEligibilityTombstone[]
  }
  rights_snapshot_digest: string
  eligibility_policy_digest: string
  canonical_digest: string
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
  locale: "es" | "en"
  type: "report_package_readiness"
  version: string
  characterization_id: number
  status: "incomplete" | "ready" | string
  workflow_status: "incomplete" | "ready" | string
  workflow_complete: boolean
  report_content_status: "incomplete" | "ready" | string
  report_content_ready: boolean
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
  confirmed_theme_count: number
  confirmed_at: string | null
  confirmed_topics: LaravelMaterialityTopic[]
  confirmed_themes: Array<{ esrs_code: string; label: string }>
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
  locale: "es" | "en"
  type: "report_draft"
  version: string
  characterization_id: number
  generation_status: "frontend_rendered_draft" | "report_preparation_package_ready" | string
  readiness_status: "incomplete" | "ready" | string
  workflow_status: "incomplete" | "ready" | string
  workflow_complete: boolean
  report_content_status: "incomplete" | "ready" | string
  report_content_ready: boolean
  company: LaravelReportDraftCompany
  materiality: LaravelReportDraftMateriality
  datapoints: LaravelReportDraftDatapoints
  exports: Record<string, LaravelReportDownload>
  limitations: LaravelReportLimitation[]
}

export type LaravelReportingFactValue = string | boolean | null | Record<string, string>
// Historical persisted facts may still carry native JSON numbers.
export type LaravelReportingFactDisplayValue = LaravelReportingFactValue | number

export type LaravelReportingFactDimension = {
  axis: string
  member: string
}

export type LaravelReportingFactEvidenceRef = {
  type: string
  value: string
}

export type LaravelReportingFactApplicability =
  | "applicable"
  | "not_applicable"
  | "pending"
  | "unavailable"
  | "blocked"

export type LaravelReportingFactValueType =
  | "text"
  | "number"
  | "monetary"
  | "integer"
  | "boolean"
  | "enumeration"
  | "date"
  | "nil"

export type LaravelReportingFactApprovalStatus = "review_required" | "reviewed" | "approved" | string

export type LaravelReportingFact = {
  origin: "persisted" | "legacy_projection" | string
  id?: number
  fact_id: string
  schema_version: string
  profile_id: string
  datapoint_id: string
  applicability: LaravelReportingFactApplicability
  value_type: LaravelReportingFactValueType
  value: LaravelReportingFactDisplayValue
  unit: string | null
  decimals: number | null
  dimensions: LaravelReportingFactDimension[]
  language: string | null
  nil: boolean
  nil_reason: string | null
  evidence_refs: LaravelReportingFactEvidenceRef[]
  provenance: string
  approval_status: LaravelReportingFactApprovalStatus
  blocking_reasons: string[]
  created_at?: string | null
  updated_at?: string | null
}

export type LaravelReportingFactInput = {
  datapoint_id: string
  applicability: LaravelReportingFactApplicability
  value_type: LaravelReportingFactValueType
  value: LaravelReportingFactValue
  unit: string | null
  decimals: number | null
  dimensions: LaravelReportingFactDimension[]
  language: string | null
  nil: boolean
  nil_reason: string | null
  evidence_refs: LaravelReportingFactEvidenceRef[]
  provenance: "api"
  approval_status: "review_required"
  blocking_reasons: string[]
}

export type LaravelReportingFactState = {
  characterization_id: number
  schema_version: string
  profile_id: string
  persisted_facts: LaravelReportingFact[]
  persisted_fact_count: number
  legacy_projection: LaravelReportingFact[]
  legacy_projection_count: number
  pending_p9_suggestions: LaravelReportingFact[]
  pending_p9_suggestion_count: number
}

export type LaravelReportSnapshotApproval = {
  id: number
  role_mode: string
  approved_at: string | null
}

export type LaravelReportSnapshot = {
  id: number
  characterization_id: number
  profile_id: string
  profile_hash: string
  facts_hash: string
  characterization_hash: string
  snapshot_hash: string
  stale_state: "fresh" | "stale" | string
  stale_reasons: string[]
  approval: LaravelReportSnapshotApproval | null
  is_approved: boolean
  created_at: string | null
  updated_at: string | null
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

export type LaravelEsrsDatapointWorkspace = {
  snapshot_version: "p9-workspace-v1"
  data: (LaravelEsrsDatapointCorpus & { learning_authority_digest: string; mapping_snapshot_digest: string })
  response_state: LaravelEsrsDatapointResponses
}

export async function getLaravelEsrsDatapointWorkspace(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelEsrsDatapointWorkspace> {
  const snapshot = await laravelApi<LaravelEsrsDatapointWorkspace>("/esrs-datapoints", {
    ...options, cache: "no-store", method: "GET",
  })
  validateDatapointWorkspace(snapshot)
  return snapshot
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
    ...options,
    cache: "no-store",
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

export function getLaravelReportingFacts(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelReportingFactState | null>> {
  return laravelApi<LaravelApiEnvelope<LaravelReportingFactState | null>>("/report/facts", {
    cache: "no-store",
    ...options,
    method: "GET",
  })
}

export function saveLaravelReportingFacts(
  payload: { facts: LaravelReportingFactInput[] },
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelReportingFactState>> {
  return laravelApi<LaravelApiEnvelope<LaravelReportingFactState>>("/report/facts", {
    ...options,
    body: payload,
    method: "PUT",
  })
}

export function reviewLaravelReportingFact(
  factId: number,
  payload: { review_declaration: string },
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelReportingFact>> {
  return laravelApi<LaravelApiEnvelope<LaravelReportingFact>>(`/report/facts/${factId}/review`, {
    ...options,
    body: payload,
    method: "POST",
  })
}

export function createLaravelReportSnapshot(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelReportSnapshot>> {
  return laravelApi<LaravelApiEnvelope<LaravelReportSnapshot>>("/report/snapshot", {
    ...options,
    method: "POST",
  })
}

export function getLaravelReportSnapshots(
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelReportSnapshot[]>> {
  return laravelApi<LaravelApiEnvelope<LaravelReportSnapshot[]>>("/report/snapshots", {
    cache: "no-store",
    ...options,
    method: "GET",
  })
}

export function approveLaravelReportSnapshot(
  snapshotId: number,
  payload: { single_person_declaration: string },
  options: Omit<LaravelApiOptions, "body" | "method"> = {},
): Promise<LaravelApiEnvelope<LaravelReportSnapshotApproval>> {
  return laravelApi<LaravelApiEnvelope<LaravelReportSnapshotApproval>>(`/report/snapshots/${snapshotId}/approve`, {
    ...options,
    body: payload,
    method: "POST",
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

export type LaravelLearningClosureHeader = LaravelLearningRevision & { characterization_id: number; epoch: string }
export type LaravelLearningClosureCommand = {
  expected_revisions: Record<"p5" | "p6_base" | "p8" | "p9", LaravelLearningClosureHeader>
  expected_authorization_generation: number
  source_token: string
  idempotency_key: string
  reviewed_universe: boolean
  final_for_period_scope: boolean
  declaration_version: "local-synthetic-closure-v1"
}
export type LaravelLearningClosureReceipt = {
  schema_version: "learning-case-closure-receipt-v1"
  case_id: string
  case_hash: string
  source_token: string
  p5_completion_reference: string
  actor_id: number
  authorization_generation: number
  authorization_digest: string
  recorded_at: string
  receipt_hash: string
  provenance: "synthetic-only"
  promotion_allowed: false
}
export type LaravelLearningClosureResult = {
  schema_version: "learning-case-closure-v1"
  status: "disabled" | "blocked" | "ready" | "closed" | "stale" | "withdrawn"
  case_hash: string | null
  receipt: LaravelLearningClosureReceipt | null
  provenance: "synthetic-only"
  promotion_allowed: false
}
export type LaravelLearningClosureDraft = LaravelLearningClosureResult & {
  can_close: boolean
  can_withdraw: boolean
  expected_revisions: LaravelLearningClosureCommand["expected_revisions"] | null
  expected_authorization_generation: number | null
  source_token: string | null
  draft: LaravelLearningClosureCommand | null
}
export function getLaravelLearningCaseDraft(options: Omit<LaravelApiOptions, "body" | "method"> = {}) {
  return laravelApi<LaravelApiEnvelope<LaravelLearningClosureDraft>>("/learning-case/draft", { ...options, method: "GET", cache: "no-store" })
}
export function saveLaravelLearningCaseDraft(body: LaravelLearningClosureCommand, options: Omit<LaravelApiOptions, "body" | "method"> = {}) {
  return laravelApi<LaravelApiEnvelope<LaravelLearningClosureDraft>>("/learning-case/draft", { ...options, method: "PUT", body })
}
export function closeLaravelLearningCase(body: LaravelLearningClosureCommand, options: Omit<LaravelApiOptions, "body" | "method"> = {}) {
  return laravelApi<LaravelApiEnvelope<LaravelLearningClosureResult>>("/learning-case/close", { ...options, method: "POST", body })
}
export function withdrawLaravelLearningCase(body: Pick<LaravelLearningClosureCommand, "expected_authorization_generation" | "idempotency_key">, options: Omit<LaravelApiOptions, "body" | "method"> = {}) {
  return laravelApi<LaravelApiEnvelope<LaravelLearningClosureDraft>>("/learning-case/withdraw", { ...options, method: "POST", body })
}

export type LaravelLocaleContext = { locale: "es" | "en"; supported_locales: string[]; csrf_token: string }
export function getLaravelLocale() {
  return laravelApi<LaravelApiEnvelope<LaravelLocaleContext>>("/locale", { cache: "no-store" })
}
export function updateLaravelLocale(locale: "es" | "en", csrfToken: string) {
  return laravelApi<LaravelApiEnvelope<LaravelLocaleContext>>("/locale", { method: "PUT", body: { locale }, csrfToken })
}

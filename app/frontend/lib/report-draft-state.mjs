export function humanizeKey(key) {
  return String(key ?? "")
    .replace(/^p\d+[_\s-]*/i, "")
    .replace(/_/g, " ")
    .replace(/[/?=&.-]+/g, " ")
    .trim()
    .replace(/\s+/g, " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
    .replace(/^P\d+\s+/i, "")
}

const SECTION_LABELS = {
  characterization: "Caracterización de la empresa",
  materiality_proposal: "Propuesta de temas (IA)",
  double_materiality_guide: "Guía de doble materialidad",
  materiality_confirmation: "Confirmación de materialidad final",
  esrs_datapoints: "Lista de información ESRS",
  datapoint_responses: "Respuestas registradas",
  final_report_generation: "Resumen de resultados",
}

const DOWNLOAD_LABELS = {
  p8_decision_sheet: "Hoja de decisión de materialidad",
  p9_responses_csv: "Respuestas registradas (CSV)",
  p9_datapoints_csv: "Lista de información ESRS (CSV)",
  characterization_summary_pdf: "Resumen de caracterización (PDF)",
  report_readiness: "Resumen de preparación (JSON)",
  report_package_html: "Paquete HTML imprimible",
  evidence_bundle_json: "Trazabilidad de preparación (JSON)",
}

const LIMITATION_MESSAGES = {
  report_package_scope:
    "El paquete organiza preparación ESRS 2023. No sustituye presentación oficial, aseguramiento, Taxonomía UE ni aceptación de formatos digitales.",
  exact_ar16_matter_to_dr_mapping_pending:
    "Modo alcance: la lista incluye bloques transversales disponibles, pero la información temática derivada de tus temas no se genera hasta que la plataforma tenga una correspondencia tema-requisito configurada y válida.",
  materiality_confirmation_stale:
    "Tu confirmación de materialidad es anterior a tus últimos cambios en la propuesta de temas. Vuelve al paso 4 y confirma de nuevo.",
  orphaned_datapoint_responses:
    "Algunas respuestas guardadas corresponden a temas que ya no están en tu alcance. Se conservan y volverán a aparecer si recuperas esos temas.",
}

export function sectionLabel(key) {
  return SECTION_LABELS[key] ?? humanizeKey(key)
}

export function downloadLabel(key) {
  return DOWNLOAD_LABELS[key] ?? humanizeKey(key)
}

export function limitationMessage(limitation) {
  return LIMITATION_MESSAGES[limitation?.key] ?? limitation?.message ?? ""
}

export function statusLabel(status) {
  if (!status) {
    return "-"
  }

  const labels = {
    blocked: "Bloqueado",
    complete: "Completo",
    generation_pending: "Pendiente de generación",
    incomplete: "Incompleto",
    in_progress: "En curso",
    missing: "Pendiente",
    not_implemented: "No disponible en esta versión",
    not_started: "Sin empezar",
    ready: "Listo",
    scoping_only: "Modo alcance",
  }

  return labels[status] ?? humanizeKey(status)
}

export function statusTone(status) {
  if (status === "ready" || status === "complete") {
    return "border-emerald-200 bg-emerald-50 text-emerald-800"
  }

  if (status === "generation_pending") {
    return "border-blue-200 bg-blue-50 text-blue-800"
  }

  if (status === "not_implemented" || status === "blocked" || status === "scoping_only") {
    return "border-amber-200 bg-amber-50 text-amber-800"
  }

  return "border-border bg-muted text-muted-foreground"
}

export function formatPercent(value) {
  if (typeof value !== "number" || Number.isNaN(value)) {
    return "-"
  }

  return `${Math.round(value * 100)}%`
}

export function endpointHref(endpoint, apiUrl) {
  if (endpoint.startsWith("/api/")) {
    return apiUrl(endpoint.slice(4))
  }

  if (endpoint.startsWith("/characterization/")) {
    return endpoint
  }

  return apiUrl(endpoint)
}

export function actionTarget(endpoint) {
  if (endpoint === "/api/report/draft" || endpoint.includes("report/draft") || endpoint.includes("report/package")) {
    return "/wizard/step-6"
  }

  if (endpoint.includes("characterization")) {
    return "/wizard/step-1"
  }

  if (endpoint.includes("materiality-proposal")) {
    return "/wizard/step-2"
  }

  if (endpoint.includes("materiality-confirmation")) {
    return "/wizard/step-4"
  }

  if (endpoint.includes("esrs-datapoints")) {
    return "/wizard/step-5"
  }

  return "/wizard/step-1"
}

export function actionLabel(endpoint) {
  if (endpoint?.includes("characterization")) {
    return "Completar la encuesta inicial (paso 1)"
  }

  if (endpoint?.includes("materiality-proposal")) {
    return "Revisar la propuesta de temas (paso 2)"
  }

  if (endpoint?.includes("materiality-confirmation")) {
    return "Confirmar la materialidad (paso 4)"
  }

  if (endpoint?.includes("esrs-datapoints")) {
    return "Registrar información ESRS (paso 5)"
  }

  if (endpoint === "/api/report/draft") {
    return "Revisar el resumen (paso 6)"
  }

  if (endpoint === "/api/report/package") {
    return "Abrir el paquete HTML (paso 6)"
  }

  return humanizeKey(
    String(endpoint ?? "")
      .replace(/^\/api\//, "")
      .replace(/^\/characterization\//, "characterization_")
      .replace(/[/?=&.-]+/g, "_"),
  )
}

export function isScopingOnly(readiness, draft) {
  if (readiness?.coverage_mode === "scoping_only" || draft?.coverage_mode === "scoping_only") {
    return true
  }

  const mappingStatuses = [
    readiness?.sections?.esrs_datapoints?.matter_to_dr_mapping_status,
    draft?.datapoints?.matter_to_dr_mapping_status,
  ]

  return mappingStatuses.some((status) => status !== undefined && status !== null && status !== "loaded")
}

export function visibleNextActions(readiness) {
  return (readiness?.next_actions ?? []).filter((endpoint) => actionTarget(endpoint) !== "/wizard/step-6")
}

export function allSectionsReady(readiness) {
  const actions = readiness?.next_actions ?? []

  return actions.length === 0 || actions.every((endpoint) => actionTarget(endpoint) === "/wizard/step-6")
}

export function sectionNumber(section) {
  const countKeys = [
    "topic_count",
    "confirmed_topic_count",
    "total_datapoint_count",
    "decided_count",
    "completed_count",
  ]

  for (const key of countKeys) {
    const value = section?.[key]

    if (typeof value === "number") {
      return String(value)
    }
  }

  return "-"
}

export function uniqueLimitations(readiness, draft) {
  const seen = new Set()
  const limitations = []

  for (const limitation of [...(readiness?.limitations ?? []), ...(draft?.limitations ?? [])]) {
    if (!limitation?.key || seen.has(limitation.key)) {
      continue
    }

    seen.add(limitation.key)
    limitations.push(limitation)
  }

  return limitations
}

export function reportDownloadRows(readiness, draft) {
  const rows = new Map()

  for (const [key, download] of Object.entries(readiness?.downloads ?? {})) {
    rows.set(key, download)
  }

  for (const [key, download] of Object.entries(draft?.exports ?? {})) {
    if (!rows.has(key)) {
      rows.set(key, download)
    }
  }

  return Array.from(rows.entries())
}

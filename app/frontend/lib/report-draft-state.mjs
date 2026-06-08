export function humanizeKey(key) {
  return String(key ?? "")
    .replace(/^p(\d)_/, "P$1 ")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
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
    not_implemented: "No implementado",
    not_started: "Sin empezar",
    ready: "Listo",
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

  if (status === "not_implemented" || status === "blocked") {
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
  if (endpoint === "/api/report/draft" || endpoint.includes("report/draft")) {
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
  if (endpoint === "/api/report/draft") {
    return "Borrador P10"
  }

  return humanizeKey(
    String(endpoint ?? "")
      .replace(/^\/api\//, "")
      .replace(/^\/characterization\//, "characterization_")
      .replace(/[/?=&.-]+/g, "_"),
  )
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

export function finalGenerationPending(readiness, draft) {
  return uniqueLimitations(readiness, draft).some((limitation) => limitation.key === "final_report_generation_pending")
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

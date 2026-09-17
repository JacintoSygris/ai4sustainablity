export function localized(value) {
  return value?.es || value?.en || ""
}

export function firstOpenStepKey(guide) {
  const firstStepKey = guide?.sections?.[0]?.steps?.[0]?.key

  return firstStepKey ? [firstStepKey] : []
}

export const TEMPLATE_LOCALES = ["es", "en"]

export const TEMPLATE_DOWNLOAD_LABELS = {
  es: "Descargar en español",
  en: "Download in English",
}

/**
 * @param {string} templateKey
 * @param {string} [locale]
 */
export function templateDownloadPath(templateKey, locale = "es") {
  const safeLocale = TEMPLATE_LOCALES.includes(locale) ? locale : "es"

  return `/double-materiality-guide/templates/${templateKey}.csv?locale=${safeLocale}`
}

export function canContinueFromGuideState({ guide, loadingInitial, errorMessage }) {
  return Boolean(guide) && !loadingInitial && !errorMessage
}

// P7 state helpers: checklist 4 bools, acta 3 fields, guide_status derivation.
export function actaRegistered(state) {
  const a = state?.acta || {}
  return Boolean(a.completed_on && a.method && a.participants)
}

export function checklistComplete(state) {
  const c = state?.checklist || {}
  return Boolean(
    c.identified_stakeholders &&
      c.assessed_impacts &&
      c.assessed_financial_effects &&
      c.reached_conclusions,
  )
}

export function guideProgressLabel(state) {
  if (!state) return "Sin empezar"
  // Ready when acta OR all 4 checklist items are complete; both surface "Análisis registrado".
  if (actaRegistered(state) || checklistComplete(state) || state.guide_status === "ready") return "Análisis registrado"
  const anySet = state.guide_status === "in_progress" ||
    Object.values(state.checklist || {}).some(Boolean) ||
    (state.acta && Object.values(state.acta).some((v) => Boolean(v)))
  if (anySet) return "En curso"
  return "Sin empezar"
}

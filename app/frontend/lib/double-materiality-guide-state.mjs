export function localized(value) {
  return value?.es || value?.en || ""
}

const GUIDE_TITLES = {
  review_p5_p6: "Revisar la caracterización inicial y la propuesta de temas materiales",
  define_boundaries: "Definir los límites de la evaluación",
  stakeholder_input: "Registrar aportaciones de los grupos de interés",
  sync_to_laravel: "Registrar la materialidad final en la aplicación",
  document_decision: "Documentar la decisión",
  return_to_p8: "Volver a la selección final de temas",
}

export function guideTitle(item) {
  return GUIDE_TITLES[item?.key] ?? localized(item?.title)
}

const GUIDE_CHECKS = {
  review_p5_p6: [
    "Confirma el perímetro de la empresa, el ejercicio de información, el sector, el rango de tamaño y los temas AR16 propuestos por la IA.",
    "Anota los temas dudosos de la propuesta para resolverlos durante el taller de análisis de doble materialidad.",
  ],
  define_boundaries: [
    "Separa las operaciones propias de la cadena de valor anterior y posterior.",
    "Registra las hipótesis que puedan afectar a la materialidad de impacto o financiera.",
  ],
  iro_inventory: [
    "Usa los temas AR16 para comprobar que no falta ninguno, no como la decisión de materialidad en sí.",
    "Escribe un impacto, riesgo u oportunidad por fila e indica su ubicación en la cadena de valor y el grupo de interés o canal financiero afectado.",
  ],
  stakeholder_input: [
    "Anota la fuente, la fecha, el grupo de interés y el tema, impacto, riesgo u oportunidad afectado.",
    "Usa estas evidencias fuera de la aplicación; la selección final de temas solo registra los cambios finales en los temas.",
  ],
  impact_materiality: [
    "Puntúa la escala, el alcance, el carácter irremediable y la probabilidad según el método de umbrales de la organización.",
    "Conserva las referencias de las evidencias fuera de la aplicación para su revisión de verificación.",
  ],
  financial_materiality: [
    "Estima los posibles efectos financieros, el horizonte temporal, la probabilidad y la magnitud.",
    "Registra si cada impacto, riesgo u oportunidad alcanza el umbral definido.",
  ],
  decision_log: [
    "Resume qué temas AR16 son materiales y por qué.",
    "Conserva un registro de los temas añadidos, retirados o mantenidos respecto a la propuesta de temas materiales.",
  ],
  sync_to_laravel: [
    "Confirma los temas materiales en la selección final de temas y añade, si procede, los motivos de los cambios.",
    "Si la propuesta incluía E1 y el análisis concluye que no es material, introduce la breve explicación requerida.",
  ],
}

export function guideChecks(step) {
  return GUIDE_CHECKS[step?.key] ?? []
}

export function checklistFlags(state) {
  return Object.fromEntries([
    "identified_stakeholders", "assessed_impacts", "assessed_financial_effects", "reached_conclusions",
  ].map((key) => [key, state?.checklist?.[key] === true]))
}

export function firstOpenStepKey() {
  return []
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

// P7 state helpers (frozen contract: checklist 4 bools, acta 3 fields, guide_status derivation)
export function actaRegistered(state) {
  const a = state?.acta || {}
  return Boolean(a.completed_on && a.method && a.participants)
}

export function checklistComplete(state) {
  const c = checklistFlags(state)
  return Boolean(
    c.identified_stakeholders &&
      c.assessed_impacts &&
      c.assessed_financial_effects &&
      c.reached_conclusions,
  )
}

export function guideProgressLabel(state) {
  if (!state) return "Sin empezar"
  // per frozen: ready when acta OR all 4 checklist; label surfaces "Análisis registrado" on either complete signal
  if (actaRegistered(state) || checklistComplete(state) || state.guide_status === "ready") return "Análisis registrado"
  const anySet = state.guide_status === "in_progress" ||
    Object.values(checklistFlags(state)).some(Boolean) ||
    (state.acta && Object.values(state.acta).some((v) => Boolean(v)))
  if (anySet) return "En curso"
  return "Sin empezar"
}

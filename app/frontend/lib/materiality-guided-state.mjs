// Pure rule engine for P8 guided 4-signal suggestion (client + mirrored contract).
// Exact 7-row precedence used by the guided materiality review. No I/O, no side effects.

export const IMPACT_LEVELS = ["bajo", "medio", "alto", "no_lo_se"]
export const CONFIDENCE_LEVELS = ["baja", "media", "alta"]
export const EXPOSURE_LEVELS = ["normal", "fuerte", "descartada"]

const SUGGESTED_RESULTS = ["material", "no_material", "en_observacion"]
const FINAL_RESULTS = ["material", "no_material"]

const impactSet = new Set(IMPACT_LEVELS)
const confidenceSet = new Set(CONFIDENCE_LEVELS)
const exposureSet = new Set(EXPOSURE_LEVELS)
const suggestedSet = new Set(SUGGESTED_RESULTS)
const finalSet = new Set(FINAL_RESULTS)

function isNoLoSe(v) {
  return v === "no_lo_se"
}

export function suggestTopicResult({ impacto, financiero, confianza, exposicion }) {
  const i = impacto
  const f = financiero
  const c = confianza
  const e = exposicion

  const hasAlto = i === "alto" || f === "alto"
  const anyNoSe = isNoLoSe(i) || isNoLoSe(f)
  const lowConf = c === "baja"

  // Row 1
  if (hasAlto) {
    const revisar = lowConf || anyNoSe
    return { suggested_result: "material", revisar }
  }

  // Row 2
  if (anyNoSe) {
    return { suggested_result: "en_observacion", revisar: false }
  }

  // Row 3
  if (lowConf) {
    return { suggested_result: "en_observacion", revisar: false }
  }

  // Row 4
  const hasMedio = i === "medio" || f === "medio"
  if (hasMedio && e === "fuerte") {
    return { suggested_result: "material", revisar: false }
  }

  // Row 5
  if (hasMedio) {
    return { suggested_result: "en_observacion", revisar: false }
  }

  // Row 6
  const bothBajo = i === "bajo" && f === "bajo"
  const expOk = e === "normal" || e === "descartada"
  const confOk = c === "media" || c === "alta"
  if (bothBajo && expOk && confOk) {
    return { suggested_result: "no_material", revisar: false }
  }

  // Row 7 (fallback, incl. bajo+bajo+fuerte)
  return { suggested_result: "en_observacion", revisar: false }
}

export function resolveObservacion(override) {
  // default pre-resolves to material + revisar (conservative)
  // override to 'no_material' still keeps revisar=true for trace
  if (override === "no_material") {
    return { final_result: "no_material", revisar: true }
  }
  return { final_result: "material", revisar: true }
}

export function deriveDimension(impacto, financiero) {
  const iHigh = impacto === "medio" || impacto === "alto"
  const fHigh = financiero === "medio" || financiero === "alto"

  if (iHigh && fHigh) return "both"
  if (iHigh) return "impact"
  if (fHigh) return "financial"
  return undefined
}

export function buildGuidedAnswer(signals, finalResult, note) {
  const suggestion = suggestTopicResult(signals || {})
  const hasObsSuggestion = suggestion.suggested_result === "en_observacion"

  let final_result
  let revisar = suggestion.revisar

  if (hasObsSuggestion) {
    const resolved = resolveObservacion(finalResult || "material")
    final_result = resolved.final_result
    revisar = resolved.revisar // always true for en_observacion path
  } else {
    final_result = finalResult || suggestion.suggested_result
    if (final_result === "no_material") {
      revisar = false
    }
  }

  const cleanedNote = typeof note === "string" && note.trim() ? note.trim() : undefined

  return {
    impacto: signals?.impacto ?? undefined,
    financiero: signals?.financiero ?? undefined,
    confianza: signals?.confianza ?? undefined,
    exposicion: signals?.exposicion ?? undefined,
    suggested_result: suggestion.suggested_result,
    final_result,
    revisar,
    ...(cleanedNote ? { note: cleanedNote } : {}),
  }
}

export function validateGuidedAnswer(answer) {
  if (!answer || typeof answer !== "object") return false

  const required = ["impacto", "financiero", "confianza", "exposicion", "suggested_result", "final_result", "revisar"]
  for (const k of required) {
    if (!(k in answer)) return false
  }

  if (!impactSet.has(answer.impacto)) return false
  if (!impactSet.has(answer.financiero)) return false
  if (!confidenceSet.has(answer.confianza)) return false
  if (!exposureSet.has(answer.exposicion)) return false
  if (!suggestedSet.has(answer.suggested_result)) return false
  if (!finalSet.has(answer.final_result)) return false
  if (typeof answer.revisar !== "boolean") return false

  if (typeof answer.note === "string" && answer.note.length > 300) return false
  if (answer.note != null && typeof answer.note !== "string") return false

  return true
}

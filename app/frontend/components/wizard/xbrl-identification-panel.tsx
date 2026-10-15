"use client"

import { useReducer, useState } from "react"
import { useLocale } from "@/components/locale-provider"
import { ui } from "@/lib/i18n/messages.mjs"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { LaravelApiError, getXbrlIdentifications, identifyXbrlConcept, reviewXbrlIdentification,
  type XbrlIndicator, type XbrlIdentificationRun, type XbrlIdentificationCandidate } from "@/lib/laravel-api"
import { initialIdentificationState, identificationReducer, canReviewIdentification, identificationRequest, identificationReview } from "@/lib/xbrl-identification-state.mjs"

export type IdentificationSubject = { key: string; label: string; description?: string; language?: string; datapoint_id?: string; reporting_fact_id?: number; value_type?: string; unit?: string | null }
const empty: XbrlIndicator = { label: "", description: "", language: "es" }
const statusCopy: Record<string, string> = {
  map_only: "Solo mapa: la taxonomía no está verificada.", unverified: "Compatibilidad sin verificar; no se puede aceptar.",
  incompatible: "Concepto incompatible; no se puede aceptar.", verified_taxonomy: "Tipo y periodo comprobados contra la taxonomía externa.",
  taxonomy_unconfigured: "No se ha configurado una taxonomía externa.", abstract_concept: "El concepto es abstracto.",
  period_mismatch: "El periodo no es compatible.", type_mismatch: "El tipo de valor no es compatible.",
  unit_mismatch: "La unidad no es compatible.", unit_unverified: "La unidad no se ha verificado.",
  type_or_context_unverified: "Falta verificar el tipo o el contexto.", concept_missing: "El concepto no existe en el paquete verificado.",
  accepted: "Identificación aceptada", rejected: "Identificación rechazada",
  unsupported_language: "El modelo no admite el idioma indicado.", unsupported_context: "El modelo no admite el contexto indicado.",
  zero_textual_overlap: "No hay coincidencia textual suficiente.", no_compatible_candidates: "No hay conceptos compatibles.",
  below_threshold: "La puntuación no alcanza el umbral configurado.", insufficient_margin: "La diferencia entre los primeros candidatos es insuficiente.",
}

export function XbrlIdentificationPanel({ enabled, csrfToken, subjects = [] }: { enabled: boolean; csrfToken?: string; subjects?: IdentificationSubject[] }) {
  const { locale, changing } = useLocale()
  const tr = (text: string) => ui(locale, text)
  const [indicator, setIndicator] = useState<XbrlIndicator>(() => ({ ...empty, language: locale }))
  const [subject, setSubject] = useState<IdentificationSubject | undefined>()
  const [reason, setReason] = useState("")
  const [state, dispatch] = useReducer(identificationReducer, undefined, initialIdentificationState)
  const [history, setHistory] = useState<{ runs: XbrlIdentificationRun[]; page: number; last_page: number } | null>(null)
  const [historyError, setHistoryError] = useState(false)
  const run = state.run as XbrlIdentificationRun | null
  if (!enabled) return null

  function update(patch: Partial<XbrlIndicator>) {
    dispatch({ type: "input" }); setIndicator(current => ({ ...current, ...patch })); setReason("")
  }
  function selectSubject(key: string) {
    const selected = subjects.find(item => item.key === key)
    setSubject(selected); setIndicator(selected ? { ...empty, label: selected.label.slice(0, 300), description: selected.description ?? "", language: selected.language ?? locale, value_type: selected.value_type ?? null, unit: selected.unit ?? null } : { ...empty, language: locale })
    setReason(""); dispatch({ type: "input" })
  }
  async function identify() {
    const generation = state.generation
    dispatch({ type: "start", generation })
    try {
      const response = await identifyXbrlConcept(identificationRequest(indicator, subject), csrfToken)
      dispatch({ type: "result", generation, run: response.data })
    } catch (error) { dispatch({ type: "error", generation, status: error instanceof LaravelApiError ? error.status : 0 }) }
  }
  async function review(candidate: XbrlIdentificationCandidate, decision: "accepted" | "rejected") {
    if (!run || !canReviewIdentification(state, candidate, reason, enabled && !changing, decision)) return
    const generation = state.generation
    dispatch({ type: "start", generation })
    try {
      const response = await reviewXbrlIdentification(run.id, identificationReview(run, candidate, decision, reason), csrfToken)
      dispatch({ type: "result", generation, run: response.data })
    } catch (error) { dispatch({ type: "error", generation, status: error instanceof LaravelApiError ? error.status : 0 }) }
  }
  async function loadHistory(page: number) {
    setHistoryError(false)
    try { setHistory((await getXbrlIdentifications(page)).data) } catch { setHistoryError(true) }
  }
  return <section className="space-y-3 rounded-lg border border-border p-4" aria-label={tr("Identificar concepto XBRL de las NEIS")}>
    <h3 className="font-semibold">{tr("Identificar concepto XBRL de las NEIS")}</h3>
    <p className="text-sm">{tr("No cambia la doble importancia relativa ni etiqueta automáticamente el informe.")}</p>
    <p className="text-sm text-muted-foreground">{tr("Las puntuaciones ordenan candidatos; no son probabilidades de acierto. Revisa el concepto y sus limitaciones antes de aceptar.")}</p>
    <label className="block text-sm">{tr("Indicador")}
      <select className="block w-full rounded border p-2" value={subject?.key ?? ""} onChange={event => selectSubject(event.target.value)}>
        <option value="">{tr("Descripción libre sin vincular a un punto de datos")}</option>
        {subjects.map(item => <option key={item.key} value={item.key}>{item.datapoint_id ?? item.key} · {item.label}</option>)}
      </select>
    </label>
    <label className="block text-sm">{tr("Nombre del indicador")}<Input value={indicator.label} maxLength={300} onChange={e => update({ label: e.target.value })} /></label>
    <label className="block text-sm">{tr("Descripción del indicador")}<Textarea value={indicator.description} maxLength={4000} onChange={e => update({ description: e.target.value })} /></label>
    <div className="flex flex-wrap gap-3">
      <label className="text-sm">{tr("Idioma del texto")}<select className="block rounded border p-2" value={indicator.language} onChange={e => update({ language: e.target.value })}><option value="es">{tr("Español")}</option><option value="en">{tr("Inglés")}</option></select></label>
      <label className="text-sm">{tr("Tipo de valor")}<select className="block rounded border p-2" value={indicator.value_type ?? ""} onChange={e => update({ value_type: e.target.value || null })}>
        <option value="">{tr("Sin especificar")}</option>{[["text", "Texto"], ["number", "Número"], ["integer", "Entero"], ["monetary", "Monetario"], ["boolean", "Booleano"], ["date", "Fecha"]].map(([value, label]) => <option key={value} value={value}>{tr(label)}</option>)}
      </select></label>
      <label className="text-sm">{tr("Tipo de periodo")}<select className="block rounded border p-2" value={indicator.period_type ?? ""} onChange={e => update({ period_type: e.target.value || null })}><option value="">{tr("Sin especificar")}</option><option value="duration">{tr("Duración")}</option><option value="instant">{tr("Instante")}</option></select></label>
      <label className="text-sm">{tr("Unidad (ISO 4217 o pure)")}<Input value={indicator.unit ?? ""} maxLength={64} onChange={e => update({ unit: e.target.value || null })} /></label>
    </div>
    <Button type="button" disabled={state.busy || changing || !(indicator.label.trim() || indicator.description.trim())} onClick={identify}>{tr("Identificar concepto XBRL de las NEIS")}</Button>
    <div aria-live="polite">
      {state.busy ? <p>{tr("Consultando...")}</p> : null}
      {state.error ? <p role="alert">{tr(state.error === "conflict" ? "El contexto o la revisión han cambiado. Consulta el historial y vuelve a identificar." : "No se pudo completar la identificación o revisión.")}</p> : null}
      {run ? <div className="space-y-3">
        <p className="break-all text-xs">{run.model_version} · {run.artifact_sha256}</p>
        {run.response_json.abstained ? <p>{tr("El modelo se abstiene.")} {run.response_json.reason_codes.map(code => tr(statusCopy[code] ?? "Compatibilidad sin verificar; no se puede aceptar.")).join(" ")}</p> : null}
        <label className="block text-sm">{tr("Motivo de la decisión (obligatorio)")}<Textarea value={reason} maxLength={1000} onChange={e => setReason(e.target.value)} /></label>
        <ol className="space-y-3">{run.response_json.candidates.map(candidate => <li key={candidate.qname} className="rounded border p-3">
          <p className="break-all">{candidate.rank}. {candidate.qname}</p>
          <p>{tr("Puntuación sin calibrar")}: {candidate.score.toLocaleString(locale, { minimumFractionDigits: 4, maximumFractionDigits: 4 })}</p>
          <p className="text-sm">{tr(statusCopy[candidate.validation_status])}</p>
          <p className="text-sm">{candidate.validation_reasons.map(code => tr(statusCopy[code] ?? "Compatibilidad sin verificar; no se puede aceptar.")).join(" ")}</p>
          <div className="flex gap-2"><Button type="button" disabled={!canReviewIdentification(state, candidate, reason, enabled && !changing, "accepted")} onClick={() => review(candidate, "accepted")}>{tr("Aceptar identificación")}</Button>
          <Button type="button" variant="outline" disabled={!canReviewIdentification(state, candidate, reason, enabled && !changing, "rejected")} onClick={() => review(candidate, "rejected")}>{tr("Rechazar identificación")}</Button></div>
          {run.reviews.filter(r => r.candidate_qname === candidate.qname).map(r => <p key={r.sequence} className="mt-2 text-sm">{tr(statusCopy[r.decision])} · {tr("Revisor")}: {r.reviewer_id} · {r.created_at} · {r.reason}</p>)}
        </li>)}</ol>
      </div> : null}
    </div>
    <Button type="button" variant="outline" onClick={() => loadHistory(1)}>{tr("Cargar historial de identificaciones")}</Button>
    {historyError ? <p role="alert">{tr("No se pudo cargar el historial.")}</p> : null}
    {history ? <div className="space-y-2"><ul>{history.runs.map(item => <li key={item.id}><button type="button" className="text-left underline" onClick={() => {
      setIndicator(item.input_json.indicator); setSubject({ key: item.subject_key, label: item.input_json.indicator.label, ...(item.reporting_fact_id ? { reporting_fact_id: item.reporting_fact_id } : {}), ...(item.datapoint_id ? { datapoint_id: item.datapoint_id } : {}) }); setReason(""); dispatch({ type: "history", run: item })
    }}>{item.created_at} · {item.input_json.indicator.label || item.subject_key}</button></li>)}</ul>
    <Button type="button" variant="outline" disabled={history.page <= 1} onClick={() => loadHistory(history.page - 1)}>{tr("Anterior")}</Button>{" "}<Button type="button" variant="outline" disabled={history.page >= history.last_page} onClick={() => loadHistory(history.page + 1)}>{tr("Siguiente")}</Button></div> : null}
  </section>
}

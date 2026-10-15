"use client"

import { DmaNlpPanel } from "@/components/wizard/dma-nlp-panel"
import { nlpIroCandidates } from "@/lib/dma-nlp-state.mjs"
import type { DmaNlpRun, DmaNlpCandidate } from "@/lib/laravel-api"
import { useEffect, useState } from "react"
import { useLocale } from "@/components/locale-provider"
import { useSystemMessage, systemCopy } from "@/lib/i18n/use-system-message"
import { ui, formatUi } from "@/lib/i18n/messages.mjs"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { dmaReferencedImportIds, dmaReviewRows } from "@/lib/dma-import-state.mjs"
import { LaravelApiError, getLaravelDmaImport, uploadLaravelDmaImport, laravelApiUrl,
  type LaravelDmaImport, type LaravelDmaSuggestion, type LaravelIroReview, type LaravelIroCandidate } from "@/lib/laravel-api"

type IroReviewDraft = Partial<LaravelIroReview> & { confirmed?: boolean; recovery_alternative?: Partial<LaravelIroReview> }

export function DmaImportPanel({ characterizationId, csrfToken, disabled, topicName, onApply, iroContext, nlp }: {
  nlp?: { context: string; revision: number; inferredTopics: Record<string, number[]>; selectedTopics: Set<number>;
    onAccepted: (run: DmaNlpRun, candidate: DmaNlpCandidate, sourceTopicId: number) => void }
  characterizationId: number; csrfToken?: string; disabled: boolean
  topicName: (id: number) => string
  iroContext?: { reviews: Record<string, IroReviewDraft>; candidates: Record<string, LaravelIroCandidate[]>;
    evidence: Array<{ import_id: number; iro_rows: LaravelDmaImport["iro_rows"] }>; references: Record<string, number>
    onReview: (review: Partial<LaravelIroReview>, topicId: number, confirmed: boolean) => void
    onResolve: (iroId: string, useAlternative: boolean) => void }
  onApply: (record: LaravelDmaImport, suggestion: LaravelDmaSuggestion) => void
}) {
  const { locale } = useLocale()
  const tr = (text: string) => ui(locale, text)
  const [record, setRecord] = useState<LaravelDmaImport | null>(null)
  const [iro, setIro] = useState<File | null>(null)
  const [stakeholders, setStakeholders] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<{ locale: string; lines: string[] }>({ locale, lines: [] })
  const [generalError, setGeneralError] = useSystemMessage(null)
  const [loadFailed, setLoadFailed] = useState(false)
  const [notice, setNotice] = useSystemMessage(null)
  const [ready, setReady] = useState(false)
  const [referencedImports, setReferencedImports] = useState<{ characterizationId: number; records: LaravelDmaImport[] } | null>(null)
  const [loadAttempt, setLoadAttempt] = useState(0)
  const importIdsKey = JSON.stringify(dmaReferencedImportIds(iroContext?.references))
  useEffect(() => {
    let active = true
    getLaravelDmaImport(characterizationId, JSON.parse(importIdsKey)).then(response => {
      if (active) {
        setRecord(response.data); setReady(true); setLoadFailed(false)
        setReferencedImports({ characterizationId, records: response.referenced_imports ?? [] })
      }
    }).catch(() => { if (active) setLoadFailed(true) })
    return () => { active = false }
  }, [characterizationId, importIdsKey, loadAttempt])
  const upload = async () => {
    if (!iro || busy || disabled) return
    if (iro.size > 1048576 || (stakeholders?.size ?? 0) > 1048576) {
      setGeneralError(systemCopy("Cada CSV debe ocupar como máximo 1 MiB.")); return
    }
    setBusy(true); setErrors({ locale, lines: [] }); setGeneralError(null); setNotice(null)
    try {
      const data = new FormData()
      data.append("iro_file", iro)
      if (stakeholders) data.append("stakeholder_file", stakeholders)
      const response = await uploadLaravelDmaImport(characterizationId, data, { csrfToken })
      setRecord(response.data); setNotice(systemCopy("Importación guardada. Revisa las sugerencias antes de aplicarlas."))
    } catch (error) {
      const payload = error instanceof LaravelApiError ? error.payload as { errors?: Record<string, string[]> } : null
      if (payload?.errors) setErrors({ locale, lines: Object.entries(payload.errors).flatMap(([field, messages]) => messages.map(message => `${field}: ${message}`)) })
      else setGeneralError(systemCopy("No se pudo importar el registro ADM."))
    } finally { setBusy(false) }
  }
  const labels = { material: "Material", no_material: "No material", review: "Revisar" }
  const reasons = { conflicting_flags: "Las decisiones y las dimensiones se contradicen.", at_least_one_material_iro: "Al menos un IRO supera el umbral declarado.",
    all_iros_not_material: "Todos los IRO del tema se declaran no materiales.", incomplete_assessment: "Hay IRO pendientes de evaluación." }
  const records = [...(iroContext?.evidence ?? []), ...(record ? [{ import_id: record.id, iro_rows: record.iro_rows }] : []),
    ...(referencedImports?.characterizationId === characterizationId ? referencedImports.records
      .filter(source => dmaReferencedImportIds(iroContext?.references).includes(source.id))
      .map(source => ({ import_id: source.id, iro_rows: source.iro_rows })) : [])]
  const reviewRows: Array<{ importId: number; row: LaravelDmaImport["iro_rows"][number] }> = dmaReviewRows(records)
  return <Card><CardContent className="space-y-4 pt-6">
    <h2 id="dma-import-title" className="font-semibold">{tr("Resultados importados del ADM")}</h2>
    <p className="text-sm text-muted-foreground">{tr("Carga los CSV cumplimentados de las plantillas del paso 3. Aplicar modifica el borrador; después debes confirmar el paso 4. Las puntuaciones no fijan un umbral automático.")}</p>
    <p className="text-sm">{tr("En las consultas, enlaza con el ID AR16 o IRO:número de fila del CSV. El primer IRO está en la fila 2.")}</p>
    <fieldset aria-labelledby="dma-import-title" disabled={disabled || busy || !ready} className="space-y-3">
      <div><label htmlFor="dma-iro" className="text-sm font-medium">{tr("Registro IRO (CSV, obligatorio)")}</label>
        <Input id="dma-iro" type="file" accept=".csv,text/csv" onChange={event => setIro(event.target.files?.[0] ?? null)} /></div>
      <div><label htmlFor="dma-stakeholders" className="text-sm font-medium">{tr("Consultas a grupos de interés (CSV, opcional)")}</label>
        <Input id="dma-stakeholders" type="file" accept=".csv,text/csv" onChange={event => setStakeholders(event.target.files?.[0] ?? null)} /></div>
      <Button type="button" disabled={!iro} onClick={upload}>{tr(busy ? "Importando..." : "Importar CSV")}</Button>
    </fieldset>
    {loadFailed && <div role="alert" className="text-sm text-destructive"><p>{tr("No se pudo cargar la importación ADM.")}</p>
      <Button type="button" variant="outline" onClick={() => setLoadAttempt(value => value + 1)}>{tr("Reintentar")}</Button></div>}
    {generalError && <p role="alert" className="text-sm text-destructive">{generalError}</p>}
    {errors.locale === locale && errors.lines.length > 0 && <ul role="alert" className="text-sm text-destructive">{errors.lines.map((error, index) => <li key={index}>{error}</li>)}</ul>}
    <p role="status" aria-live="polite" className="text-sm">{notice}</p>
    {record && nlp && <DmaNlpPanel key={record.id} enabled characterizationId={characterizationId} record={record} stage="p8" context={nlp.context} revision={nlp.revision} csrfToken={csrfToken} disabled={disabled || busy} onAccepted={(run, candidate) => {
      const source = record.iro_rows.find(row => row.iro_id === candidate.iro_id)
      if (source) nlp.onAccepted(run, candidate, source.topic_id)
    }} />}
    {record && <>
      <p className="break-all text-sm">{formatUi(locale, "Importación {0} · {1}", [record.id, record.uploaded_at])}</p>
      {Object.entries(record.sources).map(([field, source]) => <p className="break-all text-xs text-muted-foreground" key={field}>{formatUi(locale, "{0} · SHA-256: {1}", [source.original_name, source.sha256])}</p>)}
      <a className="text-sm underline" href={laravelApiUrl(`/characterizations/${characterizationId}/dma-imports/${record.id}/iro.csv`)}>{tr("Descargar registro importado")}</a>
      <ul className="space-y-3">{record.suggestions.map(suggestion => <li key={suggestion.topic_id} className="rounded-md border p-3 text-sm">
        <h3 className="font-medium">{formatUi(locale, "AR16 {0}: {1} · {2}", [suggestion.topic_id, topicName(suggestion.topic_id), tr(labels[suggestion.suggestion])])}</h3>
        <p>{tr(reasons[suggestion.reason_code])}</p>
        <p>{formatUi(locale, "Filas IRO: {0}. Filas de consultas: {1}.", [suggestion.iro_rows.join(", "), suggestion.stakeholder_rows.join(", ") || "—"])}</p>
        <p>{formatUi(locale, "Filas que sustentan la sugerencia: {0}.", [suggestion.supporting_iro_rows.join(", ")])}</p>
        <details className="my-2">
          <summary className="cursor-pointer">{tr("Ver filas de origen")}</summary>
          <ul>{record.iro_rows.filter(row => row.topic_id === suggestion.topic_id).map(row => <li key={row.row} className="my-2 break-words">
            <p>{formatUi(locale, "Fila {0}: {1}", [row.row, row.iro_description])}</p>
            <p>{formatUi(locale, "Impacto: {0}; financiero: {1}; resultado: {2}.", [row.impact_materiality_score || "—", row.financial_materiality_score || "—", row.threshold_result || "—"])}</p>
            <p>{formatUi(locale, "Referencia: {0}", [row.evidence_reference || "—"])}</p>
          </li>)}</ul>
        </details>
        <p>{formatUi(locale, "Consultas: {0}; apoyos explícitos: {1}; objeciones explícitas: {2}.", [suggestion.stakeholder_count, suggestion.stakeholder_support_count, suggestion.stakeholder_opposition_count])}</p>
        <Button type="button" size="sm" variant="outline" className="mt-2" disabled={disabled || busy || suggestion.suggestion === "review"}
          onClick={() => { onApply(record, suggestion); setNotice(systemCopy("Sugerencia aplicada al borrador. La confirmación sigue pendiente.")) }}
          aria-label={formatUi(locale, "Aplicar sugerencia al tema AR16 {0}", [suggestion.topic_id])}>{tr("Aplicar al borrador")}</Button>
      </li>)}</ul>
    </>}
    {iroContext && <section aria-label={tr("Contexto IRO revisado")} className="space-y-4">
      <h3>{tr("Contexto IRO revisado")}</h3><p>{tr("Se mantienen todas las obligaciones NEIS")}</p>
      {reviewRows.map(({ importId, row }) => <IroReviewEditor key={`${importId}|${row.iro_id}`}
        row={row} importId={importId} review={iroContext.reviews[row.iro_id!] ?? {}}
        candidates={nlp ? nlpIroCandidates(row, nlp.inferredTopics, nlp.selectedTopics, iroContext.candidates) : iroContext.candidates[row.topic_id] ?? []} disabled={disabled || busy}
        onResolve={useAlternative => iroContext.onResolve(row.iro_id!, useAlternative)}
        onChange={(review, confirmed) => iroContext.onReview(review, row.topic_id, confirmed)} />)}
    </section>}
  </CardContent></Card>
}

function IroReviewEditor({ row, importId, review, candidates, disabled, onChange, onResolve }: {
  row: LaravelDmaImport["iro_rows"][number]; importId: number
  review: IroReviewDraft; candidates: LaravelIroCandidate[]; disabled: boolean
  onChange: (review: Partial<LaravelIroReview>, confirmed: boolean) => void
  onResolve: (useAlternative: boolean) => void
}) {
  const { locale } = useLocale()
  const tr = (text: string) => ui(locale, text)
  const current = review.import_id === importId ? review : {}
  const base = { import_id: importId, iro_id: row.iro_id!, description: row.iro_description,
    locations: [], groups: [], reason: '', focus_datapoint_ids: [], ...current }
  const change = (patch: Partial<LaravelIroReview>) => onChange({ ...base, ...patch }, false)
  const select = (key: 'decision' | 'type' | 'dimension', title: string, options: [string, string][]) => <label className="block">
    {tr(title)}<select className="block border p-2" value={base[key] ?? ''} onChange={event => change({ [key]: event.target.value, ...(key === 'decision' ? { focus_datapoint_ids: [] } : {}) })}>
      <option value="">{tr("Selecciona una opción")}</option>{options.map(([value, label]) => <option key={value} value={value}>{tr(label)}</option>)}
    </select></label>
  const checks = (key: 'locations' | 'groups' | 'focus_datapoint_ids', title: string, options: [string, string][]) => <fieldset className="space-y-1"><legend>{tr(title)}</legend>
    {options.map(([value, label]) => <label key={value} className="block"><input type="checkbox" checked={base[key].includes(value)}
      onChange={event => change({ [key]: event.target.checked ? [...base[key], value] : base[key].filter(item => item !== value) })} /> {key === 'focus_datapoint_ids' ? label : tr(label)}</label>)}
  </fieldset>
  return <details className="border rounded p-3"><summary>{formatUi(locale, "Fila {0}: {1}", [row.row, row.iro_description])}</summary>
    <fieldset disabled={disabled} className="space-y-3 mt-3"><legend>{`IRO ${row.iro_id} · AR16 ${row.topic_id}`}</legend>
      {current.recovery_alternative && <div role="alert" className="border p-3 space-y-2">
        <p>{tr("Hay dos versiones de este IRO. Compara la alternativa con los campos y elige antes de confirmar.")}</p>
        <dl>{([
          ['description', 'Descripción revisada'], ['decision', 'Decisión revisada'], ['type', 'Tipo revisado'],
          ['dimension', 'Dimensión revisada'], ['locations', 'Ubicación del IRO'], ['groups', 'Colectivos afectados'],
          ['group', 'Grupo afectado'], ['channel', 'Canal financiero'], ['reason', 'Motivo (obligatorio al excluir)'],
          ['focus_datapoint_ids', 'Indicadores vinculados al IRO'],
        ] as const).map(([key, label]) => {
          const value = current.recovery_alternative?.[key]
          const labels: Record<string, string> = { material: 'Material', not_material: 'No material', excluded: 'Excluido',
            impact: 'Impacto', risk: 'Riesgo', opportunity: 'Oportunidad', financial: 'Financiera', both: 'Ambas',
            own_operations: 'Operaciones propias', upstream: 'Cadena de valor anterior', downstream: 'Cadena de valor posterior', internal: 'Internos', external: 'Externos' }
          const text = ['decision', 'type', 'dimension', 'locations', 'groups'].includes(key)
            ? (Array.isArray(value) ? value : [value ?? '']).map(item => tr(labels[item] ?? item)).join(', ')
            : Array.isArray(value) ? value.join(', ') : value
          return <div key={key}><dt className="font-medium">{tr(label)}</dt><dd className="break-words">{text || '—'}</dd></div>
        })}</dl>
        <Button type="button" variant="outline" onClick={() => onResolve(false)}>{tr("Conservar los campos mostrados")}</Button>
        <Button type="button" variant="outline" onClick={() => onResolve(true)}>{tr("Usar la versión alternativa")}</Button>
      </div>}
      {select('decision', 'Decisión revisada', [['material', 'Material'], ['not_material', 'No material'], ['excluded', 'Excluido']])}
      {select('type', 'Tipo revisado', [['impact', 'Impacto'], ['risk', 'Riesgo'], ['opportunity', 'Oportunidad']])}
      {select('dimension', 'Dimensión revisada', [['impact', 'Impacto'], ['financial', 'Financiera'], ['both', 'Ambas']])}
      {checks('locations', 'Ubicación del IRO', [['own_operations', 'Operaciones propias'], ['upstream', 'Cadena de valor anterior'], ['downstream', 'Cadena de valor posterior']])}
      {checks('groups', 'Colectivos afectados', [['internal', 'Internos'], ['external', 'Externos']])}
      {(['description', 'group', 'channel', 'reason'] as const).map((key, index) => <label className="block" key={key}>
        {tr(['Descripción revisada', 'Grupo afectado', 'Canal financiero', 'Motivo (obligatorio al excluir)'][index])}
        <textarea className="block border w-full p-2" maxLength={key === 'reason' ? 300 : 2000} value={base[key] ?? ''} onChange={event => change({ [key]: event.target.value })} />
      </label>)}
      {base.decision === 'material' && <details open><summary>{tr("Indicadores vinculados al IRO")}</summary>
        <p>{tr("Confirma el tema como material para elegir sus indicadores.")}</p>
        {checks('focus_datapoint_ids', 'Indicadores vinculados al IRO', candidates.map(point => [point.id, `${point.id} · ${point.name} · ${point.dr}${point.transversal ? ' · '+tr('Transversal') : ''}`]))}
      </details>}
      <label className="block"><input type="checkbox" disabled={Boolean(current.recovery_alternative)} checked={current.confirmed === true} onChange={event => onChange(base, event.target.checked)} /> {tr("He revisado este IRO y sus asociaciones")}</label>
    </fieldset>
  </details>
}

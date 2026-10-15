"use client"
import { useLayoutEffect, useRef, useState } from 'react'
import { useLocale } from '@/components/locale-provider'
import { ui } from '@/lib/i18n/messages.mjs'
import { Button } from '@/components/ui/button'
import { nlpRequest, nlpResponseCurrent, nlpReviewTransition } from '@/lib/dma-nlp-state.mjs'
import { analyseLaravelDma, reviewLaravelDma, type DmaNlpRun, type DmaNlpCandidate, type DmaNlpInput, type LaravelDmaImport } from '@/lib/laravel-api'

export function DmaNlpPanel({ enabled, characterizationId, record, stage, context, revision, csrfToken, disabled, onAccepted, onReviewed }: {
  enabled: boolean; characterizationId: number; record: Pick<LaravelDmaImport, 'id' | 'iro_rows'>
  stage: 'p8' | 'p9'; context: string; revision: number; csrfToken?: string; disabled?: boolean
  onAccepted?: (run: DmaNlpRun, candidate: DmaNlpCandidate) => void
  onReviewed?: (run: DmaNlpRun, candidate: DmaNlpCandidate) => void
}) {
  const { locale } = useLocale(); const tr = (text: string) => ui(locale, text)
  const [page, setPage] = useState(0)
  const [published, setPublished] = useState<{ generation: string; run: DmaNlpRun } | null>(null)
  const [busy, setBusy] = useState(false); const [failure, setFailure] = useState<string | null>(null)
  const generation = `${enabled}|${context}|${characterizationId}|${record.id}|${stage}|${locale}|${page}`
  const current = useRef(generation)
  const callbacks = useRef({ onAccepted, onReviewed })
  const pending = useRef(false)
  const run = published?.generation === generation ? published.run : null
  const error = failure === generation
  // Parent drafts can change while transport is pending, without changing authority.
  useLayoutEffect(() => { callbacks.current = { onAccepted, onReviewed } }, [onAccepted, onReviewed])
  useLayoutEffect(() => {
    current.current = generation
    return () => { current.current = 'unmounted' }
  }, [generation])
  const perform = async (candidate?: DmaNlpCandidate, decision?: 'accepted' | 'rejected') => {
    if (!enabled || disabled || pending.current) return
    const input = nlpRequest(enabled, context, record.iro_rows, page, stage, locale, revision) as DmaNlpInput | null
    if (!input) return
    const token = current.current
    pending.current = true; setBusy(true); setFailure(null)
    try {
      const response = candidate && run && decision
        ? await reviewLaravelDma(characterizationId, run.id, { candidate_id: candidate.candidate_id, decision, expected_context: context, expected_revision: revision }, csrfToken)
        : await analyseLaravelDma(characterizationId, record.id, input, csrfToken)
      if (token !== current.current || !nlpResponseCurrent(context, context, response.data)) return
      const next = candidate && decision ? nlpReviewTransition(response.data, candidate.candidate_id, decision) as DmaNlpRun : response.data
      setPublished({ generation: token, run: next })
      const accepted = next.candidates.find(item => item.candidate_id === candidate?.candidate_id)
      if (accepted?.decision === 'accepted') callbacks.current.onAccepted?.(next, accepted)
      if (accepted?.decision) callbacks.current.onReviewed?.(next, accepted)
    } catch { if (token === current.current) setFailure(token) }
    finally { pending.current = false; setBusy(false) }
  }
  if (!enabled) return null
  const pages = Math.ceil(record.iro_rows.length / 20)
  return <section className="space-y-3 rounded border p-3" aria-label={tr('Indicadores NEIS propuestos')}>
    <h3 className="font-semibold">{tr('Indicadores NEIS propuestos')}</h3>
    <p>{tr('Estas propuestas no modifican las obligaciones de información.')}</p>
    <p>{tr('La similitud no es una probabilidad. Revisa la cita y el contexto del IRO.')}</p>
    <div className="flex flex-wrap gap-2 items-center">
      <Button type="button" disabled={disabled || busy || page === 0} onClick={() => setPage(value => value - 1)}>{tr('Página anterior')}</Button>
      <span>{tr('Lote')} {page + 1} / {pages}</span>
      <Button type="button" disabled={disabled || busy || page + 1 >= pages} onClick={() => setPage(value => value + 1)}>{tr('Página siguiente')}</Button>
      <Button type="button" disabled={disabled || busy} onClick={() => perform()}>{tr('Analizar textos de doble importancia relativa')}</Button>
    </div>
    <p role="status" aria-live="polite">{busy ? tr('Analizando textos...') : run ? tr('Análisis disponible para revisión.') : ''}</p>
    {error && <p role="alert">{tr('No se pudo completar el análisis o la revisión. Actualiza el contexto y vuelve a intentarlo.')}</p>}
    {run?.result.iros.map(iro => <div key={iro.iro_id} className="space-y-2 border-t pt-2">
      <p className="break-all text-xs">{`IRO ${iro.iro_id}`}</p>
      <p>{record.iro_rows.find(row => row.iro_id === iro.iro_id)?.iro_description}</p>
      {Object.entries(iro.levels).map(([level, result]) => result.abstention && <p key={level}>{tr({ topic: 'Tema AR16', dr: 'Requisito de divulgación', datapoint: 'Indicador' }[level] ?? level)}: {tr(result.abstention === 'ambiguous' ? 'Texto ambiguo' : 'Sin evidencia suficiente')}</p>)}
      {run.candidates.filter(candidate => candidate.iro_id === iro.iro_id).map(candidate => <article key={candidate.candidate_id} className="space-y-2 rounded border p-2">
        <p>{tr({ topic: 'Tema AR16', dr: 'Requisito de divulgación', datapoint: 'Indicador' }[candidate.level])} · {candidate.id} · {candidate.label}</p>
        <p>{tr('Similitud')} {candidate.score.toFixed(3)}</p>
        {Boolean(candidate.related_datapoint_ids?.length) && <p>{tr('Indicadores propuestos compatibles')}: {candidate.related_datapoint_ids?.join(', ')}</p>}
        {candidate.spans.map(span => <blockquote key={span.start} className="border-l-2 pl-3 whitespace-pre-wrap">{span.quote}<p className="text-xs break-all">{span.start}–{span.end} · {span.catalogue_ref}</p></blockquote>)}
        <p>{tr(candidate.decision === 'accepted' ? 'Vínculo aceptado' : candidate.decision === 'rejected' ? 'Propuesta rechazada' : 'Pendiente de revisión')}</p>
        {!candidate.in_scope && <p>{tr('Propuesta informativa. Revisa el tema en doble importancia relativa antes de vincular el indicador.')}</p>}
        <Button type="button" variant="outline" disabled={disabled || busy || Boolean(candidate.decision) || !candidate.in_scope} onClick={() => perform(candidate, 'accepted')}>{tr('Aceptar vínculo')}</Button>{' '}
        <Button type="button" variant="outline" disabled={disabled || busy || Boolean(candidate.decision)} onClick={() => perform(candidate, 'rejected')}>{tr('Rechazar propuesta')}</Button>
        {stage === 'p8' && candidate.decision === 'accepted' && <Button type="button" variant="link" disabled={disabled || busy} onClick={() => onAccepted?.(run, candidate)}>{tr('Aplicar al borrador')}</Button>}
        {stage === 'p9' && candidate.decision === 'accepted' && <Button type="button" variant="link" onClick={() => (onReviewed ?? onAccepted)?.(run, candidate)}>{tr('Abrir respuesta del indicador')}</Button>}
      </article>)}
    </div>)}
  </section>
}

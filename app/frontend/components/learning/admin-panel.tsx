"use client"

import { useEffect, useState } from "react"
import { useLocale } from "@/components/locale-provider"
import { ui } from "@/lib/i18n/messages.mjs"
import { getLaravelSession, laravelApi } from "@/lib/laravel-api"
import { canLearningAction, learningCheckLabels, learningStatusLabels, learningLoopLabels, learningFallbackLabels } from "@/lib/learning-admin.mjs"

type Metric = { value: number | null; unavailable_reason?: string | null; ci: { lower: number | null; upper: number | null; valid_replicates: number } }
type Section = { positive_support: number; negative_support: number; unknown_support: number; predictors: Record<string, { tp: number; fp: number; fn: number; tn: number; precision: Metric; recall: Metric; f1: Metric }>; paired_delta: Record<string, Metric> }
type Gate = { schema?: string; passed: boolean; protocol_sha256?: string; primary?: { threshold: number; passed: boolean; reason: string | null }; failure_reasons?: string[]; policy?: { confidence: number; replicates: number }; report?: Record<string, { unit: string; companies: number; micro: Section; per_topic?: Record<string, Section> }>; sets: { name: string; served_recall: number | null; candidate_recall: number | null; lost_true_positives: number }[] }
type Candidate = { id: string; sha256: string; eligible: boolean; comparator?: { baseline_identity: string; effective: string | null; effective_known: boolean }; events_applied?: number; gate?: Gate }

type Action = { id: string; loop: string; action: string; status: string; admin_id: number | null; created_at: string; reason: string; before_sha256: string | null; after_sha256: string | null; error_code: string | null }
type Status = {
  gate_metrics_enabled?: boolean; checks: Record<string, boolean>; engine_enabled: boolean; synthetic_only: boolean; scheduled: boolean; bandit_mode: string
  consented_users: number; pending_events: number; processed_events: number
  wp13: { selected: string | null; effective: string | null; fallback_reason: string | null; serving?: { engine_enabled: boolean; serving_enabled: boolean; bandit_mode: string; baseline: { serving_identity_sha256: string; runtime_config: { score_threshold: number; policy_active: Record<string, boolean> } } | null }; active: string | null; active_sha256: string | null; candidates: Candidate[] } | null
  wp23: { candidate: Candidate | null; active: Candidate | null; previous: Candidate | null } | null
  runs: { id: string; status: string; created_at: string }[]; actions: Action[]
}

export function LearningAdminPanel() {
  const { locale } = useLocale()
  const tr = (text: string) => ui(locale, text)
  const [state, setState] = useState<Status | null>(null)
  const [reason, setReason] = useState("")
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState("")
  async function refresh(candidate?: string) {
    try {
      const result = await laravelApi<{ data: Status }>("/admin/learning" + (candidate ? `?candidate=${encodeURIComponent(candidate)}` : ""), { cache: "no-store", timeoutMs: 25000 })
      setState(result.data)
    } catch { setState(null); setNotice("No se pudo consultar el aprendizaje. Comprueba tu sesión y tus permisos.") }
  }
  useEffect(() => { void refresh() }, [])
  async function act(loop: string, action: string, candidate?: Candidate) {
    if (busy || reason.trim().length < 3 || !canLearningAction(state, loop, action, candidate)) return
    setBusy(true); setNotice("")
    try {
      const session = await getLaravelSession()
      await laravelApi("/admin/learning/actions", { method: "POST", csrfToken: session.data.csrf_token, timeoutMs: 25000,
        body: { loop, action, reason: reason.trim(), ...(candidate ? { candidate: candidate.id } : {}) } })
      setNotice("Acción en cola. Actualiza para consultar el resultado.")
      await refresh()
    } catch { setNotice("No se pudo ejecutar la acción. Actualiza y revisa los requisitos y la auditoría.") }
    finally { setBusy(false) }
  }
  function button(loop: string, action: string, candidate?: Candidate) {
    return <button className="rounded border px-3 py-2 disabled:opacity-50" disabled={busy || reason.trim().length < 3 || !canLearningAction(state, loop, action, candidate)}
      onClick={() => void act(loop, action, candidate)}>{tr(loop === "wp13" && action === "rollback" && !state?.engine_enabled ? "Restablecer modelo base" : learningStatusLabels[action])}</button>
  }
  return <article className="space-y-6">
    <h1 className="text-2xl font-bold">{tr("Administración del aprendizaje")}</h1>
    <p>{tr("Solo se utilizan revisiones con consentimiento vigente. Los candidatos requieren promoción manual y no garantizan una mejora.")}</p>
    <button className="rounded border px-3 py-2" disabled={busy} onClick={() => void refresh()}>{tr("Actualizar estado")}</button>
    <p role="status" aria-live="polite">{tr(notice)}</p>
    {state && <>
      <section className="rounded border p-4 space-y-2"><h2 className="font-semibold">{tr("Requisitos y actividad")}</h2>
        <ul>{Object.entries(state.checks).map(([key, ok]) => <li key={key}>{tr(learningCheckLabels[key] || key)}: {tr(ok ? "Disponible" : "Pendiente o no disponible")}</li>)}</ul>
        <p>{tr("Usuarios con consentimiento")}: {state.consented_users} · {tr("Eventos pendientes")}: {state.pending_events} · {tr("Eventos procesados")}: {state.processed_events}</p>
        <p>{tr("Planificación automática")}: {tr(state.scheduled ? "Activo" : "Desactivado")} · {tr("Solo datos sintéticos")}: {tr(state.synthetic_only ? "Sí" : "No")}</p>
      </section>
      <label className="block" htmlFor="learning-reason">{tr("Motivo de la acción (sin datos personales)")}</label>
      <textarea id="learning-reason" className="w-full rounded border p-2" value={reason} onChange={e => setReason(e.target.value)} minLength={3} maxLength={500} required />
      <section className="space-y-3"><h2 className="text-xl font-semibold">{tr(learningLoopLabels.wp13)} · {tr(state.engine_enabled ? "Activo" : "Desactivado")}</h2>
        <p>{tr("Modelo seleccionado")}: <code>{state.wp13?.selected ?? "—"}</code></p>
        <p>{tr("Modelo efectivo según configuración local")}: <code>{state.wp13?.serving?.baseline ? (state.wp13.effective ?? tr("Modelo base")) : tr("Pendiente o no disponible")}</code></p>
        <p>{tr("Estado calculado en el puente local; no verifica la configuración del servicio remoto.")}</p>
        {state.wp13?.fallback_reason && <p>{tr(learningFallbackLabels[state.wp13.fallback_reason] || "Pendiente o no disponible")}</p>}
        <p>{tr("Servicio de candidatos")}: {tr(state.wp13?.serving?.serving_enabled ? "Activo" : "Desactivado")}</p>
        <p className="break-all">{tr("Identidad base actual")}: {state.wp13?.serving?.baseline?.serving_identity_sha256 ?? "—"}</p>
        <p>{tr("Configuración de servicio local")}: <code>{JSON.stringify(state.wp13?.serving?.baseline?.runtime_config ?? null)}</code></p>
        <p className="break-all">{tr("Hash del modelo seleccionado")}: {state.wp13?.active_sha256 ?? "—"}</p>
        <div className="flex gap-3">{button("wp13", "train")}{button("wp13", "rollback")}</div>
        {state.wp13?.candidates.map(candidate => <div key={candidate.id} className="rounded border p-4 space-y-2">
          <p className="break-all">{tr("Candidato")}: <code>{candidate.id}</code> · {"SHA-256"}: {candidate.sha256}</p>
          <p>{tr("Control histórico de promoción")}: {tr(candidate.gate?.passed ? "Superado" : "No superado")} · {tr(candidate.eligible ? "Disponible" : "Pendiente o no disponible")}</p>
          <p className="break-all">{tr("Identidad base de la evaluación")}: {candidate.comparator?.baseline_identity ?? "—"}</p>
          <p>{tr("Comparador histórico")}: {candidate.comparator?.effective_known ? (candidate.comparator.effective ?? tr("Modelo base")) : tr("No registrado")}</p>
          <table className="w-full text-left"><caption>{tr("Sensibilidad en la evaluación histórica")}</caption><thead><tr><th>{tr("Conjunto")}</th><th>{tr("Comparador histórico")}</th><th>{tr("Candidato")}</th><th>{tr("Aciertos perdidos")}</th></tr></thead>
            <tbody>{candidate.gate?.sets.map(set => <tr key={set.name}><td>{set.name}</td><td>{set.served_recall ?? tr("No disponible")}</td><td>{set.candidate_recall ?? tr("No disponible")}</td><td>{set.lost_true_positives}</td></tr>)}</tbody></table>
          {state.gate_metrics_enabled && candidate.gate?.schema === "learning-gate-metrics-v1" && <>
            <GateMetrics gate={candidate.gate} tr={tr} />
            <button className="rounded border px-3 py-2" onClick={() => void refresh(candidate.id)}>{tr("Ver detalle por tema")}</button>
          </>}
          {button("wp13", "promote", candidate)}
        </div>)}
        <ul>{state.runs.map(run => <li key={run.id}>{run.created_at} · {tr(learningStatusLabels[run.status] || run.status)}</li>)}</ul>
      </section>
      <section className="space-y-3"><h2 className="text-xl font-semibold">{tr(learningLoopLabels.wp23)} · {tr(learningStatusLabels[state.bandit_mode] || state.bandit_mode)}</h2>
        <p>{tr("La versión activa cambia únicamente al promover o revertir. La retirada de consentimiento puede desactivarla.")}</p>
        <div className="flex gap-3">{button("wp23", "process")}{button("wp23", "rollback")}</div>
        {(["active", "candidate"] as const).map(kind => {
          const item = state.wp23?.[kind]
          return <div key={kind} className="rounded border p-4 break-all"><h3>{tr(kind === "active" ? "Estado activo" : "Estado candidato")}</h3>
            {item ? <><p>{tr("Versión")}: <code>{item.id}</code></p><p>{"SHA-256"}: {item.sha256}</p><p>{tr("Eventos aplicados")}: {item.events_applied}</p><p>{tr(item.eligible ? "Disponible" : "Pendiente o no disponible")}</p>
              {kind === "candidate" && button("wp23", "promote", item)}</> : <p>{tr("Sin estado registrado")}</p>}
          </div>
        })}
      </section>
      <section><h2 className="text-xl font-semibold">{tr("Auditoría de acciones")}</h2>
        <ul className="space-y-3">{state.actions.map(action => <li key={action.id} className="rounded border p-3 break-all">
          <p>{action.created_at} · {tr(learningLoopLabels[action.loop] || action.loop)} · {tr(learningStatusLabels[action.action] || action.action)} · {tr(learningStatusLabels[action.status] || action.status)}</p>
          <p>{tr("Administrador")}: {action.admin_id ?? tr("Planificador")} · {action.reason}</p>
          <p>{tr("Hash anterior")}: {action.before_sha256 ?? "—"}</p><p>{tr("Hash posterior")}: {action.after_sha256 ?? "—"}</p>
          {action.error_code && <p>{tr("La operación se bloqueó. Revisa los requisitos antes de reintentar.")}</p>}
        </li>)}</ul>
      </section>
    </>}
  </article>
}

function GateMetrics({ gate, tr }: { gate: Gate; tr: (text: string) => string }) {
  const value = (n: number | null | undefined) => n == null ? tr("No disponible") : String(n)
  const metric = (m: Metric) => `${value(m.value)} [${value(m.ci.lower)}, ${value(m.ci.upper)}] (${m.ci.valid_replicates})`
  const labels: Record<string, string> = { base: "Modelo base", comparator: "Comparador histórico", candidate: "Candidato", keys: "Temas", standards: "Normas NEIS", heldout: "Conjunto reservado", gold_oos: "Referencia independiente" }
  const reasons: Record<string, string> = { improvement_not_demonstrated: "Mejora no demostrada", insufficient_evidence: "Datos insuficientes", positive_loss: "Pérdida de positivos conocidos" }
  return <section className="space-y-3">
    <h3>{tr("Evaluación de recomendaciones NEIS para la doble importancia relativa")}</h3>
    <p className="break-all">{tr("Protocolo declarado")}: {gate.protocol_sha256}</p>
    <p>{tr("Criterio primario: límite inferior del intervalo de la mejora de precisión micro en temas del conjunto reservado estrictamente mayor que")}: {value(gate.primary?.threshold)}</p>
    <p>{tr("Intervalo de confianza por empresa")}: {value(gate.policy?.confidence)} · {tr("Réplicas")}: {value(gate.policy?.replicates)}</p>
    {gate.failure_reasons?.map(reason => <p key={reason}>{tr(reasons[reason] ?? "No disponible")}</p>)}
    {Object.entries(gate.report ?? {}).map(([name, report]) => <div key={name}>
      <p>{tr(labels[name] ?? name)} · {tr(labels[report.unit] ?? report.unit)} · {tr("Empresas")}: {report.companies}</p>
      {Object.entries({ micro: report.micro, ...report.per_topic }).map(([key, section]) => <div key={key} className="overflow-x-auto">
        <p>{tr("Positivos / Negativos / Desconocidos")}: {section.positive_support} / {section.negative_support} / {section.unknown_support}</p>
        <table className="w-full text-left"><caption>{key} · {tr("Precisión / Sensibilidad / F1")} · {tr("Valor [intervalo]; réplicas válidas")}</caption>
          <thead><tr>{["Predictor", "TP", "FP", "FN", "TN", "Precisión", "Sensibilidad", "F1"].map(label => <th scope="col" key={label}>{tr(label)}</th>)}</tr></thead>
          <tbody>{Object.entries(section.predictors).map(([predictor, scores]) => <tr key={predictor}><th scope="row">{tr(labels[predictor] ?? predictor)}</th>
            <td>{scores.tp}</td><td>{scores.fp}</td><td>{scores.fn}</td><td>{scores.tn}</td>
            <td>{metric(scores.precision)}</td><td>{metric(scores.recall)}</td><td>{metric(scores.f1)}</td></tr>)}</tbody>
        </table>
        <p>{tr("Diferencia pareada de precisión (candidato menos comparador)")}: {metric(section.paired_delta.precision)}</p>
      </div>)}
    </div>)}
  </section>
}

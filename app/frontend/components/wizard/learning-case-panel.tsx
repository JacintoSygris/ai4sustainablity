"use client"

import { useEffect, useRef, useState } from "react"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { useLocale } from "@/components/locale-provider"
import { ui as systemUi } from "@/lib/i18n/messages.mjs"
import { learningCaseConflict, learningCaseRead, learningCaseCanSubmit } from "@/lib/learning-case-state.mjs"
import {
  LaravelApiError, getLaravelSession, getLaravelLearningCaseDraft, saveLaravelLearningCaseDraft,
  closeLaravelLearningCase, withdrawLaravelLearningCase,
  type LaravelLearningClosureDraft, type LaravelLearningClosureCommand,
} from "@/lib/laravel-api"

const statusCopy = { disabled: "Deshabilitado", blocked: "Bloqueado", ready: "Disponible para revisión", closed: "Cerrado", stale: "Fuentes modificadas", withdrawn: "Retirado" }
type Draft = { reviewed_universe: boolean; final_for_period_scope: boolean; idempotency_key: string; source_token?: string | null }
export function LearningCasePanel() {
  const { locale } = useLocale()
  const tr = (source: string) => systemUi(locale, source)
  const [server, setServer] = useState<LaravelLearningClosureDraft | null>(null)
  const [ui, setUi] = useState({ draft: { reviewed_universe: false, final_for_period_scope: false, idempotency_key: "" } as Draft, needsReload: false, requiresReview: false })
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState("")
  const active = useRef(false)
  const sequence = useRef(0)
  const touched = useRef(false)
  async function read() {
    const version = ++sequence.current
    const result = await getLaravelLearningCaseDraft()
    if (!active.current || version !== sequence.current) return
    setServer(result.data)
    setUi(previous => learningCaseRead(previous, result.data, !touched.current))
  }
  useEffect(() => {
    active.current = true
    void read().catch(() => { if (active.current) setMessage("No se pudo leer el estado. Reintente la lectura.") })
    return () => { active.current = false; sequence.current++ }
  }, [])
  function patch(field: "reviewed_universe" | "final_for_period_scope", value: boolean) {
    if (!server?.can_close || !server.source_token || busy || ui.needsReload) return
    touched.current = true
    setUi(previous => {
      const confirmed = field === "reviewed_universe" && value
      return { ...previous, requiresReview: confirmed ? false : previous.requiresReview,
        draft: { ...previous.draft, [field]: value,
          source_token: confirmed ? server.source_token : previous.draft.source_token,
          idempotency_key: confirmed && (previous.requiresReview || previous.draft.source_token !== server.source_token || !previous.draft.idempotency_key) ? crypto.randomUUID() : previous.draft.idempotency_key } }
    })
  }
  async function perform(action: "save" | "close" | "withdraw") {
    if (busy || !server) return
    if (action !== "withdraw" && !learningCaseCanSubmit(ui, server, action)) return
    setBusy(true); setMessage("")
    try {
      const session = await getLaravelSession()
      if (!active.current) return
      const options = { csrfToken: session.data.csrf_token }
      if (action === "withdraw") {
        if (server.expected_authorization_generation === null) return
        await withdrawLaravelLearningCase({ expected_authorization_generation: server.expected_authorization_generation, idempotency_key: crypto.randomUUID() }, options)
      } else {
        if (!server.expected_revisions || !server.source_token || server.expected_authorization_generation === null) return
        const command: LaravelLearningClosureCommand = { ...ui.draft, expected_revisions: server.expected_revisions,
          expected_authorization_generation: server.expected_authorization_generation, source_token: server.source_token, declaration_version: "local-synthetic-closure-v1" }
        if (action === "save") await saveLaravelLearningCaseDraft(command, options)
        else await closeLaravelLearningCase(command, options)
      }
      if (active.current) { setMessage(action === "withdraw" ? "Retirada registrada." : "Operación registrada."); await read() }
    } catch (error) {
      if (!active.current) return
      if (error instanceof LaravelApiError && error.status === 409) {
        setUi(previous => learningCaseConflict(previous))
        setMessage("El estado cambió. Se conservan sus entradas. Relea y revise antes de una nueva acción explícita.")
        // Only GET follows a conflict. Never replay PUT/POST.
        try { await read() } catch { setMessage("Conflicto: lectura pendiente. Sus entradas se conservan.") }
      } else setMessage("No se completó la operación. Compruebe sesión, permisos y estado.")
    } finally { if (active.current) setBusy(false) }
  }
  const canEdit = !!server?.can_close && !busy && !ui.needsReload
  const canClose = canEdit && learningCaseCanSubmit(ui, server, "close")
  const canSave = canEdit && learningCaseCanSubmit(ui, server, "save")
  return <Card aria-labelledby="learning-case-title">
    <CardContent className="space-y-4 pt-6">
      <h2 id="learning-case-title" className="text-lg font-semibold">{tr("Cierre del caso de aprendizaje")}</h2>
      <p className="text-sm text-muted-foreground">{tr("Solo mecanismo local sintético. Esta declaración técnica no es consentimiento jurídico aprobado, no concede derechos reales y no acredita mejora predictiva.")}</p>
      <p role="status" aria-live="polite">{server ? tr(statusCopy[server.status]) : tr("Leyendo estado…")}{message ? ` · ${tr(message)}` : ""}</p>
      <fieldset disabled={!canEdit} className="space-y-3">
        <legend className="text-sm font-medium">{tr("Revisión técnica explícita")}</legend>
        <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={ui.draft.reviewed_universe && !ui.requiresReview} onChange={e => patch("reviewed_universe", e.target.checked)} />{tr("He revisado el universo almacenado de temas y decisiones.")}</label>
        <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={ui.draft.final_for_period_scope} onChange={e => patch("final_for_period_scope", e.target.checked)} />{tr("Declaro este caso final para el período y perímetro sintéticos indicados por el servidor.")}</label>
      </fieldset>
      <div className="flex flex-wrap gap-2">
        <Button type="button" variant="outline" disabled={busy} onClick={() => { setBusy(true); void read().catch(() => setMessage("No se pudo releer.")).finally(() => { if (active.current) setBusy(false) }) }}>{tr("Releer estado")}</Button>
        <Button type="button" variant="outline" disabled={!canSave} onClick={() => void perform("save")}>{tr("Guardar revisión")}</Button>
        <Button type="button" disabled={!canClose} onClick={() => void perform("close")}>{tr("Cerrar caso")}</Button>
        <Button type="button" variant="outline" disabled={!server?.can_withdraw || busy} onClick={() => void perform("withdraw")}>{tr("Retirar caso")}</Button>
      </div>
    </CardContent>
  </Card>
}

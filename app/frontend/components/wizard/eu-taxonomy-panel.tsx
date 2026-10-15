"use client"

import { useEffect, useState, type FormEvent } from "react"
import { useLocale } from "@/components/locale-provider"
import { ui } from "@/lib/i18n/messages.mjs"
import { getLaravelSession, laravelApi, LaravelApiError } from "@/lib/laravel-api"
import { taxonomyTitle, taxonomyDisclaimer, taxonomyCoverage, taxonomyClassification, objectiveLabels, taxonomyPayload } from "@/lib/eu-taxonomy-state.mjs"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"

type Declaration = { activity_id: string; turnover_share: number | null; capex_share: number | null; opex_share: number | null }
type Activity = { id: string; activity_number: string; name: { es: string; en: string }; environmental_objectives: Array<keyof typeof objectiveLabels>; nace_codes: string[]; source: { celex: string; annex: string; url: string } }
type Screen = { source_hash: string; revision: number; activities: Activity[]; confirmed_activities: Declaration[]; declaration_status: string; mapping: { status: string; potential_activity_ids: string[] } }
type Edit = { confirmed: boolean; turnover_share: string; capex_share: string; opex_share: string }
const shares = { turnover_share: "Volumen de negocios declarado (%)", capex_share: "CapEx declarado (%)", opex_share: "OpEx declarado (%)" } as const

function initialEdits(screen: Screen): Record<string, Edit> {
  return Object.fromEntries(screen.activities.map((activity) => {
    const row = screen.confirmed_activities.find((item) => item.activity_id === activity.id)
    return [activity.id, { confirmed: !!row, turnover_share: row?.turnover_share?.toString() ?? "", capex_share: row?.capex_share?.toString() ?? "", opex_share: row?.opex_share?.toString() ?? "" }]
  }))
}

export function EuTaxonomyPanel() {
  const { locale, changing } = useLocale()
  const tr = (text: string) => ui(locale, text)
  const [screen, setScreen] = useState<Screen | null>(null)
  const [edits, setEdits] = useState<Record<string, Edit>>({})
  const [reload, setReload] = useState(0)
  const [busy, setBusy] = useState(true)
  const [message, setMessage] = useState("")
  const [disabled, setDisabled] = useState(false)

  // All activity names are bilingual. Locale switches do not discard pending declarations.
  useEffect(() => {
    let active = true
    laravelApi<{ data: Screen | null }>("/report/eu-taxonomy").then(({ data }) => {
      if (!active) return
      setScreen(data)
      setEdits(data ? initialEdits(data) : {})
      setMessage(data ? "" : "Completa la caracterización de la empresa para revisar estas actividades.")
    }).catch((error: unknown) => {
      if (!active) return
      if (error instanceof LaravelApiError && error.status === 404) setDisabled(true)
      setMessage("No se han podido cargar las actividades. Recarga el panel.")
    }).finally(() => { if (active) setBusy(false) })
    return () => { active = false }
  }, [reload])

  async function save(event: FormEvent) {
    event.preventDefault()
    if (!screen || busy || changing) return
    setBusy(true)
    setMessage("")
    try {
      const payload = taxonomyPayload(screen, edits)
      const session = await getLaravelSession()
      const { data } = await laravelApi<{ data: Screen }>("/report/eu-taxonomy", { method: "PUT", body: payload, csrfToken: session.data.csrf_token })
      setScreen(data)
      setEdits(initialEdits(data))
      setMessage("Declaraciones guardadas. No constituyen una evaluación de alineamiento.")
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 404) setDisabled(true)
      setMessage(error instanceof LaravelApiError && error.status === 409
        ? "Los datos o las declaraciones han cambiado. Recarga el panel y revisa las actividades antes de guardar."
        : "No se han podido guardar las declaraciones. Revisa los porcentajes e inténtalo de nuevo.")
    } finally { setBusy(false) }
  }

  if (disabled) return null
  return <section className="rounded-lg border p-4 space-y-4" aria-labelledby="eu-taxonomy-title" aria-busy={busy}>
    <h2 id="eu-taxonomy-title" className="text-lg font-semibold">{tr(taxonomyTitle)}</h2>
    <p className="font-medium">{tr(taxonomyDisclaimer)}</p>
    <p>{tr(taxonomyCoverage)}</p>
    <p>{tr(taxonomyClassification)}</p>
    <p>{tr("Confirma solo las actividades cuya descripción oficial corresponda a la empresa. Los campos vacíos indican valores no declarados. No sumes porcentajes entre objetivos: una misma actividad puede aparecer en varios anexos.")}</p>
    <p role="status" aria-live="polite">{message ? tr(message) : busy ? tr("Cargando...") : ""}</p>
    {screen?.declaration_status === "stale" ? <p role="alert">{tr("La actividad de la caracterización o el catálogo han cambiado. Las declaraciones anteriores se conservan en las evidencias, pero requieren nueva confirmación.")}</p> : null}
    {screen?.mapping.status === "manual_review_required" ? <p>{tr("Sin correspondencia automática verificada. Revisa manualmente el subconjunto disponible.")}</p> : null}
    <Button type="button" variant="outline" disabled={busy || changing} onClick={() => { setBusy(true); setReload((value) => value + 1) }}>{tr("Recargar declaraciones guardadas (descarta cambios sin guardar)")}</Button>
    {screen ? <form onSubmit={save}>
      <fieldset disabled={busy || changing} className="space-y-4">
        <legend className="font-semibold">{tr("Actividades del subconjunto verificado")}</legend>
        {screen.activities.map((activity) => <fieldset key={activity.id} className="rounded border p-3 space-y-3">
          <legend>{activity.activity_number} — {activity.name[locale]}</legend>
          <p>{activity.environmental_objectives.map((objective) => tr(objectiveLabels[objective])).join(", ")} · {activity.nace_codes.join(", ")}</p>
          <a href={activity.source.url} target="_blank" rel="noreferrer" className="underline">{tr("Texto oficial")}: {activity.source.celex}, {tr("Anexo")} {activity.source.annex}</a>
          <p>{tr(screen.mapping.potential_activity_ids.includes(activity.id) ? "Posible coincidencia por código de la caracterización" : "Revisión manual de la descripción")}</p>
          <label className="flex items-center gap-2"><input type="checkbox" checked={edits[activity.id]?.confirmed ?? false} onChange={(event) => setEdits((old) => ({ ...old, [activity.id]: { ...old[activity.id], confirmed: event.target.checked } }))} />{tr("Confirmo que esta actividad corresponde a la empresa")}</label>
          {edits[activity.id]?.confirmed ? <div className="grid gap-3 md:grid-cols-3">{(Object.keys(shares) as Array<keyof typeof shares>).map((key) => <label key={key} className="space-y-1">
            <span>{tr(shares[key])}</span>
            <Input type="number" min="0" max="100" step="any" value={edits[activity.id][key]} onChange={(event) => setEdits((old) => ({ ...old, [activity.id]: { ...old[activity.id], [key]: event.target.value } }))} />
          </label>)}</div> : null}
        </fieldset>)}
        <Button type="submit">{tr(busy ? "Guardando..." : "Guardar declaraciones")}</Button>
      </fieldset>
    </form> : null}
  </section>
}

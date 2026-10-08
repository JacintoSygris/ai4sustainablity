"use client"

import { useSystemMessage, systemCopy } from "@/lib/i18n/use-system-message"

import { useLocale } from "@/components/locale-provider"
import { ui } from "@/lib/i18n/messages.mjs"

import { useEffect, useState } from "react"
import { useRouter } from "next/navigation"
import { AlertCircle, ChevronDown, Download, RefreshCw } from "lucide-react"
import { Button } from "@/components/ui/button"
import { LocalizedDownload } from "@/components/localized-download"
import { Card, CardContent } from "@/components/ui/card"
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible"
import { Term } from "@/components/wizard/term"
import {
  LaravelApiError,
  getLaravelDoubleMaterialityGuide,
  getLaravelDoubleMaterialityGuideState,
  getLaravelSession,
  laravelApiUrl,
  updateLaravelDoubleMaterialityGuideState,
  type LaravelDoubleMaterialityGuide as LaravelDoubleMaterialityGuideData,
  type LaravelDoubleMaterialityProcessState,
} from "@/lib/laravel-api"
import {
  actaRegistered,
  canContinueFromGuideState,
  checklistFlags,
  guideTitle,
  guideChecks,
  firstOpenStepKey,
  guideProgressLabel,
  localized,
  templateDownloadPath,
} from "@/lib/double-materiality-guide-state.mjs"

export function DoubleMaterialityGuide() {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)
  const router = useRouter()
  const [guide, setGuide] = useState<LaravelDoubleMaterialityGuideData | null>(null)
  const [processState, setProcessState] = useState<LaravelDoubleMaterialityProcessState | null>(null)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [savingChecklist, setSavingChecklist] = useState(false)
  const [savingActa, setSavingActa] = useState(false)
  const [errorMessage, setErrorMessage] = useSystemMessage(null)
  const [openSteps, setOpenSteps] = useState<string[]>([])
  const [csrfToken, setCsrfToken] = useState<string | undefined>(undefined)

  // local editable drafts for acta (saved explicitly)
  const [actaDraft, setActaDraft] = useState<{ completed_on: string; method: string; participants: string }>({
    completed_on: "",
    method: "",
    participants: "",
  })

  const loadGuide = async () => {
    setLoadingInitial(true)
    setErrorMessage(null)

    try {
      const [sessionResponse, guideResponse, stateResponse] = await Promise.all([
        getLaravelSession(),
        getLaravelDoubleMaterialityGuide(),
        getLaravelDoubleMaterialityGuideState(),
      ])
      setCsrfToken(sessionResponse.data.csrf_token)
      setGuide(guideResponse.data)
      setOpenSteps(firstOpenStepKey())
      const st = stateResponse.data
      setProcessState(st)
      // seed acta draft from server if present
      if (st?.acta) {
        setActaDraft({
          completed_on: st.acta.completed_on || "",
          method: st.acta.method || "",
          participants: st.acta.participants || "",
        })
      }
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")

        return
      }

      setErrorMessage(systemCopy("No se ha podido cargar la guía de doble importancia relativa desde la plataforma."))
    } finally {
      setLoadingInitial(false)
    }
  }

  useEffect(() => {
    loadGuide()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const toggleStep = (stepKey: string) => {
    setOpenSteps((current) =>
      current.includes(stepKey) ? current.filter((key) => key !== stepKey) : [...current, stepKey],
    )
  }

  const toggleChecklistItem = async (key: keyof LaravelDoubleMaterialityProcessState["checklist"]) => {
    setSavingChecklist(true)
    setErrorMessage(null)
    const currentChecklist = checklistFlags(processState)
    const nextChecklist = { ...currentChecklist, [key]: !currentChecklist[key] }
    try {
      const res = await updateLaravelDoubleMaterialityGuideState({ checklist: nextChecklist }, { csrfToken })
      setProcessState(res.data)
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")
        return
      }
      setErrorMessage(systemCopy("No se pudo guardar el progreso del análisis."))
    } finally {
      setSavingChecklist(false)
    }
  }

  const saveActa = async () => {
    setSavingActa(true)
    setErrorMessage(null)
    const payloadActa = {
      completed_on: actaDraft.completed_on || null,
      method: actaDraft.method.trim() || null,
      participants: actaDraft.participants.trim() || null,
    }
    try {
      const res = await updateLaravelDoubleMaterialityGuideState({ acta: payloadActa }, { csrfToken })
      setProcessState(res.data)
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")
        return
      }
      setErrorMessage(systemCopy("No se pudo registrar el acta del análisis."))
    } finally {
      setSavingActa(false)
    }
  }

  const updateActaField = (field: "completed_on" | "method" | "participants", value: string) => {
    setActaDraft((d) => ({ ...d, [field]: value }))
  }

  const canContinue = canContinueFromGuideState({ guide, loadingInitial, errorMessage })
  const progressLabel = guideProgressLabel(processState, locale)
  const hasActa = actaRegistered(processState)

  const CHECKLIST_LABELS: Record<keyof LaravelDoubleMaterialityProcessState["checklist"], string> = {
    identified_stakeholders: tr("He identificado a mis grupos de interés"),
    assessed_impacts: tr("He evaluado los impactos hacia fuera"),
    assessed_financial_effects: tr("He evaluado los efectos económicos hacia dentro"),
    reached_conclusions: tr("He llegado a conclusiones tema a tema"),
  }

  const checklistKeys = [
    "identified_stakeholders",
    "assessed_impacts",
    "assessed_financial_effects",
    "reached_conclusions",
  ] as const

  return (
    <div className="min-w-0 flex-1 space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">{tr("Doble importancia relativa")}</h1>
        <p className="mt-2 text-muted-foreground">{" "}{tr("Guía estructurada para realizar el")}{" "}<Term k="adm">{tr("análisis de doble importancia relativa")}</Term>{" "}{tr("fuera de la aplicación, con mirada de")}{" "}<Term k="doble_materialidad">{tr("doble importancia relativa")}</Term>{" "}{tr("y participación de")}{" "}
          <Term k="grupos_interes">{tr("grupos de interés")}</Term>{tr(", antes de volver al paso 4 con la selección final.")}{" "}</p>
      </div>

      {errorMessage ? (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {tr(errorMessage)}
        </div>
      ) : null}

      {loadingInitial ? (
        <Card>
          <CardContent className="pt-6 text-sm text-muted-foreground">{tr("Cargando guía de doble importancia relativa...")}</CardContent>
        </Card>
      ) : guide ? (
        <>
          <Card>
            <CardContent className="space-y-3 pt-6">
              <div className="flex items-start gap-3">
                <AlertCircle className="mt-0.5 h-5 w-5 text-amber-500" />
                <div>
                  <p className="font-medium text-foreground">{localized(guide.warning, locale)}</p>
                  <p className="mt-1 text-sm text-muted-foreground">{localized(guide.next_step.note, locale)}</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <div className="space-y-2" aria-label={tr("Instrucciones para el análisis")}>
            {guide.sections.map((section, index) => (
              <Collapsible key={section.key} open={openSteps.includes(section.key)} onOpenChange={() => toggleStep(section.key)} className="rounded-lg border border-border bg-card">
                <CollapsibleTrigger className="group flex w-full items-center gap-3 p-4 text-left hover:bg-accent/50">
                  <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">{index + 1}</span>
                  <h2 className="min-w-0 flex-1 font-semibold text-foreground">{guideTitle(section, locale)}</h2>
                  <ChevronDown className="h-4 w-4 shrink-0 transition-transform group-data-[state=open]:rotate-180" aria-hidden="true" />
                </CollapsibleTrigger>
                <CollapsibleContent className="space-y-4 border-t border-border px-4 py-3">
                  {section.steps.map((step) => (
                    <section key={step.key} className="space-y-2">
                      <h3 className="text-sm font-semibold text-primary">{guideTitle(step, locale)}</h3>
                      {step.body ? <p className="text-sm text-foreground">{localized(step.body, locale)}</p> : null}
                      <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                        {guideChecks(step, locale).map((check: string, checkIndex: number) => <li key={checkIndex}>{check}</li>)}
                      </ul>
                    </section>
                  ))}
                </CollapsibleContent>
              </Collapsible>
            ))}
          </div>

          {guide.templates && guide.templates.length > 0 ? (
            <details className="rounded-lg border border-border bg-card px-4 py-3">
              <summary className="cursor-pointer text-sm font-semibold text-primary">{tr("Plantillas para tu análisis")}</summary>
              <p className="mt-2 text-xs text-muted-foreground">{tr("Descarga hojas de cálculo vacías para documentar el análisis.")}</p>
              <div className="mt-3 divide-y divide-border">
                {guide.templates.map((template) => (
                  <div key={template.key} className="flex flex-wrap items-center justify-between gap-2 py-2">
                    <p className="text-sm text-foreground">{localized(template.title, locale)}</p>
                    <div className="flex shrink-0 gap-1">
                      <LocalizedDownload type="button" variant="ghost" size="sm" href={laravelApiUrl(templateDownloadPath(template.key))} aria-label={`${tr("Descargar CSV")}: ${localized(template.title, locale)}`}>
                          <Download className="mr-1 h-3 w-3" />{tr("Descargar CSV")}
                        </LocalizedDownload>
                    </div>
                  </div>
                ))}
              </div>
            </details>
          ) : null}

          <h2 className="text-lg font-semibold text-foreground">{tr("Registra tu avance")}</h2>
          {/* Persisted checklist — saves on toggle per plan F2 */}
          <Card>
            <CardContent className="space-y-3 pt-6">
              <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold text-primary">{tr("Marca lo que ya has hecho")}</h2>
                <span className="text-xs text-muted-foreground">{progressLabel}</span>
              </div>
              <div className="space-y-2">
                {checklistKeys.map((key) => {
                  const checked = checklistFlags(processState)[key]
                  return (
                    <label key={key} className="flex items-center gap-2 rounded border border-border px-3 py-2 text-sm">
                      <input
                        type="checkbox"
                        checked={checked}
                        onChange={() => toggleChecklistItem(key)}
                        disabled={savingChecklist}
                        className="h-4 w-4 accent-primary"
                      />
                      <span>{CHECKLIST_LABELS[key]}</span>
                    </label>
                  )
                })}
              </div>
              {savingChecklist ? <p className="text-xs text-muted-foreground">{tr("Guardando…")}</p> : null}
            </CardContent>
          </Card>

          {/* Acta del análisis — explicit save button; 3 fields per frozen contract */}
          <Card>
            <CardContent className="space-y-4 pt-6">
              <h2 className="text-lg font-semibold text-primary">{tr("Acta del análisis")}</h2>
              <div className="grid gap-3 md:grid-cols-3">
                <div>
                  <label className="text-xs font-medium text-muted-foreground">{tr("¿Cuándo lo terminasteis?")}</label>
                  <input
                    type="date"
                    value={actaDraft.completed_on}
                    onChange={(e) => updateActaField("completed_on", e.target.value)}
                    className="mt-1 w-full rounded border border-border bg-background px-3 py-2 text-sm"
                  />
                </div>
                <div className="md:col-span-2">
                  <label className="text-xs font-medium text-muted-foreground">{tr("¿Cómo lo hicisteis? (método)")}</label>
                  <input
                    type="text"
                    value={actaDraft.method}
                    maxLength={500}
                    onChange={(e) => updateActaField("method", e.target.value)}
                    placeholder={tr("Taller interno con dirección, entrevistas...")}
                    className="mt-1 w-full rounded border border-border bg-background px-3 py-2 text-sm"
                  />
                </div>
                <div className="md:col-span-3">
                  <label className="text-xs font-medium text-muted-foreground">{tr("¿Quién participó?")}</label>
                  <input
                    type="text"
                    value={actaDraft.participants}
                    maxLength={500}
                    onChange={(e) => updateActaField("participants", e.target.value)}
                    placeholder={tr("Gerencia, RRHH, producción")}
                    className="mt-1 w-full rounded border border-border bg-background px-3 py-2 text-sm"
                  />
                </div>
              </div>
              <div>
                <Button type="button" variant="outline" onClick={saveActa} disabled={savingActa}>
                  {savingActa ? tr("Guardando acta...") : tr("Registrar acta")}
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">{" "}{tr("El acta se guarda por separado y se usa para etiquetar tu hoja de decisión.")}{" "}</p>
            </CardContent>
          </Card>
        </>
      ) : (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <p className="text-sm text-muted-foreground">{tr("La plataforma no ha devuelto la guía de doble importancia relativa.")}</p>
            <Button type="button" variant="outline" onClick={loadGuide}>
              <RefreshCw className="h-4 w-4" />{" "}{tr("Reintentar")}{" "}</Button>
          </CardContent>
        </Card>
      )}

      <div className="flex flex-col items-end gap-2 pt-4">
        {!hasActa ? (
          <p className="text-xs text-amber-600">{" "}{tr("Puedes continuar sin acta: tu hoja de decisión quedará marcada como 'sin análisis registrado'.")}{" "}</p>
        ) : null}
        <Button
          onClick={() => router.push("/wizard/step-4")}
          className="bg-primary hover:bg-primary/90"
          disabled={!canContinue}
        >{" "}{tr("Volver a la selección final de temas")}{" "}</Button>
      </div>
    </div>
  )
}

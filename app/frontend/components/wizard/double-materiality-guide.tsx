"use client"

import { useEffect, useState } from "react"
import { useRouter } from "next/navigation"
import { AlertCircle, ChevronDown, ChevronUp, Download, RefreshCw } from "lucide-react"
import { Button } from "@/components/ui/button"
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
  type LaravelDoubleMaterialityGuideStep,
  type LaravelDoubleMaterialityProcessState,
} from "@/lib/laravel-api"
import {
  TEMPLATE_DOWNLOAD_LABELS,
  TEMPLATE_LOCALES,
  actaRegistered,
  canContinueFromGuideState,
  checklistComplete,
  firstOpenStepKey,
  guideProgressLabel,
  localized,
  templateDownloadPath,
} from "@/lib/double-materiality-guide-state.mjs"

export function DoubleMaterialityGuide() {
  const router = useRouter()
  const [guide, setGuide] = useState<LaravelDoubleMaterialityGuideData | null>(null)
  const [processState, setProcessState] = useState<LaravelDoubleMaterialityProcessState | null>(null)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [savingChecklist, setSavingChecklist] = useState(false)
  const [savingActa, setSavingActa] = useState(false)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
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
      setOpenSteps(firstOpenStepKey(guideResponse.data))
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

      setErrorMessage("No se ha podido cargar la guía de doble materialidad desde la plataforma.")
    } finally {
      setLoadingInitial(false)
    }
  }

  useEffect(() => {
    loadGuide()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const toggleStep = (step: LaravelDoubleMaterialityGuideStep) => {
    setOpenSteps((current) =>
      current.includes(step.key) ? current.filter((stepKey) => stepKey !== step.key) : [...current, step.key],
    )
  }

  const toggleChecklistItem = async (key: keyof LaravelDoubleMaterialityProcessState["checklist"]) => {
    if (!processState) return
    setSavingChecklist(true)
    setErrorMessage(null)
    const currentChecklist = processState.checklist || {
      identified_stakeholders: false,
      assessed_impacts: false,
      assessed_financial_effects: false,
      reached_conclusions: false,
    }
    const nextChecklist = { ...currentChecklist, [key]: !currentChecklist[key] }
    try {
      const res = await updateLaravelDoubleMaterialityGuideState({ checklist: nextChecklist }, { csrfToken })
      setProcessState(res.data)
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")
        return
      }
      setErrorMessage("No se pudo guardar el progreso del análisis.")
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
      setErrorMessage("No se pudo registrar el acta del análisis.")
    } finally {
      setSavingActa(false)
    }
  }

  const updateActaField = (field: "completed_on" | "method" | "participants", value: string) => {
    setActaDraft((d) => ({ ...d, [field]: value }))
  }

  const canContinue = canContinueFromGuideState({ guide, loadingInitial, errorMessage })
  const progressLabel = guideProgressLabel(processState)
  const hasActa = actaRegistered(processState)

  const CHECKLIST_LABELS: Record<keyof LaravelDoubleMaterialityProcessState["checklist"], string> = {
    identified_stakeholders: "He identificado a mis grupos de interés",
    assessed_impacts: "He evaluado los impactos hacia fuera",
    assessed_financial_effects: "He evaluado los efectos económicos hacia dentro",
    reached_conclusions: "He llegado a conclusiones tema a tema",
  }

  const checklistKeys = [
    "identified_stakeholders",
    "assessed_impacts",
    "assessed_financial_effects",
    "reached_conclusions",
  ] as const

  return (
    <div className="flex-1 space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Doble materialidad</h1>
        <p className="mt-2 text-muted-foreground">
          Guía estructurada para realizar el <Term k="adm">análisis de doble materialidad</Term> fuera de la
          aplicación, con mirada de <Term k="doble_materialidad">doble materialidad</Term> y participación de{" "}
          <Term k="grupos_interes">grupos de interés</Term>, antes de volver al paso 4 con la selección final.
        </p>
      </div>

      {errorMessage ? (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {errorMessage}
        </div>
      ) : null}

      {loadingInitial ? (
        <Card>
          <CardContent className="pt-6 text-sm text-muted-foreground">Cargando guía de doble materialidad...</CardContent>
        </Card>
      ) : guide ? (
        <>
          <Card>
            <CardContent className="space-y-3 pt-6">
              <div className="flex items-start gap-3">
                <AlertCircle className="mt-0.5 h-5 w-5 text-amber-500" />
                <div>
                  <p className="font-medium text-foreground">{localized(guide.warning)}</p>
                  <p className="mt-1 text-sm text-muted-foreground">{localized(guide.next_step.note)}</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <div className="space-y-4">
            {guide.sections.map((section) => {
              const isWorked = section.key === "worked_example"
              const SectionCard = isWorked ? (
                <Card key={section.key} className="border-amber-300 bg-amber-50/60">
                  <CardContent className="space-y-3 pt-6">
                    <h2 className="text-lg font-semibold text-amber-900">{localized(section.title)}</h2>
                    <div className="space-y-3">
                      {section.steps.map((step) => (
                        <div key={step.key} className="rounded border border-amber-200 bg-white p-3 text-sm">
                          {step.body ? <p className="mb-2 text-foreground">{localized(step.body)}</p> : null}
                          {step.checks.length > 0 ? (
                            <ul className="space-y-1 text-muted-foreground">
                              {step.checks.map((check, index) => (
                                <li key={`${step.key}-${index}`} className="flex gap-2">
                                  <span className="shrink-0">-</span>
                                  <span>{check}</span>
                                </li>
                              ))}
                            </ul>
                          ) : null}
                        </div>
                      ))}
                    </div>
                  </CardContent>
                </Card>
              ) : (
                <Card key={section.key}>
                  <CardContent className="space-y-3 pt-6">
                    <h2 className="text-lg font-semibold text-primary">{localized(section.title)}</h2>
                    <div className="space-y-3">
                      {section.steps.map((step) => {
                        const isOpen = openSteps.includes(step.key)
                        return (
                          <Collapsible key={step.key} open={isOpen} onOpenChange={() => toggleStep(step)}>
                            <CollapsibleTrigger className="flex w-full items-center justify-between rounded-lg border border-border bg-card p-4 text-left transition-colors hover:bg-accent/50">
                              <span className="font-medium text-foreground">{localized(step.title)}</span>
                              {isOpen ? (
                                <ChevronUp className="h-5 w-5 text-muted-foreground" />
                              ) : (
                                <ChevronDown className="h-5 w-5 text-muted-foreground" />
                              )}
                            </CollapsibleTrigger>
                            <CollapsibleContent className="px-4 pb-2 pt-4 space-y-2">
                              {step.body ? (
                                <p className="text-sm text-foreground">{localized(step.body)}</p>
                              ) : null}
                              <ul className="space-y-2 text-sm text-muted-foreground">
                                {step.checks.map((check, index) => (
                                  <li key={`${step.key}-${index}`} className="flex gap-2">
                                    <span className="shrink-0">-</span>
                                    <span>{check}</span>
                                  </li>
                                ))}
                              </ul>
                            </CollapsibleContent>
                          </Collapsible>
                        )
                      })}
                    </div>
                  </CardContent>
                </Card>
              )
              return SectionCard
            })}
          </div>

          {guide.templates && guide.templates.length > 0 ? (
            <Card>
              <CardContent className="space-y-4 pt-6">
                <div>
                  <h2 className="text-lg font-semibold text-primary">Plantillas para tu análisis</h2>
                  <p className="mt-1 text-sm text-muted-foreground">
                    Hojas de cálculo vacías con las columnas recomendadas por la guía. Descárgalas en el idioma en el
                    que vayas a trabajar.
                  </p>
                </div>
                <div className="space-y-3">
                  {guide.templates.map((template) => (
                    <div
                      key={template.key}
                      className="flex flex-col gap-3 rounded-lg border border-border px-4 py-3 md:flex-row md:items-center md:justify-between"
                    >
                      <div>
                        <p className="text-sm font-medium text-foreground">{localized(template.title)}</p>
                        <p className="mt-1 text-xs text-muted-foreground">{template.columns.length} columnas</p>
                      </div>
                      <div className="flex flex-wrap gap-2">
                        {(TEMPLATE_LOCALES as string[]).map((locale) => (
                          <Button key={`${template.key}-${locale}`} type="button" variant="outline" size="sm" asChild>
                            <a href={laravelApiUrl(templateDownloadPath(template.key, locale))}>
                              <Download className="h-4 w-4" />
                              {(TEMPLATE_DOWNLOAD_LABELS as Record<string, string>)[locale] ?? locale}
                            </a>
                          </Button>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          ) : null}

          {/* Persisted checklist — saves on toggle per plan F2 */}
          <Card>
            <CardContent className="space-y-3 pt-6">
              <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold text-primary">Marca lo que ya has hecho</h2>
                <span className="text-xs text-muted-foreground">{progressLabel}</span>
              </div>
              <div className="space-y-2">
                {checklistKeys.map((key) => {
                  const checked = Boolean(processState?.checklist?.[key])
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
              {savingChecklist ? <p className="text-xs text-muted-foreground">Guardando…</p> : null}
            </CardContent>
          </Card>

          {/* Acta del análisis: explicit save button; 3 expected fields. */}
          <Card>
            <CardContent className="space-y-4 pt-6">
              <h2 className="text-lg font-semibold text-primary">Acta del análisis</h2>
              <div className="grid gap-3 md:grid-cols-3">
                <div>
                  <label className="text-xs font-medium text-muted-foreground">¿Cuándo lo terminasteis?</label>
                  <input
                    type="date"
                    value={actaDraft.completed_on}
                    onChange={(e) => updateActaField("completed_on", e.target.value)}
                    className="mt-1 w-full rounded border border-border bg-background px-3 py-2 text-sm"
                  />
                </div>
                <div className="md:col-span-2">
                  <label className="text-xs font-medium text-muted-foreground">¿Cómo lo hicisteis? (método)</label>
                  <input
                    type="text"
                    value={actaDraft.method}
                    maxLength={500}
                    onChange={(e) => updateActaField("method", e.target.value)}
                    placeholder="Taller interno con dirección, entrevistas..."
                    className="mt-1 w-full rounded border border-border bg-background px-3 py-2 text-sm"
                  />
                </div>
                <div className="md:col-span-3">
                  <label className="text-xs font-medium text-muted-foreground">¿Quién participó?</label>
                  <input
                    type="text"
                    value={actaDraft.participants}
                    maxLength={500}
                    onChange={(e) => updateActaField("participants", e.target.value)}
                    placeholder="Gerencia, RRHH, producción"
                    className="mt-1 w-full rounded border border-border bg-background px-3 py-2 text-sm"
                  />
                </div>
              </div>
              <div>
                <Button type="button" variant="outline" onClick={saveActa} disabled={savingActa}>
                  {savingActa ? "Guardando acta..." : "Registrar acta"}
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">
                El acta se guarda por separado y se usa para etiquetar tu hoja de decisión.
              </p>
            </CardContent>
          </Card>
        </>
      ) : (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <p className="text-sm text-muted-foreground">La plataforma no ha devuelto la guía de doble materialidad.</p>
            <Button type="button" variant="outline" onClick={loadGuide}>
              <RefreshCw className="h-4 w-4" />
              Reintentar
            </Button>
          </CardContent>
        </Card>
      )}

      <div className="flex flex-col items-end gap-2 pt-4">
        {!hasActa ? (
          <p className="text-xs text-amber-600">
            Puedes continuar sin acta: tu hoja de decisión quedará marcada como 'sin análisis registrado'.
          </p>
        ) : null}
        <Button
          onClick={() => router.push("/wizard/step-4")}
          className="bg-primary hover:bg-primary/90"
          disabled={!canContinue}
        >
          Continuar
        </Button>
      </div>
    </div>
  )
}

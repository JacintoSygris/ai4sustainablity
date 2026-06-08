"use client"

import { useEffect, useState } from "react"
import { useRouter } from "next/navigation"
import { AlertCircle, ChevronDown, ChevronUp, Download, RefreshCw } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible"
import {
  LaravelApiError,
  getLaravelDoubleMaterialityGuide,
  getLaravelSession,
  laravelApiUrl,
  type LaravelDoubleMaterialityGuide as LaravelDoubleMaterialityGuideData,
  type LaravelDoubleMaterialityGuideStep,
} from "@/lib/laravel-api"
import {
  canContinueFromGuideState,
  firstOpenStepKey,
  localized,
  templateDownloadPath,
} from "@/lib/double-materiality-guide-state.mjs"

export function DoubleMaterialityGuide() {
  const router = useRouter()
  const [guide, setGuide] = useState<LaravelDoubleMaterialityGuideData | null>(null)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [openSteps, setOpenSteps] = useState<string[]>([])

  const loadGuide = async () => {
    setLoadingInitial(true)
    setErrorMessage(null)

    try {
      const [, guideResponse] = await Promise.all([getLaravelSession(), getLaravelDoubleMaterialityGuide()])
      setGuide(guideResponse.data)
      setOpenSteps(firstOpenStepKey(guideResponse.data))
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")

        return
      }

      setErrorMessage("No se ha podido cargar la guía P7 desde Laravel.")
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
  const canContinue = canContinueFromGuideState({ guide, loadingInitial, errorMessage })

  return (
    <div className="flex-1 space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Doble materialidad</h1>
        <p className="mt-2 text-muted-foreground">
          Guía estructurada para realizar la ADM fuera de la aplicación y volver a P8 con la selección final.
        </p>
      </div>

      {errorMessage ? (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {errorMessage}
        </div>
      ) : null}

      {loadingInitial ? (
        <Card>
          <CardContent className="pt-6 text-sm text-muted-foreground">Cargando guía P7...</CardContent>
        </Card>
      ) : guide ? (
        <>
          <Card>
            <CardContent className="space-y-3 pt-6">
              <div className="flex items-start gap-3">
                <AlertCircle className="mt-0.5 h-5 w-5 text-amber-500" />
                <div>
                  <p className="font-medium text-foreground">{localized(guide.warning)}</p>
                  <p className="mt-1 text-sm text-muted-foreground">{localized(guide.handoff.note)}</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <div className="space-y-4">
            {guide.sections.map((section) => (
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
                          <CollapsibleContent className="px-4 pb-2 pt-4">
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
            ))}
          </div>

          <Card id="templates">
            <CardContent className="space-y-4 pt-6">
              <h2 className="text-lg font-semibold text-primary">Plantillas ADM</h2>
              <div className="space-y-3">
                {guide.templates.map((template) => (
                  <a
                    key={template.key}
                    href={laravelApiUrl(templateDownloadPath(template.key))}
                    className="flex items-center justify-between rounded-lg border border-border px-4 py-3 text-sm text-foreground hover:bg-muted"
                  >
                    <span>{localized(template.title)}</span>
                    <span className="flex items-center gap-2 text-muted-foreground">
                      {template.columns.length} columnas
                      <Download className="h-4 w-4" />
                    </span>
                  </a>
                ))}
              </div>
            </CardContent>
          </Card>
        </>
      ) : (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <p className="text-sm text-muted-foreground">Laravel no ha devuelto guía P7.</p>
            <Button type="button" variant="outline" onClick={loadGuide}>
              <RefreshCw className="h-4 w-4" />
              Reintentar
            </Button>
          </CardContent>
        </Card>
      )}

      <div className="flex justify-end pt-4">
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

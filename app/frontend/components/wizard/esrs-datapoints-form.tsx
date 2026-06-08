"use client"

import { useEffect, useMemo, useState } from "react"
import { useRouter } from "next/navigation"
import { AlertCircle, Download, RefreshCw, Save } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  LaravelApiError,
  getLaravelEsrsDatapointResponses,
  getLaravelEsrsDatapoints,
  getLaravelSession,
  laravelApiUrl,
  updateLaravelEsrsDatapointResponses,
  type LaravelEsrsDatapoint,
  type LaravelEsrsDatapointCorpus,
  type LaravelEsrsDatapointResponseStatus,
} from "@/lib/laravel-api"
import {
  compactDrafts,
  completionPlanItems,
  datapointApplicabilitySummary,
  emptyDraft,
  flattenCorpus,
  p9ExportLinks,
  p9MappingSummary,
  phaseInSummary,
  responseLabel,
} from "@/lib/esrs-datapoints-state.mjs"

type DatapointRow = {
  blockTitle: string
  datapoint: LaravelEsrsDatapoint
}

type DraftResponse = {
  status: LaravelEsrsDatapointResponseStatus
  value: string
  evidence_reference: string
  note: string
}

const statusOptions: Array<{ value: LaravelEsrsDatapointResponseStatus; label: string }> = [
  { value: "draft", label: "Borrador" },
  { value: "completed", label: "Completado" },
  { value: "not_applicable", label: "No aplica" },
]

function datapointSubtitle(datapoint: LaravelEsrsDatapoint): string {
  return [datapoint.standard, datapoint.dr, datapoint.paragraph].filter(Boolean).join(" / ")
}

export function EsrsDatapointsForm() {
  const router = useRouter()
  const [reloadCounter, setReloadCounter] = useState(0)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [saving, setSaving] = useState(false)
  const [csrfToken, setCsrfToken] = useState<string>()
  const [corpus, setCorpus] = useState<LaravelEsrsDatapointCorpus | null>(null)
  const [drafts, setDrafts] = useState<Record<string, DraftResponse>>({})
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  useEffect(() => {
    let mounted = true

    async function loadP9() {
      setLoadingInitial(true)
      setErrorMessage(null)

      try {
        const [sessionResponse, corpusResponse, responsesResponse] = await Promise.all([
          getLaravelSession(),
          getLaravelEsrsDatapoints(),
          getLaravelEsrsDatapointResponses(),
        ])

        if (!mounted) {
          return
        }

        setCsrfToken(sessionResponse.data.csrf_token)
        setCorpus(corpusResponse.data)
        setDrafts(
          Object.fromEntries(
            Object.entries(responsesResponse.data?.responses ?? {}).map(([datapointId, response]) => [
              datapointId,
              {
                evidence_reference: response.evidence_reference ?? "",
                note: response.note ?? "",
                status: response.status,
                value: response.value ?? "",
              },
            ]),
          ),
        )
      } catch (error) {
        if (error instanceof LaravelApiError && error.status === 401) {
          router.replace("/login")

          return
        }

        setErrorMessage("No se han podido cargar los datapoints P9 desde Laravel.")
      } finally {
        if (mounted) {
          setLoadingInitial(false)
        }
      }
    }

    loadP9()

    return () => {
      mounted = false
    }
  }, [reloadCounter, router])

  const rows = useMemo(() => flattenCorpus(corpus) as DatapointRow[], [corpus])
  const mappingSummary = useMemo(() => p9MappingSummary(corpus), [corpus])
  const phaseSummary = useMemo(() => phaseInSummary(corpus), [corpus])
  const completionItems = useMemo(() => completionPlanItems(corpus), [corpus])

  const updateDraft = (datapointId: string, patch: Partial<DraftResponse>) => {
    setDrafts((current) => ({
      ...current,
      [datapointId]: {
        ...emptyDraft(),
        ...(current[datapointId] ?? {}),
        ...patch,
      },
    }))
    setErrorMessage(null)
  }

  const reload = () => setReloadCounter((current) => current + 1)

  const handleSave = async () => {
    setSaving(true)
    setErrorMessage(null)

    try {
      await updateLaravelEsrsDatapointResponses({ responses: compactDrafts(drafts) }, { csrfToken })
      reload()
      router.refresh()
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")

        return
      }

      setErrorMessage("Laravel no ha podido guardar las respuestas P9.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="flex-1 space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">Datapoints ESRS</h1>
          <p className="mt-2 text-muted-foreground">
            Completa las respuestas desde el corpus determinista de Laravel. La IA no decide los datapoints P9.
          </p>
        </div>
        <div className="flex gap-2">
          <Button type="button" variant="outline" onClick={reload} disabled={loadingInitial || saving}>
            <RefreshCw className="h-4 w-4" />
            Recargar
          </Button>
          {corpus
            ? p9ExportLinks().map((exportLink) => (
                <Button key={exportLink.key} type="button" variant="outline" asChild>
                  <a href={laravelApiUrl(exportLink.path)}>
                    <Download className="h-4 w-4" />
                    {exportLink.label}
                  </a>
                </Button>
              ))
            : null}
        </div>
      </div>

      {errorMessage ? (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {errorMessage}
        </div>
      ) : null}

      {loadingInitial ? (
        <Card>
          <CardContent className="pt-6 text-sm text-muted-foreground">Cargando datapoints P9...</CardContent>
        </Card>
      ) : !corpus ? (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <div className="flex items-start gap-3">
              <AlertCircle className="mt-0.5 h-5 w-5 text-amber-600" />
              <div>
                <p className="font-medium text-foreground">No hay materialidad final confirmada</p>
                <p className="mt-1 text-sm text-muted-foreground">
                  Completa P8 para que Laravel genere el corpus P9 aplicable.
                </p>
              </div>
            </div>
            <Button type="button" onClick={() => router.push("/wizard/step-4")}>
              Volver a P8
            </Button>
          </CardContent>
        </Card>
      ) : (
        <>
          <Card>
            <CardContent className="grid gap-4 pt-6 md:grid-cols-4">
              <SummaryMetric label="Total" value={corpus.summary.total_datapoint_count} />
              <SummaryMetric label="Siempre requeridos" value={corpus.summary.always_required_datapoint_count} />
              <SummaryMetric label="Por materialidad" value={corpus.summary.topical_datapoint_count} />
              <SummaryMetric label="Estándares" value={corpus.activated_esrs_standards.join(", ") || "-"} />
            </CardContent>
          </Card>

          <Card>
            <CardContent className="space-y-5 pt-6">
              <div className="grid gap-4 lg:grid-cols-3">
                <div>
                  <p className="text-xs font-medium uppercase text-muted-foreground">Cobertura P9</p>
                  <p className="mt-1 text-sm text-foreground">
                    {mappingSummary.mappingStatus || "-"} / {mappingSummary.coverageStatus || "-"}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {mappingSummary.mappingGranularity || "standard_level"} · {mappingSummary.currentFilter || "-"}
                  </p>
                </div>
                <div>
                  <p className="text-xs font-medium uppercase text-muted-foreground">Fase-in</p>
                  <p className="mt-1 text-sm text-foreground">{phaseSummary.status || "-"}</p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {phaseSummary.applicablePhaseInCount} datapoints potencialmente aplicables
                  </p>
                </div>
                <div>
                  <p className="text-xs font-medium uppercase text-muted-foreground">Plan de trabajo</p>
                  <p className="mt-1 text-sm text-foreground">
                    {completionItems.length > 0 ? `${completionItems.length} fases sugeridas` : "-"}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {completionItems[0]?.title || "Laravel no ha devuelto plan de completitud."}
                  </p>
                </div>
              </div>

              {mappingSummary.limitations.length > 0 ? (
                <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                  {mappingSummary.limitations[0]}
                </div>
              ) : null}

              {completionItems.length > 0 ? (
                <div className="grid gap-2 md:grid-cols-2">
                  {completionItems.map((item) => (
                    <div key={item.key || item.title} className="rounded-md border border-border px-3 py-2 text-sm">
                      <p className="font-medium text-foreground">{item.title || item.key}</p>
                      <p className="text-xs text-muted-foreground">
                        {item.status || "-"} · {item.datapointCount} datapoints
                      </p>
                    </div>
                  ))}
                </div>
              ) : null}
            </CardContent>
          </Card>

          <div className="space-y-4">
            {rows.map(({ blockTitle, datapoint }) => {
              const draft = drafts[datapoint.id] ?? emptyDraft()
              const applicability = datapointApplicabilitySummary(datapoint)

              return (
                <Card key={datapoint.id}>
                  <CardContent className="space-y-4 pt-6">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                      <div>
                        <p className="text-xs font-medium uppercase text-muted-foreground">{blockTitle}</p>
                        <h2 className="mt-1 text-base font-semibold text-foreground">{datapoint.name}</h2>
                        <p className="mt-1 text-sm text-muted-foreground">{datapointSubtitle(datapoint)}</p>
                        {applicability.reason ? (
                          <p className="mt-2 text-sm text-foreground">{applicability.reason}</p>
                        ) : null}
                        <div className="mt-2 flex flex-wrap gap-2 text-xs text-muted-foreground">
                          {applicability.mappingBasis ? (
                            <span className="rounded-md border border-border px-2 py-1">
                              Base: {applicability.mappingBasis}
                            </span>
                          ) : null}
                          {applicability.reasonCode ? (
                            <span className="rounded-md border border-border px-2 py-1">
                              Motivo: {applicability.reasonCode}
                            </span>
                          ) : null}
                          {applicability.phaseInLessThan750 || applicability.phaseInAllUndertakings ? (
                            <span className="rounded-md border border-border px-2 py-1">Fase-in</span>
                          ) : null}
                        </div>
                        {applicability.limitations.length > 0 ? (
                          <p className="mt-2 text-xs text-amber-700">{applicability.limitations.join(" ")}</p>
                        ) : null}
                      </div>
                      <span className="rounded-md border border-border px-2 py-1 text-xs text-muted-foreground">
                        {responseLabel(draft.status)}
                      </span>
                    </div>

                    <div className="grid gap-4 lg:grid-cols-[180px_1fr_1fr]">
                      <div className="space-y-2">
                        <Label htmlFor={`${datapoint.id}-status`}>Estado</Label>
                        <select
                          id={`${datapoint.id}-status`}
                          className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground"
                          value={draft.status}
                          onChange={(event) =>
                            updateDraft(datapoint.id, {
                              status: event.target.value as LaravelEsrsDatapointResponseStatus,
                            })
                          }
                        >
                          {statusOptions.map((option) => (
                            <option key={option.value} value={option.value}>
                              {option.label}
                            </option>
                          ))}
                        </select>
                      </div>
                      <div className="space-y-2">
                        <Label htmlFor={`${datapoint.id}-value`}>Valor / respuesta</Label>
                        <Input
                          id={`${datapoint.id}-value`}
                          value={draft.value}
                          onChange={(event) => updateDraft(datapoint.id, { value: event.target.value })}
                        />
                      </div>
                      <div className="space-y-2">
                        <Label htmlFor={`${datapoint.id}-evidence`}>Evidencia</Label>
                        <Input
                          id={`${datapoint.id}-evidence`}
                          value={draft.evidence_reference}
                          onChange={(event) => updateDraft(datapoint.id, { evidence_reference: event.target.value })}
                        />
                      </div>
                      <div className="space-y-2 lg:col-span-3">
                        <Label htmlFor={`${datapoint.id}-note`}>Nota</Label>
                        <Textarea
                          id={`${datapoint.id}-note`}
                          value={draft.note}
                          onChange={(event) => updateDraft(datapoint.id, { note: event.target.value })}
                        />
                      </div>
                    </div>
                  </CardContent>
                </Card>
              )
            })}
          </div>

          <div className="flex justify-end">
            <Button type="button" onClick={handleSave} disabled={saving}>
              <Save className="h-4 w-4" />
              {saving ? "Guardando..." : "Guardar respuestas"}
            </Button>
          </div>
        </>
      )}
    </div>
  )
}

function SummaryMetric({ label, value }: { label: string; value: number | string }) {
  return (
    <div>
      <p className="text-xs font-medium uppercase text-muted-foreground">{label}</p>
      <p className="mt-1 text-lg font-semibold text-foreground">{value}</p>
    </div>
  )
}

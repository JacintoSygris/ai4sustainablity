"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { AlertCircle, Download, FileText, RefreshCw } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import {
  LaravelApiError,
  getLaravelReportDraft,
  getLaravelReportReadiness,
  getLaravelSession,
  laravelApiUrl,
  type LaravelReportDownload,
  type LaravelReportDraft,
  type LaravelReportReadiness,
} from "@/lib/laravel-api"
import {
  actionLabel,
  actionTarget,
  endpointHref,
  finalGenerationPending,
  formatPercent,
  humanizeKey,
  reportDownloadRows,
  sectionNumber,
  statusLabel,
  statusTone,
  uniqueLimitations,
} from "@/lib/report-draft-state.mjs"

export function ReportDraftPanel() {
  const router = useRouter()
  const [reloadCounter, setReloadCounter] = useState(0)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [readiness, setReadiness] = useState<LaravelReportReadiness | null>(null)
  const [draft, setDraft] = useState<LaravelReportDraft | null>(null)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const refreshReport = useCallback(() => {
    setReloadCounter((current) => current + 1)
  }, [])

  useEffect(() => {
    window.addEventListener("focus", refreshReport)

    return () => {
      window.removeEventListener("focus", refreshReport)
    }
  }, [refreshReport])

  useEffect(() => {
    let mounted = true

    async function loadP10() {
      setLoadingInitial(true)
      setErrorMessage(null)

      try {
        const [, readinessResponse, draftResponse] = await Promise.all([
          getLaravelSession(),
          getLaravelReportReadiness(),
          getLaravelReportDraft(),
        ])

        if (!mounted) {
          return
        }

        setReadiness(readinessResponse.data)
        setDraft(draftResponse.data)
      } catch (error) {
        if (error instanceof LaravelApiError && error.status === 401) {
          router.replace("/login")

          return
        }

        setErrorMessage("No se ha podido cargar el paquete P10 desde Laravel.")
      } finally {
        if (mounted) {
          setLoadingInitial(false)
        }
      }
    }

    loadP10()

    return () => {
      mounted = false
    }
  }, [reloadCounter, router])

  const sectionRows = useMemo(() => Object.entries(readiness?.sections ?? {}), [readiness])
  const downloadRows = useMemo(
    () => reportDownloadRows(readiness, draft) as Array<[string, LaravelReportDownload]>,
    [draft, readiness],
  )
  const limitationRows = useMemo(() => uniqueLimitations(readiness, draft), [draft, readiness])
  const pendingFinalGeneration = finalGenerationPending(readiness, draft)

  return (
    <div className="min-w-0 flex-1 space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">Informe ESRS</h1>
          <p className="mt-2 text-muted-foreground">
            Revisa el paquete de informe preparado por Laravel y los bloqueos pendientes antes de exportar.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" onClick={refreshReport}>
            <RefreshCw className="h-4 w-4" />
            Recargar
          </Button>
          <Button type="button" variant="outline" asChild>
            <a href={laravelApiUrl("/report/draft")} target="_blank" rel="noreferrer">
              <FileText className="h-4 w-4" />
              Borrador JSON
            </a>
          </Button>
        </div>
      </div>

      {errorMessage ? (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {errorMessage}
        </div>
      ) : null}

      {loadingInitial ? (
        <Card>
          <CardContent className="pt-6 text-sm text-muted-foreground">Cargando paquete P10...</CardContent>
        </Card>
      ) : !readiness || !draft ? (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <div className="flex items-start gap-3">
              <AlertCircle className="mt-0.5 h-5 w-5 text-amber-600" />
              <div>
                <p className="font-medium text-foreground">No hay workflow suficiente para preparar el informe</p>
                <p className="mt-1 text-sm text-muted-foreground">
                  Completa la caracterización y la materialidad antes de volver a P10.
                </p>
              </div>
            </div>
            <Button type="button" asChild>
              <Link href="/wizard/step-1">Volver a P5</Link>
            </Button>
          </CardContent>
        </Card>
      ) : (
        <>
          <Card>
            <CardContent className="grid gap-4 pt-6 md:grid-cols-4">
              <SummaryMetric label="Paquete" value={statusLabel(readiness.status)} />
              <SummaryMetric label="Datapoints decididos" value={draft.datapoints.decided_count} />
              <SummaryMetric label="Cobertura P9" value={formatPercent(draft.datapoints.completion_ratio)} />
              <SummaryMetric
                label="Generación final"
                value={pendingFinalGeneration ? "Pendiente" : statusLabel(readiness.sections.final_report_generation?.status)}
              />
            </CardContent>
          </Card>

          {pendingFinalGeneration ? (
            <div className="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <p className="font-medium">La generación final IA/XBRL no está implementada en esta release.</p>
              <p className="mt-1">
                Esta pantalla renderiza el borrador y las descargas disponibles desde Laravel; no inventa un informe
                final que el backend aún no produce.
              </p>
            </div>
          ) : null}

          {readiness.next_actions.length > 0 ? (
            <Card>
              <CardContent className="space-y-4 pt-6">
                <div>
                  <h2 className="text-lg font-semibold text-foreground">Siguientes acciones</h2>
                  <p className="mt-1 text-sm text-muted-foreground">
                    Laravel marca estas piezas como necesarias antes de cerrar el paquete.
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {readiness.next_actions.map((endpoint) => (
                    <Button key={endpoint} type="button" variant="outline" asChild>
                      <Link href={actionTarget(endpoint)}>{actionLabel(endpoint)}</Link>
                    </Button>
                  ))}
                </div>
              </CardContent>
            </Card>
          ) : null}

          <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div className="min-w-0 space-y-6">
              <Card>
                <CardContent className="space-y-4 pt-6">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">Estado por secciones</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      Lectura directa de `/api/report`; cada fila viene del backend Laravel.
                    </p>
                  </div>
                  <div className="overflow-hidden rounded-md border border-border">
                    {sectionRows.map(([key, section], index) => (
                      <div
                        key={key}
                        className={`grid gap-3 px-4 py-3 text-sm md:grid-cols-[1fr_140px_80px] md:items-center ${
                          index === 0 ? "" : "border-t border-border"
                        }`}
                      >
                        <div>
                          <p className="font-medium text-foreground">{humanizeKey(key)}</p>
                          {section.endpoint ? <p className="mt-1 text-xs text-muted-foreground">{section.endpoint}</p> : null}
                        </div>
                        <Badge className={statusTone(section.status)}>{statusLabel(section.status)}</Badge>
                        <p className="text-muted-foreground md:text-right">{sectionNumber(section)}</p>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardContent className="space-y-4 pt-6">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">Borrador renderizable</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      Resumen de empresa, materialidad confirmada y cobertura P9 desde `/api/report/draft`.
                    </p>
                  </div>
                  <div className="grid gap-4 md:grid-cols-3">
                    <SummaryMetric label="Empresa" value={draft.company.name || "Sin nombre"} />
                    <SummaryMetric label="Ejercicio" value={draft.company.reporting_year ?? "-"} />
                    <SummaryMetric label="NACE" value={draft.company.nace_code || "-"} />
                    <SummaryMetric label="Temas confirmados" value={draft.materiality.confirmed_topic_count} />
                    <SummaryMetric label="Datapoints totales" value={draft.datapoints.total_datapoint_count} />
                    <SummaryMetric label="Estado respuestas" value={statusLabel(draft.datapoints.response_status)} />
                  </div>
                  <div className="space-y-3">
                    <h3 className="text-sm font-semibold text-foreground">Temas materiales confirmados</h3>
                    <div className="flex flex-wrap gap-2">
                      {draft.materiality.confirmed_topics.length > 0 ? (
                        draft.materiality.confirmed_topics.map((topic) => (
                          <Badge key={topic.id} variant="outline">
                            {topic.esrs_code} · {topic.subtopic.es || topic.subtheme.es || topic.theme.es || topic.id}
                          </Badge>
                        ))
                      ) : (
                        <span className="text-sm text-muted-foreground">Sin temas confirmados.</span>
                      )}
                    </div>
                  </div>
                  <div className="space-y-2">
                    <h3 className="text-sm font-semibold text-foreground">Bloques P9</h3>
                    {draft.datapoints.blocks.map((block) => (
                      <div key={block.key ?? block.title} className="rounded-md border border-border px-3 py-2 text-sm">
                        <div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                          <p className="font-medium text-foreground">{block.title ?? block.key ?? "Bloque P9"}</p>
                          <p className="text-muted-foreground">
                            {block.decided_count}/{block.datapoint_count} decididos
                          </p>
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            </div>

            <div className="space-y-6">
              <Card>
                <CardContent className="space-y-4 pt-6">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">Descargas</h2>
                    <p className="mt-1 text-sm text-muted-foreground">Disponibilidad calculada por Laravel.</p>
                  </div>
                  <div className="space-y-3">
                    {downloadRows.map(([key, download]) => (
                      <div key={key} className="rounded-md border border-border px-3 py-3">
                        <div className="flex items-start justify-between gap-3">
                          <div>
                            <p className="text-sm font-medium text-foreground">{humanizeKey(key)}</p>
                            <p className="mt-1 text-xs text-muted-foreground">{download.content_type}</p>
                          </div>
                          <Badge className={statusTone(download.status)}>{statusLabel(download.status)}</Badge>
                        </div>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="mt-3 w-full"
                          disabled={download.status !== "ready"}
                          asChild={download.status === "ready"}
                        >
                          {download.status === "ready" ? (
                            <a href={endpointHref(download.endpoint, laravelApiUrl)}>
                              <Download className="h-4 w-4" />
                              Abrir
                            </a>
                          ) : (
                            <span>
                              <Download className="h-4 w-4" />
                              Pendiente
                            </span>
                          )}
                        </Button>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardContent className="space-y-3 pt-6">
                  <h2 className="text-lg font-semibold text-foreground">Limitaciones</h2>
                  {limitationRows.map((limitation) => (
                    <div key={limitation.key} className="rounded-md border border-border px-3 py-2 text-sm">
                      <p className="font-medium text-foreground">{humanizeKey(limitation.key)}</p>
                      <p className="mt-1 text-muted-foreground">{limitation.message}</p>
                    </div>
                  ))}
                </CardContent>
              </Card>
            </div>
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

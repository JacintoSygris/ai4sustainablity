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
  allSectionsReady,
  downloadLabel,
  endpointHref,
  formatPercent,
  isScopingOnly,
  limitationMessage,
  reportDownloadRows,
  sectionLabel,
  sectionNumber,
  statusLabel,
  statusTone,
  uniqueLimitations,
  visibleNextActions,
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

        setErrorMessage("No se ha podido cargar el informe desde la plataforma.")
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
  const nextActionRows = useMemo(() => visibleNextActions(readiness) as string[], [readiness])
  const scopingOnly = useMemo(() => isScopingOnly(readiness, draft), [draft, readiness])
  const reportOnlyNextAction = allSectionsReady(readiness)
  const reportPackageReady = readiness?.downloads?.report_package_html?.status === "ready"

  return (
    <div className="min-w-0 flex-1 space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">Informe ESRS</h1>
          <p className="mt-2 text-muted-foreground">
            Esto es lo que ya tienes preparado y lo que falta para cerrar el informe.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" onClick={refreshReport}>
            <RefreshCw className="h-4 w-4" />
            Recargar
          </Button>
          {reportPackageReady ? (
            <Button type="button" variant="outline" asChild>
              <a href={laravelApiUrl("/report/package")} target="_blank" rel="noreferrer">
                <FileText className="h-4 w-4" />
                Paquete HTML
              </a>
            </Button>
          ) : null}
          <Button type="button" variant="outline" asChild>
            <a href={laravelApiUrl("/report/draft")} target="_blank" rel="noreferrer">
              <FileText className="h-4 w-4" />
              Borrador JSON
            </a>
          </Button>
        </div>
      </div>

      <div className="rounded-lg border-2 border-amber-400 bg-amber-50 p-4">
        <div className="flex items-start gap-3">
          <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-amber-700" aria-hidden="true" />
          <div className="space-y-2">
            <p className="text-sm font-semibold text-amber-900">Alcance y límites de este informe</p>
            <p className="text-sm text-amber-900">
              Este paquete prepara y organiza tus evidencias ESRS 2023 como borrador de trabajo. Ten en cuenta sus
              límites:
            </p>
            <ul className="list-disc space-y-1 pl-5 text-sm text-amber-900">
              <li>No es una presentación oficial de tu informe ante ningún organismo.</li>
              <li>No es un servicio de aseguramiento ni de verificación independiente.</li>
              <li>No equivale a la atestación de la Taxonomía de la UE.</li>
              <li>No genera el formato electrónico xHTML ni iXBRL.</li>
            </ul>
          </div>
        </div>
      </div>

      {errorMessage ? (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {errorMessage}
        </div>
      ) : null}

      {loadingInitial ? (
        <Card>
          <CardContent className="pt-6 text-sm text-muted-foreground">Cargando el informe...</CardContent>
        </Card>
      ) : !readiness || !draft ? (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <div className="flex items-start gap-3">
              <AlertCircle className="mt-0.5 h-5 w-5 text-amber-600" />
              <div>
                <p className="font-medium text-foreground">No hay información suficiente para preparar el informe</p>
                <p className="mt-1 text-sm text-muted-foreground">
                  Completa la caracterización y la materialidad antes de preparar el informe.
                </p>
              </div>
            </div>
            <Button type="button" asChild>
              <Link href="/wizard/step-1">Ir a la encuesta inicial (paso 1)</Link>
            </Button>
          </CardContent>
        </Card>
      ) : (
        <>
          <Card>
            <CardContent className="space-y-3 pt-6">
              <h2 className="text-lg font-semibold text-foreground">Lo que ya tienes</h2>
              <div className="space-y-2 text-sm text-foreground">
                <p>
                  {draft.materiality.is_confirmed && draft.materiality.confirmed_topic_count > 0
                    ? `✔ Una lista de ${draft.materiality.confirmed_topic_count} temas materiales confirmados y justificados`
                    : "Pendiente: confirma tus temas materiales en el paso 4"}
                </p>
                <p>✔ El inventario de {draft.datapoints.total_datapoint_count} datapoints que pide el estándar</p>
                <p>
                  ✔ {draft.datapoints.decided_count} de {draft.datapoints.total_datapoint_count} datapoints ya decididos
                </p>
                {draft.datapoints.orphaned_response_count && draft.datapoints.orphaned_response_count > 0 ? (
                  <p className="text-amber-700">⚠ {draft.datapoints.orphaned_response_count} respuestas conservadas fuera de alcance</p>
                ) : null}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="grid gap-4 pt-6 md:grid-cols-4">
              <SummaryMetric label="Estado del informe" value={statusLabel(readiness.status)} />
              <SummaryMetric label="Datapoints decididos" value={draft.datapoints.decided_count} />
              <SummaryMetric label="Cobertura de datapoints" value={formatPercent(draft.datapoints.completion_ratio)} />
              <SummaryMetric
                label="Generación final"
                value={statusLabel(readiness.sections.final_report_generation?.status)}
              />
            </CardContent>
          </Card>

          <div className="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {limitationMessage({ key: "report_package_scope" })}
          </div>

          {scopingOnly ? (
            <Card className="border-amber-200 bg-amber-50">
              <CardContent className="space-y-2 pt-6 text-sm text-amber-950">
                <h2 className="text-lg font-semibold">Modo alcance</h2>
                <p>{limitationMessage({ key: "exact_ar16_matter_to_dr_mapping_pending" })}</p>
                <p>Tus decisiones de materialidad siguen guardadas y se aplicarán cuando la cobertura completa esté activa.</p>
              </CardContent>
            </Card>
          ) : null}

          {nextActionRows.length > 0 && !reportOnlyNextAction ? (
            <Card>
              <CardContent className="space-y-4 pt-6">
                <div>
                  <h2 className="text-lg font-semibold text-foreground">Siguientes acciones</h2>
                  <p className="mt-1 text-sm text-muted-foreground">
                    La plataforma marca estas piezas como necesarias antes de cerrar el informe.
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {nextActionRows.map((endpoint) => (
                    <Button key={endpoint} type="button" variant="outline" asChild>
                      <Link href={actionTarget(endpoint)}>{actionLabel(endpoint)}</Link>
                    </Button>
                  ))}
                </div>
              </CardContent>
            </Card>
          ) : (
            <Card>
              <CardContent className="space-y-4 pt-6">
                <h2 className="text-lg font-semibold text-foreground">¿Y ahora qué?</h2>
                <ul className="list-disc space-y-2 pl-5 text-sm text-muted-foreground">
                  <li>Comparte las descargas con tu gestoría, consultoría o auditoría.</li>
                  <li>Usa el CSV de datapoints como lista de recogida de datos dentro de tu empresa.</li>
                  <li>Vuelve cuando cambien tus cifras o tu actividad y actualiza las respuestas.</li>
                </ul>
              </CardContent>
            </Card>
          )}

          <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div className="min-w-0 space-y-6">
              <Card>
                <CardContent className="space-y-4 pt-6">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">Estado por secciones</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      Lectura directa del estado del informe; cada fila viene de la plataforma.
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
                          <p className="font-medium text-foreground">{sectionLabel(key)}</p>
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
                      Resumen de empresa, materialidad confirmada y cobertura de datapoints desde el borrador.
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
                    <h3 className="text-sm font-semibold text-foreground">Bloques de datapoints</h3>
                    {draft.datapoints.blocks.map((block) => (
                      <div key={block.key ?? block.title} className="rounded-md border border-border px-3 py-2 text-sm">
                        <div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                          <p className="font-medium text-foreground">{block.title ?? block.key ?? "Bloque de datapoints"}</p>
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
                    <p className="mt-1 text-sm text-muted-foreground">Disponibilidad calculada por la plataforma.</p>
                  </div>
                  <div className="space-y-3">
                    {downloadRows.map(([key, download]) => (
                      <div key={key} className="rounded-md border border-border px-3 py-3">
                        <div className="flex items-start justify-between gap-3">
                          <div>
                            <p className="text-sm font-medium text-foreground">{downloadLabel(key)}</p>
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
                      <p className="font-medium text-foreground">
                        {limitation.key === "exact_ar16_matter_to_dr_mapping_pending"
                          ? "Cobertura de datapoints"
                          : limitation.key === "report_package_scope"
                            ? "Alcance del paquete"
                            : "Limitación de esta versión"}
                      </p>
                      <p className="mt-1 text-muted-foreground">{limitationMessage(limitation)}</p>
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

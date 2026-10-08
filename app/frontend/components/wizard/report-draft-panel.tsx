"use client"

import { useSystemMessage, systemCopy } from "@/lib/i18n/use-system-message"

import { ui, formatUi } from "@/lib/i18n/messages.mjs"
import { useLocale } from "@/components/locale-provider"

import { useCallback, useEffect, useMemo, useState, type ChangeEvent, type FormEvent } from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { AlertCircle, CheckCircle2, Download, FileText, RefreshCw, Save, ShieldCheck } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { LocalizedDownload } from "@/components/localized-download"
import { Card, CardContent } from "@/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import {
  LaravelApiError,
  approveLaravelReportSnapshot,
  createLaravelReportSnapshot,
  getLaravelReportDraft,
  getLaravelReportReadiness,
  getLaravelReportSnapshots,
  getLaravelReportTaxonomyStatus,
  getLaravelReportingFacts,
  getLaravelSession,
  laravelApiUrl,
  reviewLaravelReportingFact,
  saveLaravelReportingFacts,
  type LaravelReportDownload,
  type LaravelReportDraft,
  type LaravelReportReadiness,
  type LaravelReportSnapshot,
  type LaravelReportTaxonomyStatus,
  type LaravelReportingFact,
  type LaravelReportingFactApplicability,
  type LaravelReportingFactInput,
  type LaravelReportingFactState,
  type LaravelReportingFactValueType,
} from "@/lib/laravel-api"
import {
  actionLabel,
  actionTarget,
  allSectionsReady,
  downloadLabel,
  endpointHref,
  formatPercent,
  isScopingOnly,
  latestReportSnapshot,
  limitationMessage,
  materialThemeGroups,
  reportDownloadRows,
  sectionLabel,
  sectionNumber,
  snapshotDownloadBlockers,
  statusLabel,
  statusTone,
  uniqueLimitations,
  visibleNextActions,
} from "@/lib/report-draft-state.mjs"

type FactFormState = {
  datapointId: string
  applicability: LaravelReportingFactApplicability
  valueType: LaravelReportingFactValueType
  value: string
  unit: string
  decimals: string
  language: string
  evidenceRef: string
  dimensionAxis: string
  dimensionMember: string
  nilReason: string
}

const initialFactForm: FactFormState = {
  datapointId: "",
  applicability: "applicable",
  valueType: "text",
  value: "",
  unit: "",
  decimals: "0",
  language: "es",
  evidenceRef: "",
  dimensionAxis: "",
  dimensionMember: "",
  nilReason: "",
}

export function ReportDraftPanel() {

  const { locale, changing } = useLocale()
  const tr = (message: string) => ui(locale, message)

  const router = useRouter()
  const [reloadCounter, setReloadCounter] = useState(0)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [storedReadiness, setReadiness] = useState<LaravelReportReadiness | null>(null)
  const [storedDraft, setDraft] = useState<LaravelReportDraft | null>(null)
  const [presentationLocale, setPresentationLocale] = useState<"es" | "en" | null>(null)
  const [presentationState, setPresentationState] = useState<"loading" | "ready" | "failed">("loading")
  const presentationReady = !changing && presentationLocale === locale && presentationState === "ready"
  const readiness = presentationReady ? storedReadiness : null
  const draft = presentationReady ? storedDraft : null
  const [facts, setFacts] = useState<LaravelReportingFactState | null>(null)
  const [taxonomyStatus, setTaxonomyStatus] = useState<LaravelReportTaxonomyStatus | null>(null)
  const [snapshots, setSnapshots] = useState<LaravelReportSnapshot[]>([])
  const [errorMessage, setErrorMessage] = useSystemMessage(null)
  const [operationMessage, setOperationMessage] = useSystemMessage(null)
  const [formError, setFormError] = useSystemMessage(null)
  const [factForm, setFactForm] = useState<FactFormState>(initialFactForm)
  const [reviewFact, setReviewFact] = useState<LaravelReportingFact | null>(null)
  const [reviewDeclaration, setReviewDeclaration] = useState("")
  const [reviewError, setReviewError] = useSystemMessage(null)
  const [approveDialogOpen, setApproveDialogOpen] = useState(false)
  const [approvalDeclaration, setApprovalDeclaration] = useState("")
  const [approvalError, setApprovalError] = useSystemMessage(null)
  const [busyAction, setBusyAction] = useState<string | null>(null)
  const confirmedMaterialThemes = useMemo(
    () => materialThemeGroups(draft?.materiality.confirmed_topics ?? [], locale),
    [draft?.materiality.confirmed_topics, locale],
  )

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
        const [, factsResponse, snapshotsResponse, taxonomyResponse] = await Promise.all([
          getLaravelSession(),
          getLaravelReportingFacts(),
          getLaravelReportSnapshots(),
          getLaravelReportTaxonomyStatus(),
        ])

        if (!mounted) {
          return
        }

        setFacts(factsResponse.data)
        setSnapshots(snapshotsResponse.data)
        setTaxonomyStatus(taxonomyResponse.data)
      } catch (error) {
        if (error instanceof LaravelApiError && error.status === 401) {
          router.replace("/login")

          return
        }

        setErrorMessage(systemCopy("No se ha podido cargar el informe desde la plataforma."))
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

  useEffect(() => {
    if (changing) return
    let active = true
    setPresentationState("loading")
    Promise.all([getLaravelReportReadiness(), getLaravelReportDraft()]).then(([status, report]) => {
      if (!active) return
      if ((status.data !== null && status.data.locale !== locale) || (report.data !== null && report.data.locale !== locale)) {
        throw new Error("Report presentation locale mismatch")
      }
      setReadiness(status.data); setDraft(report.data); setPresentationLocale(locale); setPresentationState("ready")
    }).catch(() => { if (active) { setPresentationState("failed"); setErrorMessage(systemCopy("No se pudieron actualizar las etiquetas de idioma. Inténtalo de nuevo.")) } })
    return () => { active = false }
  }, [locale, changing, reloadCounter])

  const sectionRows = useMemo(() => Object.entries(readiness?.sections ?? {}), [readiness])
  const downloadRows = useMemo(
    () => reportDownloadRows(readiness, draft) as Array<[string, LaravelReportDownload]>,
    [draft, readiness],
  )
  const limitationRows = useMemo(() => uniqueLimitations(readiness, draft), [draft, readiness])
  const nextActionRows = useMemo(() => visibleNextActions(readiness) as string[], [readiness])
  const scopingOnly = useMemo(() => isScopingOnly(readiness, draft), [draft, readiness])
  const reportOnlyNextAction = allSectionsReady(readiness)
  const latestSnapshot = latestReportSnapshot(snapshots) as LaravelReportSnapshot | null
  const snapshotBlockers = snapshotDownloadBlockers(latestSnapshot).map(tr)
  const approvedFreshSnapshot = snapshotBlockers.length === 0

  function updateFactForm<K extends keyof FactFormState>(key: K, value: FactFormState[K]) {
    setFactForm((current) => ({ ...current, [key]: value }))
  }

  async function handleSaveFact(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)
    setOperationMessage(null)

    const payload = buildFactPayload(factForm, locale)

    if (typeof payload === "string") {
      setFormError(payload)

      return
    }

    try {
      setBusyAction("save-fact")
      await saveLaravelReportingFacts({ facts: [payload] })
      setFactForm(initialFactForm)
      setOperationMessage(systemCopy("Dato factual guardado. Queda pendiente de revisión."))
      refreshReport()
    } catch (error) {
      setFormError(apiErrorMessage(error, tr("No se ha podido guardar el dato factual."), locale))
    } finally {
      setBusyAction(null)
    }
  }

  async function handleReviewFact() {
    const declaration = reviewDeclaration.trim()

    if (!reviewFact?.id || declaration === "") {
      setReviewError(systemCopy("Escribe una declaración de revisión antes de continuar."))

      return
    }

    try {
      setBusyAction("review-fact")
      setReviewError(null)
      await reviewLaravelReportingFact(reviewFact.id, { review_declaration: declaration })
      setReviewFact(null)
      setReviewDeclaration("")
      setOperationMessage(systemCopy("Dato factual marcado como revisado."))
      refreshReport()
    } catch (error) {
      setReviewError(apiErrorMessage(error, tr("No se ha podido revisar el dato factual."), locale))
    } finally {
      setBusyAction(null)
    }
  }

  async function handleCreateSnapshot() {
    try {
      setBusyAction("create-snapshot")
      setOperationMessage(null)
      await createLaravelReportSnapshot()
      setOperationMessage(systemCopy("Versión factual preparada."))
      refreshReport()
    } catch (error) {
      setOperationMessage(apiErrorMessage(error, tr("No se ha podido preparar la versión factual."), locale))
    } finally {
      setBusyAction(null)
    }
  }

  async function handleApproveSnapshot() {
    const declaration = approvalDeclaration.trim()

    if (!latestSnapshot || declaration === "") {
      setApprovalError(systemCopy("Escribe una declaración de persona única antes de continuar."))

      return
    }

    try {
      setBusyAction("approve-snapshot")
      setApprovalError(null)
      await approveLaravelReportSnapshot(latestSnapshot.id, { single_person_declaration: declaration })
      setApproveDialogOpen(false)
      setApprovalDeclaration("")
      setOperationMessage(systemCopy("Versión factual aprobada. Las descargas quedan habilitadas mientras siga vigente."))
      refreshReport()
    } catch (error) {
      setApprovalError(apiErrorMessage(error, tr("No se ha podido aprobar la versión factual."), locale))
    } finally {
      setBusyAction(null)
    }
  }

  return (
    <div className="min-w-0 flex-1 space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">{tr("Informe NEIS")}</h1>
          <p className="mt-2 text-muted-foreground">
            {" "}{tr("Esto muestra qué asuntos materiales, datos normativos y evidencias están registrados, qué falta y qué descargas pueden generarse.")}{" "}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" onClick={refreshReport}>
            <RefreshCw className="h-4 w-4" />
            {" "}{tr("Recargar")}{" "}</Button>
          {storedReadiness?.downloads?.report_package_html?.status === "ready" ? (
            <LocalizedDownload disabled={!presentationReady} type="button" variant="outline" href={laravelApiUrl("/report/package")} target="_blank" rel="noreferrer">
                <FileText className="h-4 w-4" />
                {" "}{tr("Paquete HTML")}{" "}</LocalizedDownload>
          ) : null}
          <LocalizedDownload disabled={!presentationReady} type="button" variant="outline" href={laravelApiUrl("/report/draft")} target="_blank" rel="noreferrer">
              <FileText className="h-4 w-4" />
              {" "}{tr("Borrador JSON")}{" "}</LocalizedDownload>
        </div>
      </div>

      <div className="rounded-lg border-2 border-amber-400 bg-amber-50 p-4">
        <div className="flex items-start gap-3">
          <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-amber-700" aria-hidden="true" />
          <div className="space-y-2">
            <p className="text-sm font-semibold text-amber-900">{tr("Alcance y límites de estas salidas")}</p>
            <p className="text-sm text-amber-900">
              {" "}{tr("Este paquete organiza el estado de preparación ASG/NEIS. Ten en cuenta sus límites:")}{" "}</p>
            <ul className="list-disc space-y-1 pl-5 text-sm text-amber-900">
              <li>{tr("No es una presentación oficial de tu informe ante ningún organismo.")}</li>
              <li>{tr("No es un servicio de aseguramiento ni de verificación independiente.")}</li>
              <li>{tr("No equivale a la atestación de la Taxonomía de la UE.")}</li>
              <li>{tr("El candidato XHTML/iXBRL no se ofrece como descarga salvo que la validación técnica externa esté disponible y superada.")}</li>
            </ul>
          </div>
        </div>
      </div>

      {operationMessage ? (
        <div role="status" className="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
          {operationMessage}
        </div>
      ) : null}

      {errorMessage ? (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {errorMessage}
        </div>
      ) : null}

      {!presentationReady ? (
        <Card><CardContent className="space-y-3 pt-6 text-sm text-muted-foreground">
          <p role="status">{presentationState === "failed" && !changing ? tr("No se pudieron actualizar las etiquetas de idioma. Inténtalo de nuevo.") : tr("Actualizando las etiquetas de idioma...")}</p>
          {reportDownloadRows(storedReadiness, storedDraft).map(([key, download]) => (
            <LocalizedDownload key={key} disabled type="button" variant="outline" href={endpointHref(download.endpoint, laravelApiUrl)}>{tr(downloadLabel(key))}</LocalizedDownload>
          ))}
          {approvedFreshSnapshot ? (
            <div className="flex flex-wrap gap-2">
              <LocalizedDownload disabled href={laravelApiUrl("/report/html")}>{tr("HTML factual")}</LocalizedDownload>
              <LocalizedDownload disabled href={laravelApiUrl("/guided-report/docx")}>{tr("DOCX factual")}</LocalizedDownload>
              <LocalizedDownload disabled href={laravelApiUrl("/guided-report/evidence-bundle")}>{tr("Paquete de evidencias")}</LocalizedDownload>
            </div>
          ) : null}
        </CardContent></Card>
      ) : loadingInitial ? (
        <Card>
          <CardContent className="pt-6 text-sm text-muted-foreground">{tr("Cargando el resumen...")}</CardContent>
        </Card>
      ) : !readiness || !draft ? (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <div className="flex items-start gap-3">
              <AlertCircle className="mt-0.5 h-5 w-5 text-amber-600" />
              <div>
                <p className="font-medium text-foreground">{tr("No hay información suficiente para preparar el informe")}</p>
                <p className="mt-1 text-sm text-muted-foreground">
                  {" "}{tr("Completa la caracterización y la materialidad antes de preparar el informe.")}{" "}</p>
              </div>
            </div>
            <Button type="button" asChild>
              <Link href="/wizard/step-1">{tr("Ir a la encuesta inicial (paso 1)")}</Link>
            </Button>
          </CardContent>
        </Card>
      ) : (
        <>
          <Card>
            <CardContent className="space-y-3 pt-6">
              <h2 className="text-lg font-semibold text-foreground">{tr("Lo que está registrado")}</h2>
              <div className="space-y-2 text-sm text-foreground">
                <p>
                  {draft.materiality.is_confirmed && draft.materiality.confirmed_theme_count > 0
                    ? formatUi(locale, "Una lista de {0} temas materiales confirmados y justificados", [draft.materiality.confirmed_theme_count])
                    : tr("Pendiente: confirma tus temas materiales en el paso 4")}
                </p>
                <p>{tr("El inventario de")}{" "}{draft.datapoints.total_datapoint_count} {" "}{tr("datos que pide el estándar")}</p>
                <p>
                  {draft.datapoints.decided_count} {" "}{tr("de")}{" "}{draft.datapoints.total_datapoint_count} {" "}{tr("datos ya decididos")}{" "}</p>
                {draft.datapoints.orphaned_response_count && draft.datapoints.orphaned_response_count > 0 ? (
                  <p className="text-amber-700">
                    {draft.datapoints.orphaned_response_count} {" "}{tr("respuestas conservadas fuera de alcance")}{" "}</p>
                ) : null}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="grid gap-4 pt-6 md:grid-cols-4">
              <SummaryMetric label={tr("Estado del informe")} value={tr(statusLabel(readiness.status))} />
              <SummaryMetric label={tr("Datos decididos")} value={draft.datapoints.decided_count} />
              <SummaryMetric label={tr("Cobertura de datos")} value={formatPercent(draft.datapoints.completion_ratio)} />
              <SummaryMetric
                label={tr("Generación final")}
                value={tr(statusLabel(readiness.sections.final_report_generation?.status))}
              />
            </CardContent>
          </Card>

          <div className="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {tr(limitationMessage({ key: "report_package_scope" }))}
          </div>

          {taxonomyStatus ? (
            <Card>
              <CardContent className="space-y-2 pt-6 text-sm">
                <h2 className="text-lg font-semibold text-foreground">{tr("Taxonomía NEIS externa")}</h2>
                <p className="text-foreground">
                  {tr("Taxonomía XBRL NEIS de EFRAG")} · {tr("versión")} {taxonomyStatus.taxonomy.version} · {tr("perfil")}{" "}
                  {taxonomyStatus.reporting_profile}
                </p>
                <Badge className={statusTone(taxonomyStatus.availability.state)}>
                  {tr(statusLabel(taxonomyStatus.availability.state))}
                </Badge>
                {taxonomyStatus.availability.reason_code ? (
                  <p className="text-muted-foreground">{tr("Estado:")} {tr(statusLabel(taxonomyStatus.availability.reason_code))}</p>
                ) : null}
              </CardContent>
            </Card>
          ) : null}

          {scopingOnly ? (
            <Card className="border-amber-200 bg-amber-50">
              <CardContent className="space-y-2 pt-6 text-sm text-amber-950">
                <h2 className="text-lg font-semibold">{tr("Modo alcance")}</h2>
                <p>{tr(limitationMessage({ key: "exact_ar16_matter_to_dr_mapping_pending" }))}</p>
                <p>{tr("Tus decisiones de materialidad siguen guardadas y se aplicarán cuando la cobertura completa esté activa.")}</p>
              </CardContent>
            </Card>
          ) : null}

          {nextActionRows.length > 0 && !reportOnlyNextAction ? (
            <Card>
              <CardContent className="space-y-4 pt-6">
                <div>
                  <h2 className="text-lg font-semibold text-foreground">{tr("Siguientes acciones")}</h2>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {" "}{tr("La plataforma marca estas piezas como necesarias antes de cerrar el informe.")}{" "}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {nextActionRows.map((endpoint) => (
                    <Button key={endpoint} type="button" variant="outline" asChild>
                      <Link href={actionTarget(endpoint)}>{tr(actionLabel(endpoint))}</Link>
                    </Button>
                  ))}
                </div>
              </CardContent>
            </Card>
          ) : (
            <Card>
              <CardContent className="space-y-4 pt-6">
                <h2 className="text-lg font-semibold text-foreground">{tr("¿Y ahora qué?")}</h2>
                <ul className="list-disc space-y-2 pl-5 text-sm text-muted-foreground">
                  <li>{tr("Comparte las descargas como material de trabajo con las personas que revisen la información.")}</li>
                  <li>{tr("Usa el CSV de datos normativos como lista de recogida de información dentro de tu empresa.")}</li>
                  <li>{tr("Vuelve cuando cambien tus cifras o tu actividad y actualiza las respuestas.")}</li>
                </ul>
              </CardContent>
            </Card>
          )}

          <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div className="min-w-0 space-y-6">
              <Card>
                <CardContent className="space-y-4 pt-6">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">{tr("Estado por secciones")}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {" "}{tr("Lectura directa del estado del informe; cada fila viene de la plataforma.")}{" "}</p>
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
                          <p className="font-medium text-foreground">{tr(sectionLabel(key))}</p>
                          {section.endpoint ? <p className="mt-1 text-xs text-muted-foreground">{section.endpoint}</p> : null}
                        </div>
                        <Badge className={statusTone(section.status)}>{tr(statusLabel(section.status))}</Badge>
                        <p className="text-muted-foreground md:text-right">{sectionNumber(section)}</p>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardContent className="space-y-4 pt-6">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">{tr("Borrador renderizable")}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {" "}{tr("Resumen de empresa, materialidad confirmada y cobertura de datos desde el borrador.")}{" "}</p>
                  </div>
                  <div className="grid gap-4 md:grid-cols-3">
                    <SummaryMetric label={tr("Empresa")} value={draft.company.name || tr("Sin nombre")} />
                    <SummaryMetric label={tr("Ejercicio")} value={draft.company.reporting_year ?? "-"} />
                    <SummaryMetric label="NACE" value={draft.company.nace_code || "-"} />
                    <SummaryMetric label={tr("Temas confirmados")} value={confirmedMaterialThemes.length} />
                    <SummaryMetric label={tr("Datos normativos totales")} value={draft.datapoints.total_datapoint_count} />
                    <SummaryMetric label={tr("Estado respuestas")} value={tr(statusLabel(draft.datapoints.response_status))} />
                  </div>
                  <div className="space-y-3">
                    <h3 className="text-sm font-semibold text-foreground">{tr("Temas materiales confirmados")}</h3>
                    <div className="flex flex-wrap gap-2">
                      {confirmedMaterialThemes.length > 0 ? (
                        confirmedMaterialThemes.map((topic) => (
                          <Badge key={topic.esrs_code} variant="outline">
                            {topic.esrs_code} · {topic.label}
                          </Badge>
                        ))
                      ) : (
                        <span className="text-sm text-muted-foreground">{tr("Sin temas confirmados.")}</span>
                      )}
                    </div>
                  </div>
                  <div className="space-y-2">
                    <h3 className="text-sm font-semibold text-foreground">{tr("Bloques de datos normativos")}</h3>
                    {draft.datapoints.blocks.map((block) => (
                      <div key={block.key ?? block.title} className="rounded-md border border-border px-3 py-2 text-sm">
                        <div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                          <p className="font-medium text-foreground">{block.title ?? block.key ?? tr("Bloque de datos normativos")}</p>
                          <p className="text-muted-foreground">
                            {block.decided_count}/{block.datapoint_count} {" "}{tr("decididos")}{" "}</p>
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardContent className="space-y-5 pt-6">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">{tr("Datos factuales y revisión")}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {" "}{tr("Las respuestas de datos normativos no se convierten automáticamente en hechos aprobados. Guarda cada dato factual que quieras usar en las salidas y revísalo antes de preparar la aprobación.")}{" "}</p>
                  </div>

                  <div className="grid gap-4 md:grid-cols-2">
                    <SummaryMetric label={tr("Datos factuales guardados")} value={facts?.persisted_fact_count ?? 0} />
                    <SummaryMetric label={tr("Propuestas de datos pendientes")} value={facts?.pending_p9_suggestion_count ?? 0} />
                  </div>

                  <FactList
                    facts={facts?.persisted_facts ?? []}
                    legacyFacts={facts?.pending_p9_suggestions ?? []}
                    onReview={(fact) => {
                      setReviewFact(fact)
                      setReviewDeclaration("")
                      setReviewError(null)
                    }}
                  />

                  <form className="space-y-4 rounded-md border border-border p-4" onSubmit={handleSaveFact}>
                    <div>
                      <h3 className="text-base font-semibold text-foreground">{tr("Añadir o actualizar dato factual")}</h3>
                      <p className="mt-1 text-sm text-muted-foreground">
                        {" "}{tr("El guardado crea un dato con revisión requerida; la evidencia queda en la plataforma y no se imprime como descarga pública.")}{" "}</p>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                      <LabeledInput
                        id="fact-datapoint-id"
                        label={tr("Identificador del dato normativo")}
                        value={factForm.datapointId}
                        onChange={(event) => updateFactForm("datapointId", event.target.value)}
                      />
                      <LabeledSelect
                        id="fact-applicability"
                        label={tr("Aplicabilidad")}
                        value={factForm.applicability}
                        onChange={(event) =>
                          updateFactForm("applicability", event.target.value as LaravelReportingFactApplicability)
                        }
                        options={[
                          ["applicable", tr("Aplicable")],
                          ["not_applicable", tr("No aplicable")],
                          ["pending", tr("Pendiente")],
                          ["unavailable", tr("No disponible")],
                          ["blocked", tr("Bloqueado")],
                        ]}
                      />
                      <LabeledSelect
                        id="fact-value-type"
                        label={tr("Tipo")}
                        value={factForm.valueType}
                        onChange={(event) => updateFactForm("valueType", event.target.value as LaravelReportingFactValueType)}
                        options={[
                          ["text", tr("Texto")],
                          ["number", tr("Número")],
                          ["monetary", tr("Importe")],
                          ["integer", tr("Número entero")],
                          ["boolean", tr("Sí o no")],
                          ["date", tr("Fecha")],
                          ["enumeration", tr("Enumeración")],
                          ["nil", tr("Sin valor")],
                        ]}
                      />
                      <LabeledInput
                        id="fact-value"
                        label={tr("Valor")}
                        value={factForm.value}
                        disabled={factForm.valueType === "nil"}
                        onChange={(event) => updateFactForm("value", event.target.value)}
                      />
                      <LabeledInput
                        id="fact-unit"
                        label={tr("Unidad")}
                        value={factForm.unit}
                        onChange={(event) => updateFactForm("unit", event.target.value)}
                      />
                      <LabeledInput
                        id="fact-decimals"
                        label={tr("Decimales")}
                        type="number"
                        min="0"
                        max="12"
                        value={factForm.decimals}
                        onChange={(event) => updateFactForm("decimals", event.target.value)}
                      />
                      <LabeledInput
                        id="fact-language"
                        label={tr("Idioma")}
                        value={factForm.language}
                        onChange={(event) => updateFactForm("language", event.target.value)}
                      />
                      <LabeledInput
                        id="fact-nil-reason"
                        label={tr("Motivo sin valor")}
                        value={factForm.nilReason}
                        onChange={(event) => updateFactForm("nilReason", event.target.value)}
                      />
                      <LabeledInput
                        id="fact-dimension-axis"
                        label={tr("Eje de la dimensión")}
                        value={factForm.dimensionAxis}
                        onChange={(event) => updateFactForm("dimensionAxis", event.target.value)}
                      />
                      <LabeledInput
                        id="fact-dimension-member"
                        label={tr("Valor de la dimensión")}
                        value={factForm.dimensionMember}
                        onChange={(event) => updateFactForm("dimensionMember", event.target.value)}
                      />
                    </div>

                    <div>
                      <label htmlFor="fact-evidence" className="text-sm font-medium text-foreground">
                        {" "}{tr("Referencia de evidencia")}{" "}</label>
                      <Textarea
                        id="fact-evidence"
                        className="mt-1"
                        value={factForm.evidenceRef}
                        onChange={(event) => updateFactForm("evidenceRef", event.target.value)}
                      />
                    </div>

                    {formError ? <p className="text-sm text-destructive">{formError}</p> : null}
                    {(factForm.applicability === "pending" || factForm.applicability === "blocked") ? (
                      <p className="text-sm text-amber-700">{tr("Los datos pendientes o bloqueados no pueden revisarse.")}</p>
                    ) : null}
                    <Button type="submit" disabled={busyAction === "save-fact"}>
                      <Save className="h-4 w-4" />
                      {" "}{tr("Guardar dato factual")}{" "}</Button>
                  </form>
                </CardContent>
              </Card>
            </div>

            <div className="space-y-6">
              <Card>
                <CardContent className="space-y-4 pt-6">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">{tr("Versión factual y aprobación")}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {" "}{tr("Las salidas factuales se habilitan solo con una versión vigente y aprobada.")}{" "}</p>
                  </div>

                  <SnapshotSummary snapshot={latestSnapshot} />

                  <div className="flex flex-col gap-2">
                    <Button type="button" onClick={handleCreateSnapshot} disabled={busyAction === "create-snapshot"}>
                      <ShieldCheck className="h-4 w-4" />
                      {" "}{tr("Preparar versión factual")}{" "}</Button>
                    {latestSnapshot && !latestSnapshot.is_approved ? (
                      <Button type="button" variant="outline" onClick={() => setApproveDialogOpen(true)}>
                        <CheckCircle2 className="h-4 w-4" />
                        {" "}{tr("Aprobar versión factual")}{" "}</Button>
                    ) : null}
                  </div>

                  <div className="rounded-md border border-border p-3">
                    <h3 className="text-sm font-semibold text-foreground">{tr("Salidas basadas en una versión aprobada")}</h3>
                    {approvedFreshSnapshot ? (
                      <div className="mt-3 space-y-2">
                        <LocalizedDownload disabled={!presentationReady} type="button" variant="outline" className="w-full justify-start" href={laravelApiUrl("/report/html")}>
                            <Download className="h-4 w-4" />
                            {" "}{tr("HTML factual")}{" "}</LocalizedDownload>
                        <LocalizedDownload disabled={!presentationReady} type="button" variant="outline" className="w-full justify-start" href={laravelApiUrl("/guided-report/docx")}>
                            <Download className="h-4 w-4" />
                            {" "}{tr("DOCX factual")}{" "}</LocalizedDownload>
                        <LocalizedDownload disabled={!presentationReady} type="button" variant="outline" className="w-full justify-start" href={laravelApiUrl("/guided-report/evidence-bundle")}>
                            <Download className="h-4 w-4" />
                            {" "}{tr("Paquete de evidencias")}{" "}</LocalizedDownload>
                      </div>
                    ) : (
                      <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                        {snapshotBlockers.map((blocker) => (
                          <li key={blocker}>{blocker}</li>
                        ))}
                      </ul>
                    )}
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardContent className="space-y-4 pt-6">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">{tr("Paquete HTML de preparación")}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {" "}{tr("Disponibilidad del paquete antiguo de trabajo. No es un informe aprobado.")}{" "}</p>
                  </div>
                  <div className="space-y-3">
                    {downloadRows.map(([key, download]) => (
                      <div key={key} className="rounded-md border border-border px-3 py-3">
                        <div className="flex items-start justify-between gap-3">
                          <div>
                            <p className="text-sm font-medium text-foreground">{tr(downloadLabel(key))}</p>
                            <p className="mt-1 text-xs text-muted-foreground">{download.content_type}</p>
                          </div>
                          <Badge className={statusTone(download.status)}>{tr(statusLabel(download.status))}</Badge>
                        </div>
                        <LocalizedDownload
                          variant="outline"
                          size="sm"
                          className="mt-3 w-full"
                          disabled={!presentationReady || download.status !== "ready"}
                          href={endpointHref(download.endpoint, laravelApiUrl)}
                        >
                          <Download className="h-4 w-4" />
                          {tr(download.status === "ready" ? "Abrir" : "Pendiente")}
                        </LocalizedDownload>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardContent className="space-y-3 pt-6">
                  <h2 className="text-lg font-semibold text-foreground">{tr("Limitaciones")}</h2>
                  {limitationRows.map((limitation) => (
                    <div key={limitation.key} className="rounded-md border border-border px-3 py-2 text-sm">
                      <p className="font-medium text-foreground">
                        {limitation.key === "exact_ar16_matter_to_dr_mapping_pending"
                          ? tr("Cobertura de datos normativos")
                          : limitation.key === "report_package_scope"
                            ? tr("Alcance del paquete")
                            : tr("Limitación de esta versión")}
                      </p>
                      <p className="mt-1 text-muted-foreground">{tr(limitationMessage(limitation))}</p>
                    </div>
                  ))}
                </CardContent>
              </Card>
            </div>
          </div>
        </>
      )}

      <Dialog open={reviewFact !== null} onOpenChange={(open) => !open && setReviewFact(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{tr("Marcar dato factual como revisado")}</DialogTitle>
            <DialogDescription>
              {" "}{tr("Escribe una declaración propia de revisión. La plataforma no la rellena por ti.")}{" "}</DialogDescription>
          </DialogHeader>
          <div>
            <label htmlFor="fact-review-declaration" className="text-sm font-medium text-foreground">
              {" "}{tr("Declaración de revisión")}{" "}</label>
            <Textarea
              id="fact-review-declaration"
              className="mt-1"
              value={reviewDeclaration}
              onChange={(event) => setReviewDeclaration(event.target.value)}
            />
            {reviewError ? <p className="mt-2 text-sm text-destructive">{reviewError}</p> : null}
          </div>
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => setReviewFact(null)}>
              {" "}{tr("Cancelar")}{" "}</Button>
            <Button type="button" onClick={handleReviewFact} disabled={busyAction === "review-fact"}>
              {" "}{tr("Marcar como revisado")}{" "}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={approveDialogOpen} onOpenChange={setApproveDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{tr("Aprobar versión factual")}</DialogTitle>
            <DialogDescription>
              {" "}{tr("Declara que una única persona asume la preparación, la revisión y la aprobación de esta versión.")}{" "}</DialogDescription>
          </DialogHeader>
          <div>
            <label htmlFor="snapshot-approval-declaration" className="text-sm font-medium text-foreground">
              {" "}{tr("Declaración de persona única")}{" "}</label>
            <Textarea
              id="snapshot-approval-declaration"
              className="mt-1"
              value={approvalDeclaration}
              onChange={(event) => setApprovalDeclaration(event.target.value)}
            />
            {approvalError ? <p className="mt-2 text-sm text-destructive">{approvalError}</p> : null}
          </div>
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => setApproveDialogOpen(false)}>
              {" "}{tr("Cancelar")}{" "}</Button>
            <Button type="button" onClick={handleApproveSnapshot} disabled={busyAction === "approve-snapshot"}>
              {" "}{tr("Aprobar versión factual")}{" "}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function buildFactPayload(form: FactFormState, locale: "es" | "en" = "es"): LaravelReportingFactInput | string {
  const tr = (message: string) => ui(locale, message)

  const datapointId = form.datapointId.trim()
  const evidence = form.evidenceRef.trim()
  const language = form.language.trim()
  const unit = form.unit.trim()
  const nilReason = form.nilReason.trim()

  if (datapointId === "") {
    return tr("Indica el identificador del dato normativo.")
  }

  if (["not_applicable", "unavailable"].includes(form.applicability) && evidence === "") {
    return tr("Los datos no aplicables o no disponibles necesitan una referencia de evidencia.")
  }

  const dimensions = []
  const axis = form.dimensionAxis.trim()
  const member = form.dimensionMember.trim()

  if ((axis === "") !== (member === "")) {
    return tr("La dimensión opcional necesita un eje y un valor.")
  }

  if (axis !== "" && member !== "") {
    dimensions.push({ axis, member })
  }

  let value: LaravelReportingFactInput["value"] = null
  let nil = false
  let decimals: number | null = null
  let finalUnit: string | null = unit || null
  let finalLanguage: string | null = language || null
  let finalNilReason: string | null = nilReason || null

  if (form.valueType === "text") {
    if (form.value.trim() === "") {
      return tr("El texto no puede estar vacío.")
    }

    if (language === "") {
      return tr("El idioma es obligatorio para los datos de texto.")
    }

    value = { text: form.value.trim() }
    finalNilReason = null
  } else if (["number", "monetary", "integer"].includes(form.valueType)) {
    const rawValue = form.value.trim()
    const rawDecimals = form.decimals.trim()

    if (rawValue === "") {
      return tr("El valor numérico no es válido.")
    }

    if (form.valueType === "integer" && !/^[+-]?\d+$/.test(rawValue)) {
      return tr("El valor entero no admite decimales.")
    }

    if (form.valueType !== "integer" && !/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/.test(rawValue)) {
      return tr("El valor numérico no es válido.")
    }

    const decimalValue = Number(rawDecimals)

    if (unit === "") {
      return tr("Los datos numéricos necesitan una unidad.")
    }

    if (!/^\d+$/.test(rawDecimals) || !Number.isInteger(decimalValue) || decimalValue < 0 || decimalValue > 12) {
      return tr("Los decimales deben ser un entero entre 0 y 12.")
    }

    // The backend accepts decimal strings; preserve every digit before serialization.
    value = rawValue
    decimals = decimalValue
    finalLanguage = null
    finalNilReason = null
  } else if (form.valueType === "boolean") {
    const normalized = form.value.trim().toLowerCase()

    if (!["true", "false", "verdadero", "falso", "sí", "si", "yes", "no"].includes(normalized)) {
      return tr("El valor debe ser sí/no o verdadero/falso.")
    }

    value = ["true", "verdadero", "sí", "si", "yes"].includes(normalized)
    finalLanguage = null
    finalUnit = null
    finalNilReason = null
  } else if (form.valueType === "date" || form.valueType === "enumeration") {
    if (form.value.trim() === "") {
      return tr("El valor no puede estar vacío.")
    }

    value = form.value.trim()
    finalLanguage = null
    finalUnit = null
    finalNilReason = null
  } else {
    nil = true
    value = null
    finalUnit = null
    finalLanguage = null

    if (nilReason === "") {
      return tr("Los datos sin valor necesitan un motivo.")
    }
  }

  return {
    datapoint_id: datapointId,
    applicability: form.applicability,
    value_type: form.valueType,
    value,
    unit: finalUnit,
    decimals,
    dimensions,
    language: finalLanguage,
    nil,
    nil_reason: finalNilReason,
    evidence_refs: evidence === "" ? [] : [{ type: "ui_reference", value: evidence }],
    provenance: "api",
    approval_status: "review_required",
    blocking_reasons: [],
  }
}

function apiErrorMessage(error: unknown, fallback: string, locale: "es" | "en" = "es"): string {

  if (error instanceof LaravelApiError) {
    if (error.status === 409) {
      return formatUi(locale, "{0} Hay un bloqueo de estado que debes resolver antes de continuar.", [fallback])
    }

    if (error.status === 422) {
      return formatUi(locale, "{0} Revisa los campos obligatorios y el formato del dato.", [fallback])
    }
  }

  return fallback
}

function FactList({
  facts,
  legacyFacts,
  onReview,
}: {
  facts: LaravelReportingFact[]
  legacyFacts: LaravelReportingFact[]
  onReview: (fact: LaravelReportingFact) => void
}) {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  return (
    <div className="space-y-4">
      <div className="space-y-2">
        <h3 className="text-sm font-semibold text-foreground">{tr("Datos factuales guardados")}</h3>
        {facts.length === 0 ? (
          <p className="text-sm text-muted-foreground">{tr("Aún no hay datos factuales guardados.")}</p>
        ) : (
          facts.map((fact) => {
            const reviewBlocked = fact.applicability === "pending" || fact.applicability === "blocked" || fact.blocking_reasons.length > 0

            return (
              <div key={fact.fact_id} className="rounded-md border border-border px-3 py-3 text-sm">
                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                  <div className="min-w-0">
                    <p className="break-words font-medium text-foreground">{fact.datapoint_id}</p>
                    <p className="mt-1 text-muted-foreground">
                      {factStatusLabel(fact.approval_status, locale)} · {applicabilityLabel(fact.applicability, locale)} ·{" "}
                      {valueTypeLabel(fact.value_type, locale)}: {safeFactValue(fact, locale)}
                    </p>
                    {reviewBlocked ? (
                      <p className="mt-2 text-amber-700">{tr("Este dato está bloqueado o pendiente y no puede revisarse.")}</p>
                    ) : null}
                  </div>
                  {fact.approval_status === "review_required" ? (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={!fact.id || reviewBlocked}
                      onClick={() => onReview(fact)}
                    >
                      {" "}{tr("Marcar como revisado")}{" "}</Button>
                  ) : (
                    <Badge className={statusTone(fact.approval_status)}>{factStatusLabel(fact.approval_status, locale)}</Badge>
                  )}
                </div>
              </div>
            )
          })
        )}
      </div>

      {legacyFacts.length > 0 ? (
        <div className="space-y-2">
          <h3 className="text-sm font-semibold text-foreground">{tr("Propuestas de datos pendientes")}</h3>
          {legacyFacts.map((fact) => (
            <div key={fact.fact_id} className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950">
              <p className="font-medium">{fact.datapoint_id}</p>
              <p className="mt-1">{tr("Requiere revisión antes de convertirse en dato factual guardado.")}</p>
            </div>
          ))}
        </div>
      ) : null}
    </div>
  )
}

function SnapshotSummary({ snapshot }: { snapshot: LaravelReportSnapshot | null }) {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  if (!snapshot) {
    return (
      <div className="rounded-md border border-border p-3 text-sm text-muted-foreground">
        {" "}{tr("Aún no hay una versión factual preparada.")}{" "}</div>
    )
  }

  return (
    <div className="rounded-md border border-border p-3 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <Badge className={snapshot.stale_state === "fresh" ? statusTone("ready") : statusTone("blocked")}>
          {snapshot.stale_state === "fresh" ? tr("Vigente") : tr("Obsoleta")}
        </Badge>
        <Badge className={snapshot.is_approved ? statusTone("ready") : statusTone("incomplete")}>
          {snapshot.is_approved ? tr("Aprobado") : tr("Sin aprobar")}
        </Badge>
      </div>
      <p className="mt-2 text-muted-foreground">{tr("Preparado:")}{" "}{formatDate(snapshot.created_at, locale)}</p>
      {snapshot.approval?.approved_at ? (
        <p className="mt-1 text-muted-foreground">{tr("Aprobado:")}{" "}{formatDate(snapshot.approval.approved_at, locale)}</p>
      ) : null}
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

function LabeledInput({
  id,
  label,
  value,
  onChange,
  type = "text",
  min,
  max,
  disabled,
}: {
  id: string
  label: string
  value: string
  onChange: (event: ChangeEvent<HTMLInputElement>) => void
  type?: string
  min?: string
  max?: string
  disabled?: boolean
}) {
  return (
    <div>
      <label htmlFor={id} className="text-sm font-medium text-foreground">
        {label}
      </label>
      <Input id={id} className="mt-1" type={type} min={min} max={max} value={value} disabled={disabled} onChange={onChange} />
    </div>
  )
}

function LabeledSelect({
  id,
  label,
  value,
  options,
  onChange,
}: {
  id: string
  label: string
  value: string
  options: Array<[string, string]>
  onChange: (event: ChangeEvent<HTMLSelectElement>) => void
}) {
  return (
    <div>
      <label htmlFor={id} className="text-sm font-medium text-foreground">
        {label}
      </label>
      <select
        id={id}
        className="border-input mt-1 h-9 w-full rounded-md border bg-transparent px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
        value={value}
        onChange={onChange}
      >
        {options.map(([optionValue, optionLabel]) => (
          <option key={optionValue} value={optionValue}>
            {optionLabel}
          </option>
        ))}
      </select>
    </div>
  )
}

function valueTypeLabel(valueType: string, locale: "es" | "en" = "es"): string {
  const tr = (message: string) => ui(locale, message)

  const labels: Record<string, string> = {
    text: tr("Texto"),
    number: tr("Número"),
    monetary: tr("Importe"),
    integer: tr("Número entero"),
    boolean: tr("Sí o no"),
    date: tr("Fecha"),
    enumeration: tr("Opción"),
    nil: tr("Sin valor"),
  }

  return labels[valueType] ?? tr("Tipo no disponible")
}

function factStatusLabel(status: string, locale: "es" | "en" = "es"): string {
  const tr = (message: string) => ui(locale, message)

  if (status === "review_required") {
    return tr("Revisión requerida")
  }

  if (status === "reviewed") {
    return tr("Revisado")
  }

  if (status === "approved") {
    return tr("Aprobado")
  }

  return tr(statusLabel(status))
}

function applicabilityLabel(applicability: string, locale: "es" | "en" = "es"): string {
  const tr = (message: string) => ui(locale, message)

  const labels: Record<string, string> = {
    applicable: tr("Aplicable"),
    not_applicable: tr("No aplicable"),
    pending: tr("Pendiente"),
    unavailable: tr("No disponible"),
    blocked: tr("Bloqueado"),
  }

  return labels[applicability] ?? tr("No disponible")
}

function safeFactValue(fact: LaravelReportingFact, locale: "es" | "en" = "es"): string {
  const tr = (message: string) => ui(locale, message)

  if (fact.nil) {
    return fact.nil_reason || tr("Sin valor")
  }

  if (fact.value === null) {
    return "-"
  }

  if (typeof fact.value === "object") {
    return fact.value.text ?? tr("Valor estructurado")
  }

  if (typeof fact.value === "boolean") return fact.value ? tr("Sí") : tr("No")

  return String(fact.value)
}

function formatDate(value: string | null, locale: "es" | "en" = "es"): string {
  if (!value) {
    return "-"
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat(locale === "en" ? "en-GB" : "es-ES", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(date)
}

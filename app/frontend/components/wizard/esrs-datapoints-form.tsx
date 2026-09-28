"use client"

import { useOptionalStorage } from "@/lib/consent-storage"

import { useEffect, useMemo, useRef, useState } from "react"
import { useRouter } from "next/navigation"
import { AlertCircle, ChevronDown, ChevronUp, Download, RefreshCw, Save, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Term } from "@/components/wizard/term"
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible"
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
  type LaravelEsrsDatapointResponsesPayload,
} from "@/lib/laravel-api"
import {
  DEFAULT_OBLIGATION_FILTER,
  TRIAGE_OPTIONS,
  applyObligationFilter,
  compactDrafts,
  completionPlanItems,
  createResponseSaveQueue,
  datapointApplicabilitySummary,
  emptyDraft,
  flattenCorpus,
  groupRowsByStandard,
  honestCountsLabel,
  localStorageDraftKey,
  obligationBadge,
  parseDatapointResponsesConflict,
  phaseInBadgeLabel,
  p9ExportLinks,
  p9MappingSummary,
  phaseInSummary,
  responseLabel,
  sectionProgressLabel,
  triageSummary,
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
  triage?: "have_it" | "need_to_find" | "not_applicable_candidate"
}

const statusOptions: Array<{ value: LaravelEsrsDatapointResponseStatus; label: string }> = [
  { value: "draft", label: "Borrador" },
  { value: "completed", label: "Completado" },
  { value: "not_applicable", label: "No aplica" },
]

function datapointSubtitle(datapoint: LaravelEsrsDatapoint): string {
  return [datapoint.standard, datapoint.dr, datapoint.paragraph].filter(Boolean).join(" / ")
}

function draftsFromResponseState(responses: Record<string, any>): Record<string, DraftResponse> {
  return Object.fromEntries(
    Object.entries(responses ?? {}).map(([datapointId, response]) => [
      datapointId,
      {
        evidence_reference: response.evidence_reference ?? "",
        note: response.note ?? "",
        status: response.status,
        value: response.value ?? "",
        triage: response.triage,
      },
    ]),
  )
}

export function EsrsDatapointsForm() {
  const recoveryStorage = useOptionalStorage("recovery")
  const preferenceStorage = useOptionalStorage("preferences")
  const router = useRouter()
  const [reloadCounter, setReloadCounter] = useState(0)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [saving, setSaving] = useState(false)
  const [csrfToken, setCsrfToken] = useState<string>()
  const [corpus, setCorpus] = useState<LaravelEsrsDatapointCorpus | null>(null)
  const [drafts, setDrafts] = useState<Record<string, DraftResponse>>({})
  const draftsRef = useRef<Record<string, DraftResponse>>({})
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  // F2 new state (additive)
  const [characterizationId, setCharacterizationId] = useState<number | null>(null)
  const [serverUpdatedAt, setServerUpdatedAt] = useState<string | null>(null)
  const [orphaned, setOrphaned] = useState<{ count: number; responses: Record<string, any> } | null>(null)
  const [obligationFilter, setObligationFilter] = useState<"mandatory_only" | "all" | "phase_in">(DEFAULT_OBLIGATION_FILTER)
  const [viewMode, setViewMode] = useState<"inventory" | "respond">("respond")
  const [isDirty, setIsDirty] = useState(false)
  const [lastSavedAt, setLastSavedAt] = useState<Date | null>(null)
  const [autoSaveError, setAutoSaveError] = useState<string | null>(null)
  const [showIntro, setShowIntro] = useState(true)
  const [showRecoveryPrompt, setShowRecoveryPrompt] = useState(false)
  const [recoveryIsConflict, setRecoveryIsConflict] = useState(false)
  const [pendingRecoveryDrafts, setPendingRecoveryDrafts] = useState<Record<string, DraftResponse> | null>(null)
  const autoSaveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const dirtyRef = useRef(false)
  const editVersionRef = useRef(0)
  const saveQueueRef = useRef(createResponseSaveQueue(0))

  useEffect(() => {
    let mounted = true

    async function loadP9() {
      saveQueueRef.current.invalidate()
      if (autoSaveTimerRef.current) clearTimeout(autoSaveTimerRef.current)
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
        const corpusData = corpusResponse.data
        const responsesData = responsesResponse.data
        setCorpus(corpusData)
        setCharacterizationId(responsesData?.characterization_id ?? corpusData?.characterization_id ?? null)
        setServerUpdatedAt(responsesData?.updated_at ?? null)
        saveQueueRef.current = createResponseSaveQueue(responsesData?.revision ?? 0)
        editVersionRef.current = 0
        setOrphaned(responsesData?.orphaned ?? null)
        const seededDrafts = draftsFromResponseState(responsesData?.responses ?? {})
        draftsRef.current = seededDrafts
        setDrafts(seededDrafts)

        // intro dismiss (local only)
        try {
          if (typeof window !== "undefined" && preferenceStorage.getItem("p9_intro_dismissed") === "1") {
            setShowIntro(false)
          }
        } catch {}

        // schedule recovery check (compare local mirror vs server updated_at)
        const charId = responsesData?.characterization_id ?? corpusData?.characterization_id ?? null
        if (charId != null) {
          setTimeout(() => checkRecovery(charId, responsesData?.updated_at ?? null, seededDrafts), 0)
        }
      } catch (error) {
        if (error instanceof LaravelApiError && error.status === 401) {
          router.replace("/login")

          return
        }

        setErrorMessage("No se han podido cargar los datos normativos del paso 5 desde la plataforma.")
      } finally {
        if (mounted) {
          setLoadingInitial(false)
        }
      }
    }

    loadP9()

    return () => {
      mounted = false
      saveQueueRef.current.invalidate()
    }
  }, [reloadCounter, router])

  // beforeunload guard while dirty (F2)
  useEffect(() => {
    const handler = (e: BeforeUnloadEvent) => {
      if (dirtyRef.current) {
        e.preventDefault()
        e.returnValue = ""
      }
    }
    window.addEventListener("beforeunload", handler)
    return () => window.removeEventListener("beforeunload", handler)
  }, [])

  // cleanup timer on unmount
  useEffect(() => {
    return () => {
      if (autoSaveTimerRef.current) clearTimeout(autoSaveTimerRef.current)
    }
  }, [])

  const rows = useMemo(() => flattenCorpus(corpus) as DatapointRow[], [corpus])
  const mappingSummary = useMemo(() => p9MappingSummary(corpus), [corpus])
  const phaseSummary = useMemo(() => phaseInSummary(corpus), [corpus])
  const completionItems = useMemo(() => completionPlanItems(corpus), [corpus])

  // F2 computed for sections + filters + triage
  const filteredRows = useMemo(() => {
    const base = rows as any[]
    return applyObligationFilter(base, obligationFilter)
  }, [rows, obligationFilter])
  const grouped = useMemo(() => groupRowsByStandard(filteredRows), [filteredRows])
  const triageCounts = useMemo(() => triageSummary(drafts), [drafts])
  const honestLabel = useMemo(() => {
    const sum = (corpus as any)?.summary || (corpus as any)?.preview?.datapoint_estimate || {}
    return honestCountsLabel({ total_datapoint_count: sum.total_datapoint_count, voluntary_datapoint_count: sum.voluntary_datapoint_count })
  }, [corpus])
  const lessThan750 = phaseSummary.lessThan750

  const updateDraft = (datapointId: string, patch: Partial<DraftResponse>) => {
    setDrafts((current) => {
      const next = {
        ...current,
        [datapointId]: {
          ...emptyDraft(),
          ...(current[datapointId] ?? {}),
          ...patch,
        },
      }
      draftsRef.current = next
      // mirror to localStorage immediately (F2 recovery)
      const id = characterizationId
      if (id != null) {
        try {
          const key = localStorageDraftKey(id)
          recoveryStorage.setItem(key, JSON.stringify({ drafts: next, savedAt: Date.now() }))
        } catch {}
      }
      return next
    })
    setIsDirty(true)
    dirtyRef.current = true
    editVersionRef.current = saveQueueRef.current.markEdited()
    setErrorMessage(null)
    setAutoSaveError(null)
    scheduleAutoSave()
  }

  const reload = () => setReloadCounter((current) => current + 1)

  // --- F2 auto-save + recovery + guard helpers (pure side effects on state) ---
  function clearLocalMirror(id: number | null) {
    if (id == null) return
    try {
      recoveryStorage.removeItem(localStorageDraftKey(id))
    } catch {}
  }

  function scheduleAutoSave() {
    if (autoSaveTimerRef.current) clearTimeout(autoSaveTimerRef.current)
    autoSaveTimerRef.current = setTimeout(() => {
      void performAutoSave()
    }, 1500)
  }

  function installConflictRecovery(error: unknown) {
    const conflict = parseDatapointResponsesConflict(error)
    if (!conflict) return false

    const localDrafts = draftsRef.current
    const remoteDrafts = draftsFromResponseState(conflict.responses)
    saveQueueRef.current.invalidate()
    saveQueueRef.current = createResponseSaveQueue(conflict.revision)
    editVersionRef.current = 0
    draftsRef.current = remoteDrafts
    setDrafts(remoteDrafts)
    setServerUpdatedAt(conflict.updated_at ?? null)
    setPendingRecoveryDrafts(localDrafts)
    setRecoveryIsConflict(true)
    setShowRecoveryPrompt(true)
    setIsDirty(false)
    dirtyRef.current = false
    setAutoSaveError("Las respuestas cambiaron en otra pestaña. Recupera tus cambios o conserva la versión actualizada.")
    return true
  }

  async function performAutoSave() {
    if (!characterizationId || !csrfToken) return
    if (!isDirty && !dirtyRef.current) return
    setAutoSaveError(null)
    try {
      const saved = await enqueueCurrentSave()
      if (saved.discarded) return
      setLastSavedAt(new Date())
      setServerUpdatedAt(saved.response.data.updated_at)
      if (saved.isLatestEdit) {
        setIsDirty(false)
        dirtyRef.current = false
        clearLocalMirror(characterizationId)
      }
      // soft refresh corpus counts if needed (no full reload to avoid flicker)
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")
        return
      }
      if (installConflictRecovery(error)) return
      setAutoSaveError("No se pudo guardar automáticamente. Usa el botón Guardar.")
      // keep dirty so manual save can retry
    }
  }

  function enqueueCurrentSave() {
    const editVersion = editVersionRef.current
    const responses = compactDrafts(draftsRef.current)

    return saveQueueRef.current.enqueue(
      responses,
      editVersion,
      (payload: LaravelEsrsDatapointResponsesPayload) =>
        updateLaravelEsrsDatapointResponses(
          {
            expected_revision: payload.expected_revision,
            responses: payload.responses,
          },
          { csrfToken },
        ),
    )
  }

  function checkRecovery(charId: number, serverUpdated: string | null, currentServerDrafts: Record<string, DraftResponse>) {
    try {
      const key = localStorageDraftKey(charId)
      const raw = typeof window !== "undefined" ? recoveryStorage.getItem(key) : null
      if (!raw) return
      const parsed = JSON.parse(raw)
      if (!parsed || typeof parsed !== "object" || !parsed.drafts) return
      const localSavedAt = typeof parsed.savedAt === "number" ? parsed.savedAt : 0
      const serverTs = serverUpdated ? Date.parse(serverUpdated) : 0
      if (localSavedAt > serverTs) {
        // local is newer
        setPendingRecoveryDrafts(parsed.drafts)
        setRecoveryIsConflict(false)
        setShowRecoveryPrompt(true)
      } else {
        // stale local, clear
        clearLocalMirror(charId)
      }
    } catch {}
  }

  const acceptRecovery = () => {
    if (pendingRecoveryDrafts) {
      draftsRef.current = pendingRecoveryDrafts
      setDrafts(pendingRecoveryDrafts)
      setIsDirty(true)
      dirtyRef.current = true
      editVersionRef.current = saveQueueRef.current.markEdited()
      scheduleAutoSave()
    }
    setShowRecoveryPrompt(false)
    setPendingRecoveryDrafts(null)
    setRecoveryIsConflict(false)
  }

  const declineRecovery = () => {
    clearLocalMirror(characterizationId)
    setShowRecoveryPrompt(false)
    setPendingRecoveryDrafts(null)
    setRecoveryIsConflict(false)
  }

  const dismissIntro = () => {
    setShowIntro(false)
    try {
      if (typeof window !== "undefined") preferenceStorage.setItem("p9_intro_dismissed", "1")
    } catch {}
  }

  const handleSave = async () => {
    setSaving(true)
    setErrorMessage(null)
    setAutoSaveError(null)
    if (autoSaveTimerRef.current) clearTimeout(autoSaveTimerRef.current)

    try {
      const saved = await enqueueCurrentSave()
      if (saved.discarded) return
      setLastSavedAt(new Date())
      setServerUpdatedAt(saved.response.data.updated_at)
      if (saved.isLatestEdit) {
        setIsDirty(false)
        dirtyRef.current = false
        clearLocalMirror(characterizationId)
        reload()
        router.refresh()
      }
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")

        return
      }

      if (installConflictRecovery(error)) return

      setErrorMessage("La plataforma no ha podido guardar las respuestas de los datos normativos.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="flex-1 space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">Datos normativos ESRS</h1>
          <p className="mt-2 text-muted-foreground">
            Completa los <Term k="datapoint">datos normativos</Term> que piden los <Term k="esrs">ESRS</Term>, agrupados por{" "}
            <Term k="requisito_divulgacion">requisito de divulgación</Term>. La inteligencia artificial no decide los
            datos normativos.
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
          <CardContent className="pt-6 text-sm text-muted-foreground">Cargando datos normativos del paso 5...</CardContent>
        </Card>
      ) : !corpus ? (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <div className="flex items-start gap-3">
              <AlertCircle className="mt-0.5 h-5 w-5 text-amber-600" />
              <div>
                <p className="font-medium text-foreground">No hay materialidad final confirmada</p>
                <p className="mt-1 text-sm text-muted-foreground">
                  Completa el paso 4 para que la plataforma genere el listado de datos normativos aplicable.
                </p>
              </div>
            </div>
            <Button type="button" onClick={() => router.push("/wizard/step-4")}>
              Volver al paso 4
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
                  <p className="text-xs font-medium uppercase text-muted-foreground">Cobertura de datos normativos</p>
                  <p className="mt-1 text-sm text-foreground">
                    {mappingSummary.mappingStatusLabel || "-"} / {mappingSummary.coverageStatusLabel || "-"}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {mappingSummary.mappingGranularityLabel || "-"} · {mappingSummary.currentFilterLabel || "-"}
                  </p>
                </div>
                <div>
                  <p className="text-xs font-medium uppercase text-muted-foreground">Aplicación gradual</p>
                  <p className="mt-1 text-sm text-foreground">{phaseSummary.status || "-"}</p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {phaseSummary.applicablePhaseInCount} datos normativos potencialmente aplicables
                  </p>
                </div>
                <div>
                  <p className="text-xs font-medium uppercase text-muted-foreground">Plan de trabajo</p>
                  <p className="mt-1 text-sm text-foreground">
                    {completionItems.length > 0 ? `${completionItems.length} fases sugeridas` : "-"}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {completionItems[0]?.title || "La plataforma no ha devuelto el plan de completitud."}
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
                        {item.statusLabel || "-"} · {item.datapointCount} datos normativos
                      </p>
                    </div>
                  ))}
                </div>
              ) : null}
            </CardContent>
          </Card>

          {/* F2: dismissible intro explainer (exact copy) */}
          {showIntro ? (
            <Card className="border-blue-200 bg-blue-50/60">
              <CardContent className="pt-6">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="font-semibold text-foreground">Qué es esta lista</p>
                    <p className="mt-1 text-sm text-foreground">
                      Cada fila es un dato concreto que pide el estándar: una cifra o una explicación. La lista sale de los temas que confirmaste en el paso 4 — no la decide la inteligencia artificial. El objetivo de hoy no es responderlo todo: es inventariar qué tienes y qué te falta.
                    </p>
                  </div>
                  <button type="button" onClick={dismissIntro} className="text-muted-foreground hover:text-foreground" aria-label="Cerrar">
                    <X className="h-4 w-4" />
                  </button>
                </div>
              </CardContent>
            </Card>
          ) : null}

          {/* F2: filter bar + honest counts + triage mode toggle */}
          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm text-muted-foreground">{honestLabel}</span>
              <div className="flex gap-2">
                {[
                  { key: "mandatory_only" as const, label: "Solo obligatorios" },
                  { key: "all" as const, label: "Todos" },
                  { key: "phase_in" as const, label: "Aplazables temporalmente" },
                ].map((f) => (
                  <button
                    key={f.key}
                    type="button"
                    onClick={() => setObligationFilter(f.key)}
                    className={`rounded-md border px-3 py-1 text-xs ${obligationFilter === f.key ? "border-primary bg-primary/5 text-primary" : "border-border text-muted-foreground"}`}
                  >
                    {f.label}
                  </button>
                ))}
              </div>
            </div>
            <div className="flex items-center gap-2">
              <span className="text-xs uppercase text-muted-foreground">Modo</span>
              <div className="inline-flex rounded-md border border-border text-sm">
                <button
                  type="button"
                  onClick={() => setViewMode("inventory")}
                  className={`px-3 py-1 ${viewMode === "inventory" ? "bg-foreground text-background" : "text-foreground"}`}
                >
                  Inventario rápido
                </button>
                <button
                  type="button"
                  onClick={() => setViewMode("respond")}
                  className={`px-3 py-1 ${viewMode === "respond" ? "bg-foreground text-background" : "text-foreground"}`}
                >
                  Responder
                </button>
              </div>
            </div>
          </div>

          {/* F2: triage sticky summary (only in inventory) */}
          {viewMode === "inventory" ? (
            <div className="sticky top-1 z-10 rounded-md border border-border bg-card px-3 py-2 text-sm shadow-sm">
              Lo tengo: {triageCounts.have_it} · Buscar: {triageCounts.need_to_find} · No aplica: {triageCounts.not_applicable_candidate} · Sin marcar: {triageCounts.untriaged}
            </div>
          ) : null}

          {/* F2: orphaned banner (exact copy when count>0) */}
          {orphaned && orphaned.count > 0 ? (
            <Card className="border-amber-300 bg-amber-50">
              <CardContent className="pt-6 text-sm text-amber-900">
                <p className="font-medium">
                  {orphaned.count} respuestas corresponden a temas que ya no están en tu alcance. No se han borrado: si vuelves a incluir esos temas, reaparecerán aquí.
                </p>
                <Collapsible>
                  <CollapsibleTrigger className="mt-2 text-xs underline">Ver respuestas huérfanas</CollapsibleTrigger>
                  <CollapsibleContent>
                    <div className="mt-2 space-y-1 text-xs">
                      {Object.entries(orphaned.responses || {}).map(([id, r]: [string, any]) => (
                        <div key={id} className="rounded border border-amber-200 bg-white/60 px-2 py-1">
                          <span className="font-mono">{id}</span>: {r.value || "(sin valor)"} {r.triage ? `· ${(TRIAGE_OPTIONS as Record<string, string>)[r.triage] || r.triage}` : ""}
                        </div>
                      ))}
                    </div>
                  </CollapsibleContent>
                </Collapsible>
              </CardContent>
            </Card>
          ) : null}

          {/* F2: sections by standard (Collapsible) */}
          <div className="space-y-4">
            {grouped.map((group) => {
              const isEsrs2 = group.standard === "ESRS 2"
              const progress = sectionProgressLabel(group)
              return (
                <Collapsible key={group.standard} defaultOpen={isEsrs2}>
                  <CollapsibleTrigger className="flex w-full items-center justify-between rounded-lg border border-border bg-card px-4 py-3 text-left text-sm font-medium hover:bg-accent/40">
                    <span>{progress}</span>
                    {isEsrs2 ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
                  </CollapsibleTrigger>
                  <CollapsibleContent className="space-y-3 pt-3">
                    {group.rows.length === 0 ? (
                      <div className="text-xs text-muted-foreground px-1">Sin datos normativos en este filtro.</div>
                    ) : (
                      group.rows.map((rowLike: any) => {
                        const datapoint: LaravelEsrsDatapoint = rowLike.datapoint || rowLike
                        const draft = drafts[datapoint.id] ?? emptyDraft()
                        const appl = datapointApplicabilitySummary(datapoint)
                        const badge = obligationBadge(datapoint)
                        const phaseLabel = phaseInBadgeLabel(datapoint, lessThan750)
                        const currentTriageLabel = draft.triage ? TRIAGE_OPTIONS[draft.triage] : null

                        if (viewMode === "inventory") {
                          return (
                            <Card key={datapoint.id}>
                              <CardContent className="py-3">
                                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                  <div className="min-w-0">
                                    <div className="text-xs text-muted-foreground">{datapoint.standard || ""} {datapoint.dr || ""}</div>
                                    <div className="font-medium text-foreground text-sm">{datapoint.name}</div>
                                  </div>
                                  <div className="flex flex-wrap items-center gap-2 text-xs">
                                    <span className="rounded border px-2 py-0.5 text-muted-foreground">{badge.label}</span>
                                    {phaseLabel ? <span className="rounded border border-amber-300 bg-amber-50 px-2 py-0.5 text-amber-800">{phaseLabel}</span> : null}
                                    {currentTriageLabel ? <span className="rounded bg-muted px-2 py-0.5">{currentTriageLabel}</span> : null}
                                  </div>
                                </div>
                                <div className="mt-2 flex flex-wrap gap-2">
                                  {(["have_it", "need_to_find", "not_applicable_candidate"] as const).map((t) => (
                                    <button
                                      key={t}
                                      type="button"
                                      onClick={() => updateDraft(datapoint.id, { triage: t })}
                                      className={`rounded-md border px-2 py-1 text-xs ${draft.triage === t ? "border-primary bg-primary/5" : "border-border"}`}
                                    >
                                      {TRIAGE_OPTIONS[t]}
                                    </button>
                                  ))}
                                </div>
                              </CardContent>
                            </Card>
                          )
                        }

                        // respond mode
                        const applicability = appl
                        return (
                          <Card key={datapoint.id}>
                            <CardContent className="space-y-4 pt-6">
                              <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                  <p className="text-xs font-medium uppercase text-muted-foreground">{datapoint.standard || "Dato normativo"}</p>
                                  <h2 className="mt-1 text-base font-semibold text-foreground">{datapoint.name}</h2>
                                  <p className="mt-1 text-sm text-muted-foreground">{datapointSubtitle(datapoint)}</p>
                                  {applicability.reason ? (
                                    <p className="mt-2 text-sm text-foreground">{applicability.reason}</p>
                                  ) : null}
                                  <div className="mt-2 flex flex-wrap gap-2 text-xs text-muted-foreground">
                                    <span className="rounded-md border border-border px-2 py-1">{badge.label}</span>
                                    {phaseLabel ? <span className="rounded-md border border-amber-300 bg-amber-50 px-2 py-1 text-amber-800">{phaseLabel}</span> : null}
                                    {applicability.mappingBasis ? (
                                      <span className="rounded-md border border-border px-2 py-1">
                                        Base: {applicability.mappingBasisLabel || applicability.mappingBasis}
                                      </span>
                                    ) : null}
                                    {applicability.phaseInLessThan750 || applicability.phaseInAllUndertakings ? (
                                      <span className="rounded-md border border-border px-2 py-1">Aplicación gradual</span>
                                    ) : null}
                                  </div>
                                  {applicability.limitations.length > 0 ? (
                                    <p className="mt-2 text-xs text-amber-700">{applicability.limitations.join(" ")}</p>
                                  ) : null}
                                </div>
                                <div className="flex flex-col items-end gap-1 text-xs">
                                  <span className="rounded-md border border-border px-2 py-1 text-muted-foreground">
                                    {responseLabel(draft.status)}
                                  </span>
                                  {currentTriageLabel ? <span className="rounded bg-muted px-2 py-0.5">{currentTriageLabel}</span> : null}
                                </div>
                              </div>

                              <div className="grid gap-4 lg:grid-cols-[180px_1fr_1fr]">
                                <div className="space-y-2">
                                  <Label htmlFor={`${datapoint.id}-status`}>Estado</Label>
                                  <div className="text-[10px] text-muted-foreground mb-1">Marca 'No aplica' cuando el dato no existe en tu actividad (por ejemplo, emisiones de proceso si solo tienes oficina). Si dudas, usa 'Tengo que buscarlo'.</div>
                                  <select
                                    id={`${datapoint.id}-status`}
                                    className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground"
                                    value={draft.status}
                                    onChange={(event) => {
                                      const nextStatus = event.target.value as LaravelEsrsDatapointResponseStatus
                                      updateDraft(datapoint.id, { status: nextStatus })
                                    }}
                                  >
                                    {statusOptions.map((option) => (
                                      <option key={option.value} value={option.value}>
                                        {option.label}
                                      </option>
                                    ))}
                                  </select>
                                  {draft.status === "not_applicable" && !draft.note ? (
                                    <p className="text-xs text-amber-700">Opcional: ¿por qué no aplica? Una frase ayuda a tu auditor.</p>
                                  ) : null}
                                </div>
                                <div className="space-y-2">
                                  <Label htmlFor={`${datapoint.id}-value`}>Valor / respuesta</Label>
                                  <Input
                                    id={`${datapoint.id}-value`}
                                    value={draft.value}
                                    onInput={(event) => updateDraft(datapoint.id, { value: event.currentTarget.value })}
                                  />
                                </div>
                                <div className="space-y-2">
                                  <Label htmlFor={`${datapoint.id}-evidence`}>Evidencia</Label>
                                  <Input
                                    id={`${datapoint.id}-evidence`}
                                    value={draft.evidence_reference}
                                    onInput={(event) => updateDraft(datapoint.id, { evidence_reference: event.currentTarget.value })}
                                  />
                                </div>
                                <div className="space-y-2 lg:col-span-3">
                                  <Label htmlFor={`${datapoint.id}-note`}>Nota</Label>
                                  <Textarea
                                    id={`${datapoint.id}-note`}
                                    value={draft.note}
                                    onInput={(event) => updateDraft(datapoint.id, { note: event.currentTarget.value })}
                                  />
                                </div>
                              </div>
                            </CardContent>
                          </Card>
                        )
                      })
                    )}
                  </CollapsibleContent>
                </Collapsible>
              )
            })}
          </div>

          {/* auto-save indicator + manual save */}
          <div className="flex flex-col items-end gap-2">
            {lastSavedAt ? (
              <div className="text-xs text-muted-foreground">Guardado automáticamente {lastSavedAt.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}</div>
            ) : null}
            {autoSaveError ? (
              <div className="text-xs text-amber-700">{autoSaveError}</div>
            ) : null}
            <Button type="button" onClick={handleSave} disabled={saving}>
              <Save className="h-4 w-4" />
              {saving ? "Guardando..." : "Guardar respuestas"}
            </Button>
          </div>

          {/* recovery prompt (F2) */}
          {showRecoveryPrompt && pendingRecoveryDrafts ? (
            <div className="fixed bottom-4 right-4 z-50 max-w-sm rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 shadow">
              <p>{recoveryIsConflict ? "Las respuestas cambiaron en otra pestaña. ¿Quieres recuperar tus cambios sobre la versión actualizada?" : "Tienes cambios sin guardar de una sesión anterior. ¿Recuperarlos?"}</p>
              <div className="mt-2 flex gap-2">
                <Button size="sm" onClick={acceptRecovery}>Recuperar</Button>
                <Button size="sm" variant="outline" onClick={declineRecovery}>Descartar</Button>
              </div>
            </div>
          ) : null}
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

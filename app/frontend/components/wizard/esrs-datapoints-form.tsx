"use client"

import { useSystemMessage, systemCopy } from "@/lib/i18n/use-system-message"

import { useLocale } from "@/components/locale-provider"
import { ui } from "@/lib/i18n/messages.mjs"

import { useOptionalStorage } from "@/lib/consent-storage"

import { useEffect, useMemo, useRef, useState } from "react"
import { useRouter } from "next/navigation"
import { AlertCircle, ChevronDown, Download, RefreshCw, Save, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { LocalizedDownload } from "@/components/localized-download"
import { Card, CardContent } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Term } from "@/components/wizard/term"
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible"
import {
  LaravelApiError,
  getLaravelEsrsDatapointWorkspace,
  getLaravelSession,
  laravelApiUrl,
  updateLaravelEsrsDatapointResponses,
  type LaravelDatapointReview,
  type LaravelEsrsDatapoint,
  type LaravelEsrsDatapointCorpus,
  type LaravelEsrsDatapointResponseStatus,
  type LaravelEsrsDatapointResponsesPayload,
} from "@/lib/laravel-api"
import {
  validateDatapointWorkspace,
  DEFAULT_OBLIGATION_FILTER,
  triageOptions,
  datapointDisplayName,
  applyObligationFilter,
  compactDrafts,
  datapointFeedbackPacket,
  hydrateDatapointDrafts,
  hasUnansweredDatapointReview,
  mergeDatapointRecovery,
  completionPlanItems,
  createResponseSaveQueue,
  datapointApplicabilitySummary,
  emptyDraft,
  flattenCorpus,
  groupRowsByStandard,
  groupRowsByDisclosureRequirement,
  patchDatapointDrafts,
  honestCountsLabel,
  localStorageDraftKey,
  obligationBadge,
  parseDatapointResponsesConflict,
  phaseInBadgeLabel,
  p9ExportLinks,
  p9MappingSummary,
  phaseInSummary,
  responseLabel,
  triageSummary,
} from "@/lib/esrs-datapoints-state.mjs"

type DatapointRow = {
  blockKey: string
  blockTitle: string
  datapoint: LaravelEsrsDatapoint
}

type DraftResponse = {
  learning_review?: LaravelDatapointReview
  status: LaravelEsrsDatapointResponseStatus
  value: string
  evidence_reference: string
  note: string
  triage?: "have_it" | "need_to_find" | "not_applicable_candidate"
}

type RecoveryContext = Readonly<{ authority: string; characterizationId: number; generation: number }>
type PendingRecovery = {
  drafts: Record<string, DraftResponse>
  originAuthority: string
  context: RecoveryContext
}

function datapointSubtitle(datapoint: LaravelEsrsDatapoint, locale: "es" | "en" = "es"): string {
  return [ui(locale, datapoint.standard ?? ""), datapoint.dr, datapoint.paragraph].filter(Boolean).join(" / ")
}


export function EsrsDatapointsForm() {

  const { locale, changing } = useLocale()
  const tr = (message: string) => ui(locale, message)
  const TRIAGE_OPTIONS = triageOptions(locale) as Record<NonNullable<DraftResponse["triage"]>, string>
  const statusOptions: Array<{ value: LaravelEsrsDatapointResponseStatus; label: string }> = [
  { value: "draft", label: tr("Borrador") },
  { value: "completed", label: tr("Completado") },
  { value: "not_applicable", label: tr("No aplica") },
]

  const recoveryStorage = useOptionalStorage("recovery")
  const preferenceStorage = useOptionalStorage("preferences")
  const router = useRouter()
  const reviewEnglish = locale === "en"
  const [reloadCounter, setReloadCounter] = useState(0)
  const [localeRetryCounter, setLocaleRetryCounter] = useState(0)
  const [localeLoadFailed, setLocaleLoadFailed] = useState(false)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [saving, setSaving] = useState(false)
  const [csrfToken, setCsrfToken] = useState<string>()
  const [corpus, setCorpus] = useState<LaravelEsrsDatapointCorpus | null>(null)
  const [expandedDatapoints, setExpandedDatapoints] = useState<Record<string, boolean>>({})
  const [bulkMessage, setBulkMessage] = useState<{ label: string; count: number; triage: NonNullable<DraftResponse["triage"]> } | null>(null)
  const [drafts, setDrafts] = useState<Record<string, DraftResponse>>({})
  const learningAuthorityRef = useRef<string>("")
  const draftsRef = useRef<Record<string, DraftResponse>>({})
  const [errorMessage, setErrorMessage] = useSystemMessage(null)

  // F2 new state (additive)
  const [characterizationId, setCharacterizationId] = useState<number | null>(null)
  const [serverUpdatedAt, setServerUpdatedAt] = useState<string | null>(null)
  const [orphaned, setOrphaned] = useState<{ count: number; responses: Record<string, any> } | null>(null)
  const [obligationFilter, setObligationFilter] = useState<"mandatory_only" | "all" | "phase_in">(DEFAULT_OBLIGATION_FILTER)
  const [viewMode, setViewMode] = useState<"inventory" | "respond">("respond")
  const [isDirty, setIsDirty] = useState(false)
  const [lastSavedAt, setLastSavedAt] = useState<Date | null>(null)
  const [autoSaveError, setAutoSaveError] = useSystemMessage(null)
  const [showIntro, setShowIntro] = useState(true)
  const [showRecoveryPrompt, setShowRecoveryPrompt] = useState(false)
  const [recoveryIsConflict, setRecoveryIsConflict] = useState(false)
  const [pendingRecoveryDrafts, setPendingRecoveryDrafts] = useState<PendingRecovery | null>(null)
  const workspaceContextRef = useRef<RecoveryContext | null>(null)
  const loadGenerationRef = useRef(0)
  const recoveryTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const autoSaveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const dirtyRef = useRef(false)
  const editVersionRef = useRef(0)
  const saveQueueRef = useRef(createResponseSaveQueue(0))

  useEffect(() => {
    let mounted = true

    // getLaravelEsrsDatapoints + getLaravelEsrsDatapointResponses are superseded by one workspace publication.
    async function loadP9() {
      invalidateWorkspace()
      clearPendingRecovery()
      const generation = loadGenerationRef.current
      setCorpus(null)
      setLoadingInitial(true)
      setErrorMessage(null)

      try {
        const [sessionResponse, workspaceResponse] = await Promise.all([
          getLaravelSession(),
          getLaravelEsrsDatapointWorkspace(),
        ])
        validateDatapointWorkspace(workspaceResponse)

        if (!mounted || generation !== loadGenerationRef.current) {
          return
        }

        setCsrfToken(sessionResponse.data.csrf_token)
        const corpusData = workspaceResponse.data
        const responsesData = workspaceResponse.response_state
        setCorpus(corpusData)
        setCharacterizationId(responsesData?.characterization_id ?? corpusData?.characterization_id ?? null)
        setServerUpdatedAt(responsesData?.updated_at ?? null)
        saveQueueRef.current = createResponseSaveQueue(responsesData?.revision ?? 0)
        editVersionRef.current = 0
        setOrphaned(responsesData?.orphaned ?? null)
        learningAuthorityRef.current = responsesData?.learning_authority_digest ?? ""
        const seededDrafts = hydrateDatapointDrafts(responsesData?.responses ?? {}, responsesData?.learning_feedback)
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
          const context = Object.freeze({ authority: learningAuthorityRef.current, characterizationId: charId, generation })
          workspaceContextRef.current = context
          recoveryTimerRef.current = setTimeout(() => {
            if (!mounted || !isCurrentRecoveryContext(context)) return
            recoveryTimerRef.current = null
            checkRecovery(charId, responsesData?.updated_at ?? null, seededDrafts, corpusData, context)
          }, 0)
        }
      } catch (error) {
        if (!mounted || generation !== loadGenerationRef.current) return
        if (error instanceof LaravelApiError && error.status === 401) {
          router.replace("/login")

          return
        }

        setErrorMessage(systemCopy("No se han podido cargar los datos normativos del paso 5 desde la plataforma."))
      } finally {
        if (mounted && generation === loadGenerationRef.current) {
          setLoadingInitial(false)
        }
      }
    }

    loadP9()

    return () => {
      mounted = false
      invalidateWorkspace()
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

  useEffect(() => {
    if (changing || !corpus || corpus.locale === locale) return
    let active = true
    setLocaleLoadFailed(false)
    const context = workspaceContextRef.current
    const queue = saveQueueRef.current
    getLaravelEsrsDatapointWorkspace().then((response) => {
      if (!active || !context || !isCurrentRecoveryContext(context)) return
      validateDatapointWorkspace(response)
      if (response.data.locale !== locale) throw new Error("Locale context changed")
      if (response.data.characterization_id !== context.characterizationId
        || response.data.learning_authority_digest !== context.authority
        || response.data.mapping_snapshot_digest !== corpus.mapping_snapshot_digest
        || response.response_state.revision !== queue.revision()) {
        reload()
        return
      }
      setCorpus(response.data)
    }).catch(() => {
      if (active) setLocaleLoadFailed(true)
    })
    return () => { active = false }
  }, [locale, changing, corpus?.locale, localeRetryCounter]) // Never reset drafts or the response save queue for a language change.

  const rows = useMemo(() => flattenCorpus(corpus) as DatapointRow[], [corpus])
  const mappingSummary = useMemo(() => p9MappingSummary(corpus, locale), [corpus, locale])
  const phaseSummary = useMemo(() => phaseInSummary(corpus, locale), [corpus, locale])
  const completionItems = useMemo(() => completionPlanItems(corpus, locale), [corpus, locale])

  // F2 computed for sections + filters + triage
  const filteredRows = useMemo(() => {
    const base = rows as any[]
    return applyObligationFilter(base, obligationFilter)
  }, [rows, obligationFilter])
  const grouped = useMemo(() => groupRowsByStandard(filteredRows), [filteredRows])
  const triageCounts = useMemo(() => triageSummary(Object.fromEntries(rows.map(({ datapoint }) => [datapoint.id, drafts[datapoint.id] ?? emptyDraft()]))), [drafts, rows])
  const honestLabel = useMemo(() => {
    const sum = (corpus as any)?.summary || (corpus as any)?.preview?.datapoint_estimate || {}
    return honestCountsLabel({ total_datapoint_count: sum.total_datapoint_count, voluntary_datapoint_count: sum.voluntary_datapoint_count }, locale)
  }, [corpus, locale])
  const lessThan750 = phaseSummary.lessThan750

  const updateDrafts = (datapointIds: string[], patch: Partial<DraftResponse>) => {
    if (!learningAuthorityRef.current || !saveQueueRef.current.isActive()) return
    const next = patchDatapointDrafts(draftsRef.current, datapointIds, patch)
    draftsRef.current = next
    setDrafts(next)
    if (characterizationId != null) {
      try {
        recoveryStorage.setItem(localStorageDraftKey(characterizationId), JSON.stringify({ schema_version: "datapoint-draft-v1", authority_digest: learningAuthorityRef.current, drafts: next, savedAt: Date.now() }))
      } catch {}
    }
    setIsDirty(true)
    dirtyRef.current = true
    editVersionRef.current = saveQueueRef.current.markEdited()
    setErrorMessage(null)
    setAutoSaveError(null)
    scheduleAutoSave()
  }

  const updateDraft = (datapointId: string, patch: Partial<DraftResponse>) => {
    setBulkMessage(null)
    updateDrafts([datapointId], patch)
  }

  const applyGroupTriage = (groupRows: DatapointRow[], triage: NonNullable<DraftResponse["triage"]>, label: string) => {
    updateDrafts(groupRows.map(({ datapoint }) => datapoint.id), { triage })
    setBulkMessage({ label, count: groupRows.length, triage })
  }

  const reload = () => {
    invalidateWorkspace()
    clearPendingRecovery()
    setCorpus(null)
    setLoadingInitial(true)
    setReloadCounter((current) => current + 1)
  }
  const retryLocaleLoad = () => setLocaleRetryCounter((current) => current + 1)

  // --- F2 auto-save + recovery + guard helpers (pure side effects on state) ---
  function invalidateWorkspace() {
    loadGenerationRef.current += 1
    workspaceContextRef.current = null
    learningAuthorityRef.current = ""
    saveQueueRef.current.invalidate()
    if (autoSaveTimerRef.current) clearTimeout(autoSaveTimerRef.current)
    if (recoveryTimerRef.current) clearTimeout(recoveryTimerRef.current)
    autoSaveTimerRef.current = null
    recoveryTimerRef.current = null
  }

  function clearPendingRecovery() {
    setShowRecoveryPrompt(false)
    setPendingRecoveryDrafts(null)
    setRecoveryIsConflict(false)
  }

  function isCurrentRecoveryContext(context: RecoveryContext) {
    const current = workspaceContextRef.current
    return !!current && context.generation === loadGenerationRef.current &&
      context.generation === current.generation && context.characterizationId === current.characterizationId &&
      context.authority === current.authority && context.authority === learningAuthorityRef.current &&
      !!context.authority && saveQueueRef.current.isActive()
  }

  function clearLocalMirror(id: number | null) {
    if (id == null) return
    try {
      recoveryStorage.removeItem(localStorageDraftKey(id))
    } catch {}
  }

  function preserveUnansweredReviewMirror(id: number | null) {
    if (id == null) return
    if (!hasUnansweredDatapointReview(draftsRef.current)) { clearLocalMirror(id); return }
    try {
      recoveryStorage.setItem(localStorageDraftKey(id), JSON.stringify({ schema_version: "datapoint-draft-v1", authority_digest: learningAuthorityRef.current, drafts: draftsRef.current, savedAt: Date.now() }))
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

    // A 409 response alone cannot establish the displayed corpus. Preserve the
    // local mirror and reload the coherent publication before offering recovery.
    if (characterizationId != null) {
      try { recoveryStorage.setItem(localStorageDraftKey(characterizationId), JSON.stringify({
        schema_version: "datapoint-draft-v1", authority_digest: learningAuthorityRef.current,
        drafts: draftsRef.current, savedAt: Date.now(),
      })) } catch {}
    }
    saveQueueRef.current.invalidate()
    learningAuthorityRef.current = ""
    setCorpus(null)
    reload()
    setAutoSaveError(systemCopy("Las respuestas cambiaron en otra pestaña. Recarga la versión actualizada para recuperar tus cambios."))
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
        preserveUnansweredReviewMirror(characterizationId)
      }
      // soft refresh corpus counts if needed (no full reload to avoid flicker)
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")
        return
      }
      if (installConflictRecovery(error)) return
      setAutoSaveError(systemCopy("No se pudo guardar automáticamente. Usa el botón Guardar."))
      // keep dirty so manual save can retry
    }
  }

  function enqueueCurrentSave() {
    if (!learningAuthorityRef.current || !saveQueueRef.current.isActive()) throw new Error("Coherent snapshot required")
    const editVersion = editVersionRef.current
    const learningFeedback = datapointFeedbackPacket(draftsRef.current, learningAuthorityRef.current)
    const responses = compactDrafts(draftsRef.current).map((draft) => {
      const response = { ...draft }
      delete response.learning_review
      return response
    })

    return saveQueueRef.current.enqueue(
      responses,
      editVersion,
      (payload: LaravelEsrsDatapointResponsesPayload) =>
        updateLaravelEsrsDatapointResponses(
          {
            expected_revision: payload.expected_revision,
            responses: payload.responses,
            learning_feedback: learningFeedback,
          },
          { csrfToken },
        ),
    )
  }

  function checkRecovery(charId: number, serverUpdated: string | null, currentServerDrafts: Record<string, DraftResponse>, loadedCorpus: LaravelEsrsDatapointCorpus | null, context: RecoveryContext) {
    if (!isCurrentRecoveryContext(context) || charId !== context.characterizationId || loadedCorpus?.learning_authority_digest !== context.authority) return
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
        setPendingRecoveryDrafts({
          drafts: mergeDatapointRecovery(currentServerDrafts, parsed.drafts, flattenCorpus(loadedCorpus).map((row) => row.datapoint.id), parsed.authority_digest, context.authority),
          originAuthority: typeof parsed.authority_digest === "string" ? parsed.authority_digest : "",
          context,
        })
        setRecoveryIsConflict(false)
        setShowRecoveryPrompt(true)
      } else {
        // stale local, clear
        clearLocalMirror(charId)
      }
    } catch {}
  }

  const acceptRecovery = () => {
    if (!pendingRecoveryDrafts || !isCurrentRecoveryContext(pendingRecoveryDrafts.context)) return
    if (pendingRecoveryDrafts) {
      const recovered = mergeDatapointRecovery(draftsRef.current, pendingRecoveryDrafts.drafts, rows.map(({ datapoint }) => datapoint.id), pendingRecoveryDrafts.originAuthority, pendingRecoveryDrafts.context.authority)
      draftsRef.current = recovered
      setDrafts(recovered)
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
    if (!pendingRecoveryDrafts || !isCurrentRecoveryContext(pendingRecoveryDrafts.context)) return
    clearLocalMirror(pendingRecoveryDrafts.context.characterizationId)
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

  const handleSave = async (continueToReport = false) => {
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
        preserveUnansweredReviewMirror(characterizationId)
        if (continueToReport) {
          router.push("/wizard/step-6")
        } else {
          reload()
          router.refresh()
        }
      }
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")

        return
      }

      if (installConflictRecovery(error)) return

      setErrorMessage(systemCopy("La plataforma no ha podido guardar las respuestas de los datos normativos."))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="min-w-0 flex-1 space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">{tr("Datos normativos NEIS")}</h1>
          <p className="mt-2 text-muted-foreground">{" "}{tr("Completa los")}{" "}<Term k="datapoint">{tr("datos normativos")}</Term>{" "}{tr("que piden los")}{" "}<Term k="esrs">{tr("NEIS")}</Term>{tr(", agrupados por")}{" "}
            <Term k="requisito_divulgacion">{tr("requisito de divulgación")}</Term>{tr(". La inteligencia artificial no decide los datos normativos.")}{" "}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" onClick={reload} disabled={loadingInitial || saving || Boolean(corpus && corpus.locale !== locale)}>
            <RefreshCw className="h-4 w-4" />{" "}{tr("Recargar")}{" "}</Button>
          {corpus
            ? p9ExportLinks(locale).map((exportLink: { key: string; label: string; path: string }) => (
                <LocalizedDownload key={exportLink.key} type="button" variant="outline" href={laravelApiUrl(exportLink.path)}>
                    <Download className="h-4 w-4" />
                    {exportLink.label}
                  </LocalizedDownload>
              ))
            : null}
        </div>
      </div>

      {errorMessage ? (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {tr(errorMessage)}
        </div>
      ) : null}

      {loadingInitial || (corpus && corpus.locale !== locale) ? (
        <Card>
          <CardContent className="space-y-3 pt-6 text-sm text-muted-foreground">
            {localeLoadFailed && corpus && corpus.locale !== locale ? <>
              <p role="alert">{tr("No se han podido cargar los datos normativos del paso 5 desde la plataforma.")}</p>
              <Button onClick={retryLocaleLoad}>{tr("Reintentar")}</Button>
            </> : tr("Cargando datos normativos del paso 5...")}
          </CardContent>
        </Card>
      ) : !corpus ? (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <div className="flex items-start gap-3">
              <AlertCircle className="mt-0.5 h-5 w-5 text-amber-600" />
              <div>
                <p className="font-medium text-foreground">{tr("No hay materialidad final confirmada")}</p>
                <p className="mt-1 text-sm text-muted-foreground">{" "}{tr("Completa el paso 4 para que la plataforma genere el listado de datos normativos aplicable.")}{" "}</p>
              </div>
            </div>
            <Button type="button" onClick={() => router.push("/wizard/step-4")}>{" "}{tr("Volver al paso 4")}{" "}</Button>
          </CardContent>
        </Card>
      ) : (
        <>
          <Card>
            <CardContent className="grid gap-4 pt-6 md:grid-cols-4">
              <SummaryMetric label={tr("Total")} value={corpus.summary.total_datapoint_count} />
              <SummaryMetric label={tr("Siempre requeridos")} value={corpus.summary.always_required_datapoint_count} />
              <SummaryMetric label={tr("Por materialidad")} value={corpus.summary.topical_datapoint_count} />
              <SummaryMetric label={tr("Estándares")} value={corpus.activated_esrs_standards.join(", ") || "-"} />
            </CardContent>
          </Card>

          <Card>
            <CardContent className="space-y-5 pt-6">
              <div className="grid gap-4 lg:grid-cols-3">
                <div>
                  <p className="text-xs font-medium uppercase text-muted-foreground">{tr("Cobertura de datos normativos")}</p>
                  <p className="mt-1 text-sm text-foreground">
                    {mappingSummary.mappingStatusLabel || "-"} / {mappingSummary.coverageStatusLabel || "-"}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {mappingSummary.mappingGranularityLabel || "-"} · {mappingSummary.currentFilterLabel || "-"}
                  </p>
                </div>
                <div>
                  <p className="text-xs font-medium uppercase text-muted-foreground">{tr("Aplicación gradual")}</p>
                  <p className="mt-1 text-sm text-foreground">{phaseSummary.statusLabel}</p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {phaseSummary.applicablePhaseInCount}{" "}{tr("datos normativos potencialmente aplicables")}{" "}</p>
                </div>
                <div>
                  <p className="text-xs font-medium uppercase text-muted-foreground">{tr("Plan de trabajo")}</p>
                  <p className="mt-1 text-sm text-foreground">
                    {completionItems.length > 0 ? `${completionItems.length} ${locale === "es" ? "fases sugeridas" : "suggested stages"}` : "-"}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {completionItems[0]?.title || tr("La plataforma no ha devuelto el plan de completitud.")}
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
                  {completionItems.map((item: { key: string; title: string; statusLabel: string; datapointCount: number }) => (
                    <div key={item.key || item.title} className="rounded-md border border-border px-3 py-2 text-sm">
                      <p className="font-medium text-foreground">{item.title || item.key}</p>
                      <p className="text-xs text-muted-foreground">
                        {item.statusLabel || "-"} · {item.datapointCount}{" "}{tr("datos normativos")}{" "}</p>
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
                    <p className="font-semibold text-foreground">{tr("Qué es esta lista")}</p>
                    <p className="mt-1 text-sm text-foreground">{" "}{tr("Cada fila es un dato concreto que pide el estándar: una cifra o una explicación. La lista sale de los temas que confirmaste en el paso 4 — no la decide la inteligencia artificial. El objetivo de este paso no es responderlo todo: es inventariar qué tienes y qué te falta.")}{" "}</p>
                  </div>
                  <button type="button" onClick={dismissIntro} className="text-muted-foreground hover:text-foreground" aria-label={tr("Cerrar")}>
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
                  { key: "mandatory_only" as const, label: tr("Solo obligatorios") },
                  { key: "all" as const, label: tr("Todos") },
                  { key: "phase_in" as const, label: tr("Aplazables temporalmente") },
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
              <span className="text-xs uppercase text-muted-foreground">{tr("Modo")}</span>
              <div className="inline-flex rounded-md border border-border text-sm">
                <button
                  type="button"
                  onClick={() => { setViewMode("inventory"); setExpandedDatapoints({}) }}
                  className={`px-3 py-1 ${viewMode === "inventory" ? "bg-foreground text-background" : "text-foreground"}`}
                >{" "}{tr("Inventario rápido")}{" "}</button>
                <button
                  type="button"
                  onClick={() => { setViewMode("respond"); setExpandedDatapoints({}) }}
                  className={`px-3 py-1 ${viewMode === "respond" ? "bg-foreground text-background" : "text-foreground"}`}
                >{" "}{tr("Responder")}{" "}</button>
              </div>
            </div>
          </div>

          {/* F2: triage sticky summary (only in inventory) */}
          {viewMode === "inventory" ? (
            <div className="sticky top-1 z-10 rounded-md border border-border bg-card px-3 py-2 text-sm shadow-sm">{" "}{tr("Lo tengo:")}{" "}{triageCounts.have_it}{" "}{tr("· Buscar:")}{" "}{triageCounts.need_to_find}{" "}{tr("· Creo que no aplica:")}{" "}{triageCounts.not_applicable_candidate}{" "}{tr("· Sin marcar:")}{" "}{triageCounts.untriaged}
            </div>
          ) : null}

          {/* F2: orphaned banner (exact copy when count>0) */}
          {orphaned && orphaned.count > 0 ? (
            <Card className="border-amber-300 bg-amber-50">
              <CardContent className="pt-6 text-sm text-amber-900">
                <p className="font-medium">
                  {orphaned.count}{" "}{tr("respuestas corresponden a temas que ya no están en tu alcance. No se han borrado: si vuelves a incluir esos temas, reaparecerán aquí.")}{" "}</p>
                <Collapsible>
                  <CollapsibleTrigger className="mt-2 text-xs underline">{tr("Ver respuestas huérfanas")}</CollapsibleTrigger>
                  <CollapsibleContent>
                    <div className="mt-2 space-y-1 text-xs">
                      {Object.entries(orphaned.responses || {}).map(([id, r]: [string, any]) => (
                        <div key={id} className="rounded border border-amber-200 bg-white/60 px-2 py-1">
                          <span className="font-mono">{id}</span>: {r.value || tr("(sin valor)")} {r.triage ? `· ${(TRIAGE_OPTIONS as Record<string, string>)[r.triage] || r.triage}` : ""}
                        </div>
                      ))}
                    </div>
                  </CollapsibleContent>
                </Collapsible>
              </CardContent>
            </Card>
          ) : null}

          {bulkMessage ? <p role="status" className="rounded-md border border-primary/20 bg-primary/5 px-3 py-2 text-sm">{bulkMessage.label}: {bulkMessage.count} {locale === "es" ? "datos marcados como" : "disclosures marked as"} «{TRIAGE_OPTIONS[bulkMessage.triage]}». {isDirty ? tr("Guardado automático pendiente.") : tr("Cambios guardados.")}</p> : null}
          <div className="space-y-4">
            {grouped.map((group) => {
              const allStandardRows = rows.filter(({ datapoint }) => datapoint.standard?.trim() === group.standard || (!datapoint.standard?.trim() && group.standard === "Otros"))
              const requirements = groupRowsByDisclosureRequirement(group.rows, locale) as Array<{ key: string; label: string; rows: DatapointRow[] }>
              const allRequirements = groupRowsByDisclosureRequirement(allStandardRows, locale) as typeof requirements
              const markedCount = allStandardRows.filter(({ datapoint }) => drafts[datapoint.id]?.triage).length
              return (
                <Collapsible key={group.standard} defaultOpen={group.standard === "ESRS 2"} className="rounded-lg border border-border bg-card">
                  <div className="flex flex-wrap items-center justify-between gap-2 bg-muted/40 p-3">
                    <CollapsibleTrigger className="group flex min-w-0 items-center gap-2 text-left font-semibold">
                      <ChevronDown className="h-4 w-4 shrink-0 transition-transform group-data-[state=open]:rotate-180" />
                      {tr(group.standard)}
                      <span className="text-xs font-normal text-muted-foreground">{markedCount}/{allStandardRows.length}{" "}{tr("inventariados")}</span>
                    </CollapsibleTrigger>
                    <GroupTriageControl locale={locale} label={tr(group.standard)} count={allStandardRows.length} onChange={(triage) => applyGroupTriage(allStandardRows, triage, tr(group.standard))} />
                  </div>
                  <CollapsibleContent className="space-y-3 p-3">
                    <p className="text-xs text-muted-foreground">{tr("Las acciones de grupo incluyen todos sus datos, también los ocultos por el filtro. Solo cambian el inventario; no confirman su aplicabilidad.")}</p>
                    {requirements.map((requirement) => {
                      const allRequirementRows = allRequirements.find((item) => item.key === requirement.key)?.rows ?? requirement.rows
                      const groupLabel = `${tr(group.standard)} · ${requirement.label}`
                      return (
                        <Collapsible key={requirement.key} defaultOpen className="overflow-hidden rounded-md border border-border">
                          <div className="flex flex-wrap items-center justify-between gap-2 bg-muted/30 px-3 py-2">
                            <CollapsibleTrigger className="group flex items-center gap-2 text-left text-sm font-medium">
                              <ChevronDown className="h-4 w-4 transition-transform group-data-[state=open]:rotate-180" />
                              {requirement.label}
                              <span className="text-xs text-muted-foreground">{requirement.rows.length}{" "}{tr("visibles")}</span>
                            </CollapsibleTrigger>
                            <GroupTriageControl locale={locale} label={groupLabel} count={allRequirementRows.length} onChange={(triage) => applyGroupTriage(allRequirementRows, triage, groupLabel)} />
                          </div>
                          <CollapsibleContent>
                            <div className="overflow-x-auto" role="region" aria-label={`${locale === "es" ? "Inventario de" : "Inventory for"} ${groupLabel}`} tabIndex={0}>
                              <div className="min-w-[36rem]">
                                <div className="grid grid-cols-[minmax(16rem,1fr)_19rem] border-b border-border bg-muted/50 text-xs font-medium">
                                  <div className="p-3">{tr("Dato normativo ·")}{" "}{viewMode === "inventory" ? tr("Inventario rápido") : tr("Responder")}</div>
                                  <div className="grid grid-cols-3 items-center border-l border-border text-center">
                                    {Object.values(TRIAGE_OPTIONS).map((label) => <span key={label} className="px-1 py-2">{label}</span>)}
                                  </div>
                                </div>
                                {requirement.rows.map(({ datapoint }) => {
                                  const draft = drafts[datapoint.id] ?? emptyDraft()
                                  const applicability = datapointApplicabilitySummary(datapoint, locale)
                                  const badge = obligationBadge(datapoint, locale)
                                  const phaseLabel = phaseInBadgeLabel(datapoint, lessThan750, locale)
                                  const expanded = expandedDatapoints[datapoint.id] ?? viewMode === "respond"
                                  return (
                                    <Collapsible key={datapoint.id} open={expanded} onOpenChange={(open) => setExpandedDatapoints((current) => ({ ...current, [datapoint.id]: open }))} className="border-b border-border last:border-b-0">
                                      <div className="grid grid-cols-[minmax(16rem,1fr)_19rem]">
                                        <CollapsibleTrigger className="group flex min-w-0 items-start gap-2 p-3 text-left hover:bg-accent/30">
                                          <ChevronDown className="mt-1 h-4 w-4 shrink-0 transition-transform group-data-[state=open]:rotate-180" />
                                          <div className="min-w-0 space-y-1">
                                            <p className="text-xs text-muted-foreground">{datapoint.id} · {datapointSubtitle(datapoint, locale)}</p>
                                            <h3 className="text-sm font-medium text-foreground [overflow-wrap:anywhere]">{datapointDisplayName(datapoint, locale)}</h3>
                                            <p className="text-xs text-muted-foreground">{badge.label} · {responseLabel(draft.status, locale)}</p>
                                          </div>
                                          <span className="sr-only">{expanded ? tr("Colapsar") : tr("Expandir")}{" "}{tr("respuesta")}</span>
                                        </CollapsibleTrigger>
                                        <TriageResponseControl locale={locale} datapointId={datapoint.id} value={draft.triage} onChange={(triage) => updateDraft(datapoint.id, { triage })} />
                                      </div>
                                      <CollapsibleContent className="space-y-3 border-t border-border bg-muted/10 p-3">
                                        {datapoint.display?.locale === locale && datapoint.display.qualification ? <p className="text-xs text-amber-800">{datapoint.display.qualification}</p> : null}
                                        {datapoint.display?.locale === locale ? <p className="text-xs text-muted-foreground">{datapoint.display.data_type}{datapoint.display.conditional_or_alternative ? ` · ${datapoint.display.conditional_or_alternative}` : ""}</p> : null}
                                        {applicability.reason ? <p className="text-sm text-muted-foreground">{applicability.reason}</p> : null}
                                        <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                                          {applicability.mappingBasisLabel ? <span className="rounded-md border border-border px-2 py-1">{tr("Base:")} {applicability.mappingBasisLabel}</span> : null}
                                          {applicability.phaseInLessThan750 || applicability.phaseInAllUndertakings ? <span className="rounded-md border border-border px-2 py-1">{tr("Aplicación gradual")}</span> : null}
                                        </div>
                                        {phaseLabel ? <p className="text-xs text-amber-800">{phaseLabel}</p> : null}
                                        {applicability.limitations.length > 0 ? <p className="text-xs text-amber-800">{applicability.limitations.join(" ")}</p> : null}
                                        <fieldset className="space-y-2">
                                          <legend className="text-sm font-medium">{reviewEnglish ? "Review this disclosure" : "Revisión del dato"}</legend>
                                          <p className="text-xs text-muted-foreground">{reviewEnglish ? "This review does not remove reporting obligations. Answer both questions to record a decision; an unanswered question leaves it unreviewed." : "Esta revisión no elimina las obligaciones del informe. Contesta ambas preguntas para registrar una decisión; si falta una, queda sin revisar."}</p>
                                          {([['relevant', reviewEnglish ? 'Is this relevant to your company?' : '¿Es relevante para tu empresa?'], ['selected_to_answer', reviewEnglish ? 'Do you want to answer this disclosure?' : '¿Quieres contestar este dato?']] as const).map(([field, label]) => (
                                            <div key={field}>
                                              <Label htmlFor={`${datapoint.id}-review-${field}`}>{label}</Label>
                                              <select id={`${datapoint.id}-review-${field}`} className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                value={draft.learning_review?.[field] === undefined ? '' : String(draft.learning_review[field])}
                                                onChange={(event) => updateDraft(datapoint.id, { learning_review: { ...draft.learning_review, [field]: event.target.value === '' ? undefined : event.target.value === 'true' } })}>
                                                <option value="">{reviewEnglish ? "Unanswered" : "Sin responder"}</option><option value="true">{reviewEnglish ? "Yes" : "Sí"}</option><option value="false">{tr("No")}</option>
                                              </select>
                                            </div>
                                          ))}
                                          <Label htmlFor={`${datapoint.id}-review-reason`}>{reviewEnglish ? "Review reason (optional)" : "Motivo de la revisión (opcional)"}</Label>
                                          <select id={`${datapoint.id}-review-reason`} className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                                            value={draft.learning_review?.reason_codes?.[0] ?? ''}
                                            onChange={(event) => updateDraft(datapoint.id, { learning_review: { ...draft.learning_review, reason_codes: event.target.value ? [event.target.value] : [] } })}>
                                            <option value="">{reviewEnglish ? "No reason" : "Sin motivo"}</option><option value="scope">{reviewEnglish ? "Company scope" : "Alcance de la empresa"}</option><option value="external_assessment">{reviewEnglish ? "External assessment" : "Análisis externo"}</option><option value="other">{reviewEnglish ? "Other" : "Otro"}</option>
                                          </select>
                                          <Label htmlFor={`${datapoint.id}-review-note`}>{reviewEnglish ? "Review note (optional)" : "Nota de la revisión (opcional)"}</Label>
                                          <Textarea id={`${datapoint.id}-review-note`} value={draft.learning_review?.note ?? ''} maxLength={2000}
                                            onChange={(event) => updateDraft(datapoint.id, { learning_review: { ...draft.learning_review, note: event.target.value } })} />
                                        </fieldset>
                                        <div className="grid gap-4 lg:grid-cols-[180px_1fr_1fr]">
                                          <div className="space-y-2">
                                            <Label htmlFor={`${datapoint.id}-status`}>{tr("Estado")}</Label>
                                            <div className="text-[10px] text-muted-foreground mb-1">{tr("Marca 'No aplica' cuando el dato no existe en tu actividad (por ejemplo, emisiones de proceso si solo tienes oficina). Si dudas, usa 'Tengo que buscarlo'.")}</div>
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
                                              <p className="text-xs text-amber-700">{tr("Opcional: ¿por qué no aplica? Una frase ayuda a tu auditor.")}</p>
                                            ) : null}
                                          </div>
                                          <div className="space-y-2">
                                            <Label htmlFor={`${datapoint.id}-value`}>{tr("Valor / respuesta")}</Label>
                                            <Input
                                              id={`${datapoint.id}-value`}
                                              value={draft.value}
                                              onInput={(event) => updateDraft(datapoint.id, { value: event.currentTarget.value })}
                                            />
                                          </div>
                                          <div className="space-y-2">
                                            <Label htmlFor={`${datapoint.id}-evidence`}>{tr("Evidencia")}</Label>
                                            <Input
                                              id={`${datapoint.id}-evidence`}
                                              value={draft.evidence_reference}
                                              onInput={(event) => updateDraft(datapoint.id, { evidence_reference: event.currentTarget.value })}
                                            />
                                          </div>
                                          <div className="space-y-2 lg:col-span-3">
                                            <Label htmlFor={`${datapoint.id}-note`}>{tr("Nota")}</Label>
                                            <Textarea
                                              id={`${datapoint.id}-note`}
                                              value={draft.note}
                                              onInput={(event) => updateDraft(datapoint.id, { note: event.currentTarget.value })}
                                            />
                                          </div>
                                        </div>

                                      </CollapsibleContent>
                                    </Collapsible>
                                  )
                                })}
                              </div>
                            </div>
                          </CollapsibleContent>
                        </Collapsible>
                      )
                    })}
                  </CollapsibleContent>
                </Collapsible>
              )
            })}
          </div>

          {/* auto-save indicator + manual save */}
          <div className="flex flex-col items-end gap-2">
            {lastSavedAt ? (
              <div className="text-xs text-muted-foreground">{tr("Guardado automáticamente")}{" "}{lastSavedAt.toLocaleTimeString(locale === "es" ? "es-ES" : "en-GB", { hour: "2-digit", minute: "2-digit" })}</div>
            ) : null}
            {autoSaveError ? (
              <div className="text-xs text-amber-700">{tr(autoSaveError)}</div>
            ) : null}
            <Button type="button" onClick={() => handleSave()} disabled={saving}>
              <Save className="h-4 w-4" />
              {saving ? tr("Guardando...") : tr("Guardar respuestas")}
            </Button>
            <Button type="button" onClick={() => handleSave(true)} disabled={saving}>
              {tr("Guardar y continuar")}
            </Button>
          </div>

          {/* recovery prompt (F2) */}
          {showRecoveryPrompt && pendingRecoveryDrafts ? (
            <div className="fixed bottom-4 right-4 z-50 max-w-sm rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 shadow">
              <p>{recoveryIsConflict ? tr("Las respuestas cambiaron en otra pestaña. ¿Quieres recuperar tus cambios sobre la versión actualizada?") : tr("Tienes cambios sin guardar de una sesión anterior. ¿Recuperarlos?")}</p>
              <div className="mt-2 flex gap-2">
                <Button size="sm" onClick={acceptRecovery}>{tr("Recuperar")}</Button>
                <Button size="sm" variant="outline" onClick={declineRecovery}>{tr("Descartar")}</Button>
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

function GroupTriageControl({ label, count, onChange, locale = "es" }: {
  locale?: "es" | "en"
  label: string
  count: number
  onChange: (triage: NonNullable<DraftResponse["triage"]>) => void
}) {
  const tr = (message: string) => ui(locale, message)
  const TRIAGE_OPTIONS = triageOptions(locale) as Record<NonNullable<DraftResponse["triage"]>, string>
  return (
    <select aria-label={`${locale === "es" ? "Marcar todos los datos de" : "Mark all disclosures in"} ${label} (${count})`} value="" onChange={(event) => onChange(event.target.value as NonNullable<DraftResponse["triage"]>)} className="h-8 max-w-full rounded-md border border-input bg-background px-2 text-xs">
      <option value="" disabled>{tr("Marcar todo el grupo (")}{count})…</option>
      {Object.entries(TRIAGE_OPTIONS).map(([value, text]) => <option key={value} value={value}>{text}</option>)}
    </select>
  )
}

function TriageResponseControl({ datapointId, value, onChange, locale = "es" }: {
  locale?: "es" | "en"
  datapointId: string
  value?: DraftResponse["triage"]
  onChange: (triage: NonNullable<DraftResponse["triage"]>) => void
}) {
  const TRIAGE_OPTIONS = triageOptions(locale) as Record<NonNullable<DraftResponse["triage"]>, string>
  return (
    <div role="radiogroup" aria-label={`${locale === "es" ? "Inventario rápido de" : "Quick inventory for"} ${datapointId}`} className="grid grid-cols-3 border-l border-border">
      {(Object.entries(TRIAGE_OPTIONS) as Array<[NonNullable<DraftResponse["triage"]>, string]>).map(([triage, label]) => (
        <label key={triage} className={`flex cursor-pointer items-center justify-center border-l border-border first:border-l-0 hover:bg-primary/5 ${value === triage ? "bg-primary/10" : ""}`}>
          <input type="radio" name={`triage-${datapointId}`} value={triage} checked={value === triage} onChange={() => onChange(triage)} className="h-4 w-4 accent-primary" />
          <span className="sr-only">{label}</span>
        </label>
      ))}
    </div>
  )
}

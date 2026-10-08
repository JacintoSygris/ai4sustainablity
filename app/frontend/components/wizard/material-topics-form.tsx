"use client"

import { useSystemMessage, systemCopy } from "@/lib/i18n/use-system-message"

import { ui, formatUi } from "@/lib/i18n/messages.mjs"

import { useLocale } from "@/components/locale-provider"
import { topicTitle, topicSubtitle, topicMatches } from "@/lib/materiality-confirmation-state.mjs"

import type React from "react"

import { useEffect, useRef, useState } from "react"
import { useRouter } from "next/navigation"
import { AlertCircle, AlertTriangle, CheckCircle2, ChevronDown, HelpCircle, Loader2, Info, RefreshCw, Search, XCircle } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip"
import { DocumentEvidencePanel } from "@/components/wizard/document-evidence-panel"
import { Term } from "@/components/wizard/term"
import {
  DOCUMENT_EVIDENCE_LOW_CONFIDENCE_THRESHOLD,
  documentEvidenceFromProposal,
  evidenceNeedsDocumentReview,
  flattenTopicEvidence,
  topicDocumentProvenance,
  topicHasNegativeDocumentEvidence,
} from "@/lib/p6-document-evidence.mjs"
import {
  REVIEW_REASON_KEYS,
  actionsFromProposal,
  buildDraftReviewPayload,
  buildReviewPayload,
  missingReviewTopicIds,
  notesFromProposal,
  reasonsFromProposal,
  saveCompletionMatchesEditVersion,
} from "@/lib/materiality-proposal-review.mjs"
import {
  LaravelApiError,
  getLaravelCharacterization,
  getLaravelMaterialityProposal,
  getLaravelSession,
  submitLaravelCharacterization,
  updateLaravelMaterialityProposalReview,
  type LaravelCharacterization,
  type LaravelDocumentEvidence,
  type LaravelMaterialityProposal,
  type LaravelMaterialityProposalReviewPayload,
  type LaravelMaterialityTopic,
  type LaravelTopicAction,
} from "@/lib/laravel-api"

type TopicActions = Record<string, LaravelTopicAction>
type ActionReasons = Record<string, string[]>
type ActionNotes = Record<string, string>

const actionLabels: Record<LaravelTopicAction, string> = {
  accepted: "Aceptar",
  rejected: "Rechazar",
  unsure: "Duda",
}

const actionDescriptions: Record<LaravelTopicAction, string> = {
  accepted: "Mantener en la propuesta del paso 2",
  rejected: "Descartar de la propuesta del paso 2",
  unsure: "Revisar en doble importancia relativa",
}

const PREDICTION_REFRESH_SECONDS = 10

const reasonLabels: Record<string, string> = {
  sector_fit: "Encaje sectorial",
  not_relevant: "No relevante",
  threshold: "No supera el umbral de importancia",
  stakeholder_input: "Aportación de grupos de interés",
  needs_adm: "Revisar en el análisis de doble importancia relativa",
  other: "Otro",
}

function p6StatusLabel(status: string, locale: "es" | "en" = "es"): string {
  const tr = (message: string) => ui(locale, message)

  return (
    {
      draft: tr("Borrador de la encuesta inicial"),
      submitted: tr("Preparando propuesta de temas"),
      processing: tr("Generando propuesta de temas"),
      waiting: tr("Esperando reintento"),
      failed: tr("No se pudo generar la propuesta"),
      timed_out: tr("Tiempo agotado"),
      completed: tr("Propuesta completada"),
    }[status] ?? tr("Estado pendiente de revisión")
  )
}

function p5IsComplete(characterization: LaravelCharacterization | null): boolean {
  if (!characterization) {
    return false
  }

  const companyProfile = characterization.form_data.company_profile ?? {}
  const operations = characterization.form_data.operations ?? {}

  return Boolean(
    characterization.nace_code &&
      companyProfile.company_name &&
      companyProfile.headquarters_country &&
      companyProfile.reporting_year &&
      companyProfile.reporting_scope &&
      companyProfile.num_subsidiaries_countries != null &&
      companyProfile.stock_listed != null &&
      companyProfile.reporting_currency &&
      companyProfile.product_service_type &&
      Array.isArray(operations.regions) &&
      operations.regions.length > 0 &&
      Array.isArray(operations.value_chain) &&
      operations.value_chain.length > 0 &&
      operations.employee_count_range &&
      operations.revenue_range,
  )
}

function buildSubmitPayload(characterization: LaravelCharacterization) {
  return {
    action: "submit" as const,
    step: "review" as const,
    nace_code: characterization.nace_code,
    esrs_topic_ids: characterization.esrs_topic_ids ?? [],
    form_data: characterization.form_data,
  }
}

export function MaterialTopicsForm() {
  const tr = (message: string) => ui(locale, message)

  const { locale } = useLocale()
  const router = useRouter()
  const [reloadCounter, setReloadCounter] = useState(0)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [submittingPrediction, setSubmittingPrediction] = useState(false)
  const [savingReview, setSavingReview] = useState(false)
  const [isInfoModalOpen, setIsInfoModalOpen] = useState(false)
  const [isEditMode, setIsEditMode] = useState(false)
  const [showEditConfirmDialog, setShowEditConfirmDialog] = useState(false)
  const [expandedTopics, setExpandedTopics] = useState<number[]>([])
  const [searchQuery, setSearchQuery] = useState("")
  const [csrfToken, setCsrfToken] = useState<string>()
  const [characterization, setCharacterization] = useState<LaravelCharacterization | null>(null)
  const [proposal, setProposal] = useState<LaravelMaterialityProposal | null>(null)
  const [topicActions, setTopicActions] = useState<TopicActions>({})
  const [actionReasons, setActionReasons] = useState<ActionReasons>({})
  const [actionNotes, setActionNotes] = useState<ActionNotes>({})
  const [errorMessage, setErrorMessage] = useSystemMessage(null)
  const [saveMessage, setSaveMessage] = useSystemMessage(null)
  const reviewEditVersion = useRef(0)

  useEffect(() => {
    let mounted = true

    async function loadP6() {
      setLoadingInitial(true)
      setErrorMessage(null)

      try {
        const [sessionResponse, characterizationResponse, proposalResponse] = await Promise.all([
          getLaravelSession(),
          getLaravelCharacterization(),
          getLaravelMaterialityProposal(),
        ])

        if (!mounted) {
          return
        }

        setCsrfToken(sessionResponse.data.csrf_token)
        setCharacterization(characterizationResponse.data)
        setProposal(proposalResponse.data)

        if (proposalResponse.data) {
          setTopicActions(actionsFromProposal(proposalResponse.data))
          setActionReasons(reasonsFromProposal(proposalResponse.data))
          setActionNotes(notesFromProposal(proposalResponse.data))
          setIsEditMode(proposalResponse.data.review.status !== "reviewed")
        }
      } catch (error) {
        if (error instanceof LaravelApiError && error.status === 401) {
          router.replace("/login")

          return
        }

        setErrorMessage(systemCopy("No se ha podido cargar la propuesta del paso 2 desde la plataforma."))
      } finally {
        if (mounted) {
          setLoadingInitial(false)
        }
      }
    }

    loadP6()

    return () => {
      mounted = false
    }
  }, [reloadCounter, router])

  const filteredTopics = proposal?.proposal_topics.filter((topic) => topicMatches(topic, searchQuery, locale)) ?? []

  const missingTopicIds = proposal ? missingReviewTopicIds(proposal.proposal_topic_ids, topicActions) : []
  const reviewedCount = Math.max((proposal?.proposal_topic_ids.length ?? 0) - missingTopicIds.length, 0)
  const totalTopics = proposal?.proposal_topic_ids.length ?? 0
  const readOnlyMode = proposal?.review.status === "reviewed" && !isEditMode
  const reviewReady = totalTopics > 0 && missingTopicIds.length === 0
  const p5Complete = p5IsComplete(characterization)
  // Feature detection: the platform only sends the document_evidence block when
  // document extraction is enabled. When absent, render nothing document-related.
  const documentEvidence: LaravelDocumentEvidence | null = documentEvidenceFromProposal(proposal)

  const predictionPending = characterization != null && ["submitted", "processing", "waiting"].includes(characterization.status)

  // Poll only while waiting, before any review can be edited. Clean up on navigation.
  useEffect(() => {
    if (!predictionPending || loadingInitial) return
    const timer = setTimeout(() => setReloadCounter((current) => current + 1), PREDICTION_REFRESH_SECONDS * 1000)
    return () => clearTimeout(timer)
  }, [predictionPending, loadingInitial])

  const reload = () => setReloadCounter((current) => current + 1)

  const setTopicAction = (topicId: number, action: LaravelTopicAction) => {
    reviewEditVersion.current += 1
    setTopicActions((current) => ({ ...current, [String(topicId)]: action }))
    setErrorMessage(null)
    setSaveMessage(null)
  }

  const setTopicNote = (topicId: number, note: string) => {
    reviewEditVersion.current += 1
    setActionNotes((current) => ({ ...current, [String(topicId)]: note }))
    setErrorMessage(null)
    setSaveMessage(null)
  }

  const setTopicReason = (topicId: number, reasonKey: string, checked: boolean) => {
    reviewEditVersion.current += 1
    setActionReasons((current) => {
      const topicKey = String(topicId)
      const selectedReasons = new Set(current[topicKey] ?? [])

      if (checked) {
        selectedReasons.add(reasonKey)
      } else {
        selectedReasons.delete(reasonKey)
      }

      const next = { ...current }
      const reasonList = Array.from(selectedReasons)

      if (reasonList.length > 0) {
        next[topicKey] = reasonList
      } else {
        delete next[topicKey]
      }

      return next
    })
    setErrorMessage(null)
    setSaveMessage(null)
  }

  const handleSubmitPrediction = async () => {
    if (!characterization || !p5Complete) {
      setErrorMessage(systemCopy("Completa primero la encuesta inicial antes de generar la propuesta del paso 2."))

      return
    }

    setSubmittingPrediction(true)
    setErrorMessage(null)

    try {
      await submitLaravelCharacterization(buildSubmitPayload(characterization), { csrfToken })
      reload()
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")

        return
      }

      setErrorMessage(systemCopy("La plataforma no ha podido enviar la caracterización a predicción."))
    } finally {
      setSubmittingPrediction(false)
    }
  }

  const handleSaveReview = async (continueAfterSave: boolean) => {
    if (!proposal || totalTopics === 0) {
      return
    }

    let reviewPayloadData
    if (continueAfterSave) {
      const reviewPayload = buildReviewPayload({
        proposalTopicIds: proposal.proposal_topic_ids,
        topicActions,
        actionReasons,
        actionNotes,
      })

      if (!reviewPayload.ok) {
        setErrorMessage(systemCopy("Marca una decisión explícita en {0} tema(s) antes de continuar.",[reviewPayload.missingTopicIds.length]))

        return
      }
      reviewPayloadData = reviewPayload.payload
    } else {
      reviewPayloadData = buildDraftReviewPayload({
        proposalTopicIds: proposal.proposal_topic_ids,
        topicActions,
        actionReasons,
        actionNotes,
      })
      if (Object.keys(reviewPayloadData.topic_actions).length === 0) {
        setErrorMessage(systemCopy("Marca al menos una decisión antes de guardar el borrador."))

        return
      }
    }

    setSavingReview(true)
    setErrorMessage(null)
    setSaveMessage(null)
    const savedEditVersion = reviewEditVersion.current

    try {
      const payload = {
        ...reviewPayloadData,
        expected_revision: proposal.review.revision,
      } as LaravelMaterialityProposalReviewPayload
      await updateLaravelMaterialityProposalReview(
        payload,
        { csrfToken },
      )
      if (!saveCompletionMatchesEditVersion(savedEditVersion, reviewEditVersion.current)) {
        setSaveMessage(systemCopy("Se guardó la versión anterior. Conservamos tus cambios más recientes para el siguiente guardado."))

        return
      }
      if (continueAfterSave) {
        router.push("/wizard/step-3")
        router.refresh()
      } else {
        setSaveMessage(systemCopy("Borrador guardado."))
        reload()
      }
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")

        return
      }

      if (error instanceof LaravelApiError && error.status === 409) {
        setErrorMessage(systemCopy("La revisión ha cambiado en otra pestaña. Actualiza el paso antes de volver a guardar."))

        return
      }

      setErrorMessage(systemCopy("La plataforma no ha podido guardar la revisión del paso 2."))
    } finally {
      setSavingReview(false)
    }
  }

  return (
    <TooltipProvider>
      <div className="min-w-0 flex-1">
        <div className="mb-2 flex items-start justify-between">
          <h1 className="text-2xl font-semibold text-foreground">{tr("Revisión de temas materiales")}</h1>
          <button
            type="button"
            aria-label={tr("Abrir información sobre la revisión de temas materiales")}
            onClick={() => setIsInfoModalOpen(true)}
            className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          >
            <Info className="h-4 w-4" />
            {" "}{tr("Info")}{" "}</button>
        </div>

        <p className="mb-6 text-muted-foreground">
          {" "}{tr("Revisa la propuesta de")}{" "}<Term k="materialidad">{tr("temas materiales")}</Term> {" "}{tr("generada desde la encuesta inicial y deja trazada tu decisión por cada tema.")}{" "}</p>

        {errorMessage ? (
          <div role="alert" className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
            {errorMessage}
          </div>
        ) : null}
        {saveMessage ? (
          <div role="status" className="mb-4 rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            {saveMessage}
          </div>
        ) : null}

        {loadingInitial ? (
          <div className="rounded-lg border border-border p-6 text-sm text-muted-foreground">
            {" "}{tr("Cargando propuesta del paso 2...")}{" "}</div>
        ) : !characterization ? (
          <StatePanel
            icon={<AlertCircle className="h-5 w-5 text-amber-600" />}
            title={tr("No hay encuesta inicial")}
            description={tr("Vuelve al paso 1 para crear la encuesta inicial que alimenta la propuesta del paso 2.")}
            action={
              <Button type="button" onClick={() => router.push("/wizard/step-1")}>
                {" "}{tr("Volver a la encuesta inicial")}{" "}</Button>
            }
          />
        ) : characterization.status !== "completed" ? (
          <StatePanel
            icon={predictionPending ? <Loader2 className="h-5 w-5 animate-spin motion-reduce:animate-none text-primary" aria-hidden="true" /> : <AlertCircle className="h-5 w-5 text-primary" />}
            title={p6StatusLabel(characterization.status, locale)}
            description={
              characterization.status === "draft"
                ? tr("La encuesta inicial está guardada, pero todavía no se ha enviado a predicción.")
                : predictionPending
                  ? formatUi(locale, "Estamos preparando la propuesta de temas materiales. Puede tardar unos minutos. Comprobamos el estado automáticamente cada {0} segundos; también puedes pulsar Actualizar.", [PREDICTION_REFRESH_SECONDS])
                  : tr("La propuesta no se ha completado. Puedes volver a generar la propuesta.")
            }
            progress={predictionPending ? <PredictionProgressIndicator /> : null}
            action={
              <div className="flex flex-wrap gap-2">
                {characterization.status === "draft" || characterization.status === "failed" || characterization.status === "timed_out" ? (
                  <Button type="button" onClick={handleSubmitPrediction} disabled={!p5Complete || submittingPrediction}>
                    {submittingPrediction ? tr("Generando...") : tr("Generar propuesta con inteligencia artificial")}
                  </Button>
                ) : null}
                <Button type="button" variant="outline" onClick={reload}>
                  <RefreshCw className="h-4 w-4" />
                  {" "}{tr("Actualizar")}{" "}</Button>
              </div>
            }
          />
        ) : !proposal || proposal.proposal_topics.length === 0 ? (
          <StatePanel
            icon={<AlertCircle className="h-5 w-5 text-amber-600" />}
            title={tr("Propuesta del paso 2 vacía")}
            description={tr("La plataforma no ha devuelto temas propuestos para revisar.")}
            action={
              <Button type="button" variant="outline" onClick={reload}>
                <RefreshCw className="h-4 w-4" />
                {" "}{tr("Actualizar")}{" "}</Button>
            }
          />
        ) : (
          <>
            {documentEvidence ? (
              <DocumentEvidencePanel
                documentEvidence={documentEvidence}
                csrfToken={csrfToken}
                onDocumentsChanged={reload}
              />
            ) : null}

            {proposal.ai.review_required_prediction_keys.length > 0 ? (
              <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <div className="mb-2 flex items-center gap-2 font-medium">
                  <AlertTriangle className="h-4 w-4" />
                  {" "}{tr("Claves de predicción pendientes de revisión manual")}{" "}</div>
                <div className="flex flex-wrap gap-2">
                  {proposal.ai.review_required_prediction_keys.map((key) => (
                    <Badge key={key} variant="outline" className="border-amber-400 bg-white text-amber-900">
                      {key}
                    </Badge>
                  ))}
                </div>
              </div>
            ) : null}

            <div className="mb-5 flex flex-col gap-3 rounded-lg border border-border p-4 sm:flex-row sm:items-center sm:justify-between">
              <div className="space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant={proposal.source === "ai_prediction" ? "default" : "secondary"}>
                    {proposal.source === "ai_prediction" ? tr("IA") : tr("Temas guardados")}
                  </Badge>
                  <Badge variant="outline">{p6StatusLabel(proposal.status, locale)}</Badge>
                  <span className="text-sm text-muted-foreground">
                    {reviewedCount}/{totalTopics} {" "}{tr("temas con acción")}{" "}</span>
                </div>
                <p className="text-sm text-muted-foreground">{proposal.source === "ai_prediction" ? formatUi(locale, "La IA ha propuesto {0} temas NEIS candidatos.", [totalTopics]) : formatUi(locale, "{0} temas NEIS para revisar.", [totalTopics])}</p>
              </div>
              <Button type="button" variant="outline" onClick={reload}>
                <RefreshCw className="h-4 w-4" />
                {" "}{tr("Actualizar")}{" "}</Button>
            </div>

            <div className="mb-6 flex flex-wrap items-center gap-3">
              <div className="relative max-w-md flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  aria-label={tr("Buscar tema NEIS")}
                  placeholder={tr("Buscar tema NEIS...")}
                  value={searchQuery}
                  onChange={(event) => setSearchQuery(event.target.value)}
                  className="pl-9"
                />
              </div>
              <div className="flex gap-2">
                <Button type="button" variant="outline" size="sm" onClick={() => setExpandedTopics(proposal.proposal_topics.map((topic) => topic.id))}>{tr("Expandir todo")}</Button>
                <Button type="button" variant="ghost" size="sm" onClick={() => setExpandedTopics([])}>{tr("Colapsar todo")}</Button>
              </div>
            </div>

            <div className="space-y-3">
              {filteredTopics.map((topic) => (
                <TopicReviewCard
                  locale={locale}
                  key={topic.id}
                  topic={topic}
                  expanded={expandedTopics.includes(topic.id)}
                  onExpandedChange={(open) => setExpandedTopics((current) => open ? [...current, topic.id] : current.filter((id) => id !== topic.id))}
                  documentEvidence={documentEvidence}
                  action={topicActions[String(topic.id)]}
                  reasons={actionReasons[String(topic.id)] ?? []}
                  note={actionNotes[String(topic.id)] ?? ""}
                  disabled={readOnlyMode || savingReview}
                  onActionChange={(action) => setTopicAction(topic.id, action)}
                  onReasonChange={(reasonKey, checked) => setTopicReason(topic.id, reasonKey, checked)}
                  onNoteChange={(note) => setTopicNote(topic.id, note)}
                />
              ))}
            </div>

            <div className="mt-8 flex justify-end">
              {readOnlyMode ? (
                <div className="flex items-center gap-3">
                  <Badge variant="secondary" className="gap-1">
                    <CheckCircle2 className="h-3 w-3" />
                    {" "}{tr("Revisión guardada")}{" "}</Badge>
                  <Button type="button" variant="outline" onClick={() => setShowEditConfirmDialog(true)}>
                    {" "}{tr("Editar este paso")}{" "}</Button>
                </div>
              ) : (
                <div className="flex flex-col items-end gap-2">
                  {!reviewReady ? (
                    <p className="text-sm text-muted-foreground">{tr("Marca una acción explícita en todos los temas.")}</p>
                  ) : null}
                  <div className="flex flex-wrap justify-end gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      onClick={() => handleSaveReview(false)}
                      disabled={savingReview || reviewedCount === 0}
                    >
                      {" "}{tr("Guardar borrador")}{" "}</Button>
                    <Button type="button" onClick={() => handleSaveReview(true)} disabled={savingReview || !reviewReady}>
                      {savingReview ? tr("Guardando...") : tr("Guardar y continuar")}
                    </Button>
                  </div>
                </div>
              )}
            </div>
          </>
        )}

        <Dialog open={isInfoModalOpen} onOpenChange={setIsInfoModalOpen}>
          <DialogContent className="max-w-lg">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-3">
                <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-sm font-medium text-primary-foreground">
                  2
                </div>
                {" "}{tr("Revisión de temas materiales")}{" "}</DialogTitle>
            </DialogHeader>
            <div className="space-y-4 text-sm text-muted-foreground">
              <p>
                {" "}{tr("La propuesta del paso 2 procede del estado de la encuesta inicial en la plataforma. Si está completada, puedes confirmar tema por tema antes de pasar a la doble importancia relativa.")}{" "}</p>
              <div className="rounded-lg bg-muted/50 p-3">
                <h4 className="mb-1 font-medium text-foreground">{tr("Nota importante")}</h4>
                <p>
                  {" "}{tr("Esta revisión no sustituye a la confirmación final del paso 4; deja una trazabilidad previa para el análisis de doble importancia relativa.")}{" "}</p>
              </div>
            </div>
          </DialogContent>
        </Dialog>

        <Dialog open={showEditConfirmDialog} onOpenChange={setShowEditConfirmDialog}>
          <DialogContent className="max-w-md">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <AlertTriangle className="h-5 w-5 text-amber-500" />
                {" "}{tr("Editar este paso")}{" "}</DialogTitle>
              <DialogDescription>
                {" "}{tr("Cambiar la revisión del paso 2 puede afectar a los pasos posteriores que dependan de esta propuesta.")}{" "}</DialogDescription>
            </DialogHeader>
            <DialogFooter className="flex-row justify-end gap-2 sm:gap-0">
              <Button variant="ghost" onClick={() => setShowEditConfirmDialog(false)}>
                {" "}{tr("Cancelar")}{" "}</Button>
              <Button
                onClick={() => {
                  setShowEditConfirmDialog(false)
                  setIsEditMode(true)
                }}
              >
                {" "}{tr("Editar la revisión")}{" "}</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </TooltipProvider>
  )
}

function StatePanel({
  icon,
  title,
  description,
  progress,
  action,
}: {
  icon: React.ReactNode
  title: string
  description: string
  progress?: React.ReactNode
  action: React.ReactNode
}) {
  return (
    <div role="status" aria-live="polite" className="rounded-lg border border-border p-6">
      <div className="flex items-start gap-3">
        {icon}
        <div className="min-w-0 flex-1 space-y-4">
          <div>
            <h2 className="text-base font-semibold text-foreground">{title}</h2>
            <p className="mt-1 text-sm text-muted-foreground">{description}</p>
          </div>
          {progress}
          {action}
        </div>
      </div>
    </div>
  )
}

function PredictionProgressIndicator() {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  return (
    <div className="space-y-2" aria-label={tr("Seguimiento de la generación de la propuesta de temas")}>
      <div className="h-2 overflow-hidden rounded-full bg-primary/15" role="progressbar" aria-label={tr("Generando propuesta de temas materiales")}>
        <div className="h-full w-1/2 rounded-full bg-primary motion-safe:animate-pulse" />
      </div>
      <p className="text-xs text-muted-foreground">
        {" "}{tr("La página se actualiza automáticamente; no hace falta repetir la acción mientras la barra siga activa.")}{" "}</p>
    </div>
  )
}

type TopicDocumentEvidenceItem = {
  kind: string
  standard: string | null
  documentId: number | null
  page: number | null
  confidence: number | null
  snippet: string
  documentDeleted: boolean
}

function TopicReviewCard({
  locale = "es",
  topic,
  expanded,
  onExpandedChange,
  documentEvidence,
  action,
  reasons,
  note,
  disabled,
  onActionChange,
  onReasonChange,
  onNoteChange,
}: {
  locale?: "es" | "en"
  topic: LaravelMaterialityTopic
  expanded: boolean
  onExpandedChange: (open: boolean) => void
  documentEvidence: LaravelDocumentEvidence | null
  action?: LaravelTopicAction
  reasons: string[]
  note: string
  disabled: boolean
  onActionChange: (action: LaravelTopicAction) => void
  onReasonChange: (reasonKey: string, checked: boolean) => void
  onNoteChange: (note: string) => void
}) {
  const tr = (message: string) => ui(locale, message)

  // Document evidence is reviewer context only: it never drives, suggests, or
  // disables the accept/reject/unsure controls below (ADD-only invariant).
  const provenance = documentEvidence ? topicDocumentProvenance(topic.esrs_code, documentEvidence) : null
  const evidenceItems: TopicDocumentEvidenceItem[] = provenance?.document
    ? flattenTopicEvidence(provenance.entries, documentEvidence)
    : []
  const needsDocumentReview =
    provenance?.document === true &&
    evidenceNeedsDocumentReview(provenance.entries, DOCUMENT_EVIDENCE_LOW_CONFIDENCE_THRESHOLD)
  const hasNegativeEvidence = provenance != null && topicHasNegativeDocumentEvidence(provenance.entries)

  return (
    <Collapsible open={expanded} onOpenChange={onExpandedChange} className="overflow-hidden rounded-lg border border-border bg-card">
      <div className="flex min-w-0 flex-col gap-3 border-l-4 border-primary bg-primary/5 p-3 xl:flex-row xl:items-center xl:justify-between">
        <CollapsibleTrigger className="group flex min-w-0 flex-1 items-start gap-3 text-left">
          <Badge variant="outline" className="mt-0.5 shrink-0 bg-background">{topic.esrs_code}</Badge>
          <div className="min-w-0 flex-1">
            <h3 className="text-base font-semibold text-primary [overflow-wrap:anywhere]">{topicTitle(topic, locale)}</h3>
            <p className="mt-1 text-sm text-muted-foreground [overflow-wrap:anywhere]">{topicSubtitle(topic, locale)}</p>
          </div>
          <ChevronDown className="mt-1 h-4 w-4 shrink-0 text-primary transition-transform group-data-[state=open]:rotate-180" aria-hidden="true" />
          <span className="sr-only">{expanded ? tr("Colapsar") : tr("Expandir")} {" "}{tr("detalles del tema")}</span>
        </CollapsibleTrigger>
        <div role="group" aria-label={formatUi(locale, "Revisar {0}", [topicTitle(topic, locale)])} className="flex shrink-0 flex-nowrap items-center gap-1 self-start">
          {(["accepted", "unsure", "rejected"] as LaravelTopicAction[]).map((candidateAction) => (
            <Tooltip key={candidateAction}>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  size="sm"
                  variant={action === candidateAction ? "default" : "outline"}
                  className="shrink-0 whitespace-nowrap px-2 sm:px-3"
                  aria-pressed={action === candidateAction}
                  disabled={disabled}
                  onClick={() => onActionChange(candidateAction)}
                >
                  {candidateAction === "accepted" ? (
                    <CheckCircle2 className="hidden h-4 w-4 sm:block" />
                  ) : candidateAction === "unsure" ? (
                    <HelpCircle className="hidden h-4 w-4 sm:block" />
                  ) : (
                    <XCircle className="hidden h-4 w-4 sm:block" />
                  )}
                  {tr(actionLabels[candidateAction])}
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>{tr(actionDescriptions[candidateAction])}</p>
              </TooltipContent>
            </Tooltip>
          ))}
        </div>
      </div>

      <CollapsibleContent className="p-4">
        <div className="mb-3 flex flex-wrap gap-2">
          {!action ? <Badge variant="secondary">{tr("Sin revisar")}</Badge> : null}
          {provenance ? <Badge variant="secondary">{tr("Perfil de empresa")}</Badge> : null}
          {provenance?.document ? <Badge variant="secondary">{tr("Documento subido")}</Badge> : null}
          {needsDocumentReview ? <Badge variant="outline" className="border-amber-400 text-amber-900">{tr("Revisar en el documento")}</Badge> : null}
        </div>
        {hasNegativeEvidence ? (
          <p className="text-sm text-muted-foreground">
            {" "}{tr("El documento indica que podría no ser material. Tenlo en cuenta al revisar; la decisión sigue siendo tuya.")}{" "}</p>
        ) : null}
        {evidenceItems.length > 0 ? (
          <details className="rounded-md border border-border px-3 py-2">
            <summary className="cursor-pointer text-sm font-medium text-foreground">
              {" "}{tr("Evidencias del documento (")}{evidenceItems.length})
            </summary>
            <ul className="mt-2 space-y-2">
              {evidenceItems.map((item, index) => (
                <li key={index} className="text-sm text-muted-foreground">
                  {item.documentDeleted ? (
                    <span className="text-amber-700">{tr("Documento eliminado — revisar")}</span>
                  ) : (
                    <>
                      {" "}{tr("«")}{item.snippet}{tr("»")}{" "}{item.page != null ? formatUi(locale, " (página {0})", [item.page]) : ""}
                      {item.confidence != null ? formatUi(locale, " · confianza {0} %", [Math.round(item.confidence * 100)]) : ""}
                    </>
                  )}
                </li>
              ))}
            </ul>
          </details>
        ) : null}
        <div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {REVIEW_REASON_KEYS.map((reasonKey: string) => (
            <label
              key={reasonKey}
              className="flex min-h-9 items-center gap-2 rounded-md border border-border px-3 py-2 text-sm"
            >
              <Checkbox
                checked={reasons.includes(reasonKey)}
                disabled={disabled}
                onCheckedChange={(checked) => onReasonChange(reasonKey, checked === true)}
              />
              <span>{tr(reasonLabels[reasonKey] ?? "Otro")}</span>
            </label>
          ))}
        </div>

        <div className="mt-4">
          <Textarea
            placeholder={tr("Nota de revisión opcional")}
            value={note}
            disabled={disabled}
            onChange={(event) => onNoteChange(event.target.value)}
          />
        </div>
      </CollapsibleContent>
    </Collapsible>
  )
}

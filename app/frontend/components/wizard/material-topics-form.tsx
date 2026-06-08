"use client"

import type React from "react"

import { useEffect, useMemo, useState } from "react"
import { useRouter } from "next/navigation"
import { AlertCircle, AlertTriangle, CheckCircle2, Clock, HelpCircle, Info, RefreshCw, Search, XCircle } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip"
import {
  REVIEW_REASON_KEYS,
  actionsFromProposal,
  buildReviewPayload,
  missingReviewTopicIds,
  notesFromProposal,
  reasonsFromProposal,
} from "@/lib/materiality-proposal-review.mjs"
import {
  LaravelApiError,
  getLaravelCharacterization,
  getLaravelMaterialityProposal,
  getLaravelSession,
  submitLaravelCharacterization,
  updateLaravelMaterialityProposalReview,
  type LaravelCharacterization,
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
  accepted: "Mantener en la propuesta P6",
  rejected: "Descartar de la propuesta P6",
  unsure: "Revisar en doble materialidad",
}

const reasonLabels: Record<string, string> = {
  sector_fit: "Encaje sectorial",
  not_relevant: "No relevante",
  threshold: "Umbral",
  stakeholder_input: "Stakeholders",
  needs_adm: "Revisar en ADM",
  other: "Otro",
}

function p6StatusLabel(status: string): string {
  return (
    {
      draft: "Borrador P5",
      submitted: "Enviado a predicción",
      processing: "Procesando predicción",
      waiting: "Esperando reintento",
      failed: "Predicción fallida",
      timed_out: "Tiempo agotado",
      completed: "Propuesta completada",
    }[status] ?? status
  )
}

function localized(value: { es: string | null; en: string | null } | undefined): string {
  return value?.es || value?.en || ""
}

function topicTitle(topic: LaravelMaterialityTopic): string {
  return localized(topic.subtopic) || localized(topic.subtheme) || localized(topic.theme) || `Tema ${topic.id}`
}

function topicSubtitle(topic: LaravelMaterialityTopic): string {
  return [localized(topic.theme), localized(topic.subtheme)].filter(Boolean).join(" / ")
}

function topicMatches(topic: LaravelMaterialityTopic, query: string): boolean {
  const normalizedQuery = query.trim().toLowerCase()

  if (!normalizedQuery) {
    return true
  }

  return [
    topic.esrs_code,
    topicTitle(topic),
    localized(topic.theme),
    localized(topic.subtheme),
    localized(topic.subtopic),
  ]
    .join(" ")
    .toLowerCase()
    .includes(normalizedQuery)
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
  const router = useRouter()
  const [reloadCounter, setReloadCounter] = useState(0)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [submittingPrediction, setSubmittingPrediction] = useState(false)
  const [savingReview, setSavingReview] = useState(false)
  const [isInfoModalOpen, setIsInfoModalOpen] = useState(false)
  const [isEditMode, setIsEditMode] = useState(false)
  const [showEditConfirmDialog, setShowEditConfirmDialog] = useState(false)
  const [searchQuery, setSearchQuery] = useState("")
  const [csrfToken, setCsrfToken] = useState<string>()
  const [characterization, setCharacterization] = useState<LaravelCharacterization | null>(null)
  const [proposal, setProposal] = useState<LaravelMaterialityProposal | null>(null)
  const [topicActions, setTopicActions] = useState<TopicActions>({})
  const [actionReasons, setActionReasons] = useState<ActionReasons>({})
  const [actionNotes, setActionNotes] = useState<ActionNotes>({})
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

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

        setErrorMessage("No se ha podido cargar la propuesta P6 desde Laravel.")
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

  const filteredTopics = useMemo(() => {
    return proposal?.proposal_topics.filter((topic) => topicMatches(topic, searchQuery)) ?? []
  }, [proposal, searchQuery])

  const missingTopicIds = proposal ? missingReviewTopicIds(proposal.proposal_topic_ids, topicActions) : []
  const reviewedCount = Math.max((proposal?.proposal_topic_ids.length ?? 0) - missingTopicIds.length, 0)
  const totalTopics = proposal?.proposal_topic_ids.length ?? 0
  const readOnlyMode = proposal?.review.status === "reviewed" && !isEditMode
  const reviewReady = totalTopics > 0 && missingTopicIds.length === 0
  const p5Complete = p5IsComplete(characterization)

  const reload = () => setReloadCounter((current) => current + 1)

  const setTopicAction = (topicId: number, action: LaravelTopicAction) => {
    setTopicActions((current) => ({ ...current, [String(topicId)]: action }))
    setErrorMessage(null)
  }

  const setTopicNote = (topicId: number, note: string) => {
    setActionNotes((current) => ({ ...current, [String(topicId)]: note }))
    setErrorMessage(null)
  }

  const setTopicReason = (topicId: number, reasonKey: string, checked: boolean) => {
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
  }

  const handleSubmitPrediction = async () => {
    if (!characterization || !p5Complete) {
      setErrorMessage("Completa primero la caracterización P5 antes de generar la propuesta P6.")

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

      setErrorMessage("Laravel no ha podido enviar la caracterización a predicción.")
    } finally {
      setSubmittingPrediction(false)
    }
  }

  const handleSaveReview = async () => {
    if (!proposal || totalTopics === 0) {
      return
    }

    const reviewPayload = buildReviewPayload({
      proposalTopicIds: proposal.proposal_topic_ids,
      topicActions,
      actionReasons,
      actionNotes,
    })

    if (!reviewPayload.ok) {
      setErrorMessage(`Marca una decisión explícita en ${reviewPayload.missingTopicIds.length} tema(s) antes de continuar.`)

      return
    }

    setSavingReview(true)
    setErrorMessage(null)

    try {
      const payload = reviewPayload.payload as LaravelMaterialityProposalReviewPayload
      await updateLaravelMaterialityProposalReview(
        payload,
        { csrfToken },
      )
      router.push("/wizard/step-3")
      router.refresh()
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")

        return
      }

      setErrorMessage("Laravel no ha podido guardar la revisión P6.")
    } finally {
      setSavingReview(false)
    }
  }

  return (
    <TooltipProvider>
      <div className="flex-1">
        <div className="mb-2 flex items-start justify-between">
          <h1 className="text-2xl font-semibold text-foreground">Revisión de temas materiales</h1>
          <button
            onClick={() => setIsInfoModalOpen(true)}
            className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          >
            <Info className="h-4 w-4" />
            Info
          </button>
        </div>

        <p className="mb-6 text-muted-foreground">
          Revisa la propuesta P6 generada desde la caracterización P5 y deja trazada tu decisión por cada tema.
        </p>

        {errorMessage ? (
          <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
            {errorMessage}
          </div>
        ) : null}

        {loadingInitial ? (
          <div className="rounded-lg border border-border p-6 text-sm text-muted-foreground">Cargando propuesta P6...</div>
        ) : !characterization ? (
          <StatePanel
            icon={<AlertCircle className="h-5 w-5 text-amber-600" />}
            title="No hay caracterización P5"
            description="Vuelve al paso inicial para crear la caracterización que alimenta la propuesta P6."
            action={
              <Button type="button" onClick={() => router.push("/wizard/step-1")}>
                Volver a P5
              </Button>
            }
          />
        ) : characterization.status !== "completed" ? (
          <StatePanel
            icon={<Clock className="h-5 w-5 text-primary" />}
            title={p6StatusLabel(characterization.status)}
            description={
              characterization.status === "draft"
                ? "La caracterización P5 está guardada, pero todavía no se ha enviado a predicción."
                : "La propuesta P6 todavía no está completada en Laravel."
            }
            action={
              <div className="flex flex-wrap gap-2">
                {characterization.status === "draft" || characterization.status === "failed" || characterization.status === "timed_out" ? (
                  <Button type="button" onClick={handleSubmitPrediction} disabled={!p5Complete || submittingPrediction}>
                    {submittingPrediction ? "Generando..." : "Generar propuesta IA"}
                  </Button>
                ) : null}
                <Button type="button" variant="outline" onClick={reload}>
                  <RefreshCw className="h-4 w-4" />
                  Actualizar
                </Button>
              </div>
            }
          />
        ) : !proposal || proposal.proposal_topics.length === 0 ? (
          <StatePanel
            icon={<AlertCircle className="h-5 w-5 text-amber-600" />}
            title="Propuesta P6 vacía"
            description="Laravel no ha devuelto temas propuestos para revisar."
            action={
              <Button type="button" variant="outline" onClick={reload}>
                <RefreshCw className="h-4 w-4" />
                Actualizar
              </Button>
            }
          />
        ) : (
          <>
            {proposal.ai.review_required_prediction_keys.length > 0 ? (
              <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <div className="mb-2 flex items-center gap-2 font-medium">
                  <AlertTriangle className="h-4 w-4" />
                  Claves de predicción pendientes de revisión manual
                </div>
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
                    {proposal.source === "ai_prediction" ? "IA" : "Temas guardados"}
                  </Badge>
                  <Badge variant="outline">{p6StatusLabel(proposal.status)}</Badge>
                  <span className="text-sm text-muted-foreground">
                    {reviewedCount}/{totalTopics} temas con acción
                  </span>
                </div>
                {proposal.ai.summary ? <p className="text-sm text-muted-foreground">{proposal.ai.summary}</p> : null}
              </div>
              <Button type="button" variant="outline" onClick={reload}>
                <RefreshCw className="h-4 w-4" />
                Actualizar
              </Button>
            </div>

            <div className="mb-6 flex items-center gap-4">
              <div className="relative max-w-md flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  placeholder="Buscar tema ESRS..."
                  value={searchQuery}
                  onChange={(event) => setSearchQuery(event.target.value)}
                  className="pl-9"
                />
              </div>
            </div>

            <div className="space-y-3">
              {filteredTopics.map((topic) => (
                <TopicReviewCard
                  key={topic.id}
                  topic={topic}
                  action={topicActions[String(topic.id)]}
                  reasons={actionReasons[String(topic.id)] ?? []}
                  note={actionNotes[String(topic.id)] ?? ""}
                  disabled={readOnlyMode}
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
                    Revisión guardada
                  </Badge>
                  <Button type="button" variant="outline" onClick={() => setShowEditConfirmDialog(true)}>
                    Editar este paso
                  </Button>
                </div>
              ) : (
                <div className="flex flex-col items-end gap-2">
                  {!reviewReady ? (
                    <p className="text-sm text-muted-foreground">Marca una acción explícita en todos los temas.</p>
                  ) : null}
                  <Button type="button" onClick={handleSaveReview} disabled={savingReview || !reviewReady}>
                    {savingReview ? "Guardando..." : "Guardar y continuar"}
                  </Button>
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
                Revisión de temas materiales
              </DialogTitle>
            </DialogHeader>
            <div className="space-y-4 text-sm text-muted-foreground">
              <p>
                La propuesta P6 procede del estado de caracterización en Laravel. Si está completada, puedes confirmar
                tema por tema antes de pasar a la doble materialidad.
              </p>
              <div className="rounded-lg bg-muted/50 p-3">
                <h4 className="mb-1 font-medium text-foreground">Nota importante</h4>
                <p>Esta revisión no sustituye a la confirmación final P8; deja una trazabilidad previa para el ADM.</p>
              </div>
            </div>
          </DialogContent>
        </Dialog>

        <Dialog open={showEditConfirmDialog} onOpenChange={setShowEditConfirmDialog}>
          <DialogContent className="max-w-md">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <AlertTriangle className="h-5 w-5 text-amber-500" />
                Editar este paso
              </DialogTitle>
              <DialogDescription>
                Cambiar la revisión P6 puede afectar a los pasos posteriores que dependan de esta propuesta.
              </DialogDescription>
            </DialogHeader>
            <DialogFooter className="flex-row justify-end gap-2 sm:gap-0">
              <Button variant="ghost" onClick={() => setShowEditConfirmDialog(false)}>
                Cancelar
              </Button>
              <Button
                onClick={() => {
                  setShowEditConfirmDialog(false)
                  setIsEditMode(true)
                }}
              >
                Editar P6
              </Button>
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
  action,
}: {
  icon: React.ReactNode
  title: string
  description: string
  action: React.ReactNode
}) {
  return (
    <div className="rounded-lg border border-border p-6">
      <div className="flex items-start gap-3">
        {icon}
        <div className="flex-1 space-y-4">
          <div>
            <h2 className="text-base font-semibold text-foreground">{title}</h2>
            <p className="mt-1 text-sm text-muted-foreground">{description}</p>
          </div>
          {action}
        </div>
      </div>
    </div>
  )
}

function TopicReviewCard({
  topic,
  action,
  reasons,
  note,
  disabled,
  onActionChange,
  onReasonChange,
  onNoteChange,
}: {
  topic: LaravelMaterialityTopic
  action?: LaravelTopicAction
  reasons: string[]
  note: string
  disabled: boolean
  onActionChange: (action: LaravelTopicAction) => void
  onReasonChange: (reasonKey: string, checked: boolean) => void
  onNoteChange: (note: string) => void
}) {
  return (
    <div className="rounded-lg border border-border p-4">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant="outline">{topic.esrs_code}</Badge>
            {!action ? <Badge variant="secondary">Sin revisar</Badge> : null}
            <h3 className="text-base font-medium text-foreground">{topicTitle(topic)}</h3>
          </div>
          <p className="text-sm text-muted-foreground">{topicSubtitle(topic)}</p>
        </div>

        <div className="flex flex-wrap gap-2">
          {(["accepted", "unsure", "rejected"] as LaravelTopicAction[]).map((candidateAction) => (
            <Tooltip key={candidateAction}>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  size="sm"
                  variant={action === candidateAction ? "default" : "outline"}
                  disabled={disabled}
                  onClick={() => onActionChange(candidateAction)}
                >
                  {candidateAction === "accepted" ? (
                    <CheckCircle2 className="h-4 w-4" />
                  ) : candidateAction === "unsure" ? (
                    <HelpCircle className="h-4 w-4" />
                  ) : (
                    <XCircle className="h-4 w-4" />
                  )}
                  {actionLabels[candidateAction]}
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>{actionDescriptions[candidateAction]}</p>
              </TooltipContent>
            </Tooltip>
          ))}
        </div>
      </div>

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
            <span>{reasonLabels[reasonKey] ?? reasonKey}</span>
          </label>
        ))}
      </div>

      <div className="mt-4">
        <Textarea
          placeholder="Nota de revisión opcional"
          value={note}
          disabled={disabled}
          onChange={(event) => onNoteChange(event.target.value)}
        />
      </div>
    </div>
  )
}

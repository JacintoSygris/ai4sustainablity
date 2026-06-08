"use client"

import type React from "react"

import { useEffect, useMemo, useState } from "react"
import { useRouter } from "next/navigation"
import { AlertCircle, CheckCircle2, Info, RefreshCw, Search } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Checkbox } from "@/components/ui/checkbox"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import {
  LaravelApiError,
  getLaravelEsrsTopics,
  getLaravelMaterialityConfirmation,
  getLaravelSession,
  updateLaravelMaterialityConfirmation,
  type LaravelEsrsTopic,
  type LaravelMaterialityConfirmation,
} from "@/lib/laravel-api"
import {
  CHANGE_REASON_OPTIONS,
  buildMaterialityConfirmationPayload,
  changedTopicIds,
  localized,
  removesE1FromTopics,
  sortTopics,
  topicMatches,
  topicSubtitle,
  topicTitle,
} from "@/lib/materiality-confirmation-state.mjs"

type ChangeNotes = Record<string, string>

type ChangeReasons = Record<string, string[]>

export function FinalTopicsSelection() {
  const router = useRouter()
  const [reloadCounter, setReloadCounter] = useState(0)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [saving, setSaving] = useState(false)
  const [showInfoModal, setShowInfoModal] = useState(false)
  const [searchQuery, setSearchQuery] = useState("")
  const [showOnlySelected, setShowOnlySelected] = useState(false)
  const [csrfToken, setCsrfToken] = useState<string>()
  const [confirmation, setConfirmation] = useState<LaravelMaterialityConfirmation | null>(null)
  const [catalogTopics, setCatalogTopics] = useState<LaravelEsrsTopic[]>([])
  const [selectedTopics, setSelectedTopics] = useState<Set<number>>(new Set())
  const [changeReasons, setChangeReasons] = useState<ChangeReasons>({})
  const [changeNotes, setChangeNotes] = useState<ChangeNotes>({})
  const [e1Explanation, setE1Explanation] = useState("")
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  useEffect(() => {
    let mounted = true

    async function loadP8() {
      setLoadingInitial(true)
      setErrorMessage(null)

      try {
        const [sessionResponse, confirmationResponse, topicsResponse] = await Promise.all([
          getLaravelSession(),
          getLaravelMaterialityConfirmation(),
          getLaravelEsrsTopics({ per_page: 200 }),
        ])

        if (!mounted) {
          return
        }

        setCsrfToken(sessionResponse.data.csrf_token)
        setConfirmation(confirmationResponse.data)
        setCatalogTopics(sortTopics(topicsResponse.data))

        if (confirmationResponse.data) {
          setSelectedTopics(new Set(confirmationResponse.data.confirmed_topic_ids))
          setChangeReasons(confirmationResponse.data.confirmation.change_reasons ?? {})
          setChangeNotes(confirmationResponse.data.confirmation.change_reason_notes ?? {})
          setE1Explanation(confirmationResponse.data.confirmation.e1_not_material_explanation ?? "")
        }
      } catch (error) {
        if (error instanceof LaravelApiError && error.status === 401) {
          router.replace("/login")

          return
        }

        setErrorMessage("No se ha podido cargar la confirmación P8 desde Laravel.")
      } finally {
        if (mounted) {
          setLoadingInitial(false)
        }
      }
    }

    loadP8()

    return () => {
      mounted = false
    }
  }, [reloadCounter, router])

  const p6TopicIds = useMemo(() => new Set(confirmation?.p6_topic_ids ?? []), [confirmation])
  const changedTopicIdList = useMemo(
    () => changedTopicIds(confirmation?.p6_topic_ids ?? [], selectedTopics),
    [confirmation, selectedTopics],
  )
  const changedTopicIdSet = useMemo(() => new Set(changedTopicIdList), [changedTopicIdList])
  const removesE1 = removesE1FromTopics({
    topics: catalogTopics,
    p6TopicIds: confirmation?.p6_topic_ids ?? [],
    selectedTopicIds: selectedTopics,
  })

  const filteredTopics = useMemo(() => {
    return catalogTopics.filter((topic) => {
      const selected = selectedTopics.has(topic.id)

      return topicMatches(topic, searchQuery) && (!showOnlySelected || selected)
    })
  }, [catalogTopics, searchQuery, selectedTopics, showOnlySelected])

  const addedCount = Array.from(selectedTopics).filter((topicId) => !p6TopicIds.has(topicId)).length
  const removedCount = Array.from(p6TopicIds).filter((topicId) => !selectedTopics.has(topicId)).length

  const reload = () => setReloadCounter((current) => current + 1)

  const toggleTopic = (topicId: number) => {
    setSelectedTopics((current) => {
      const next = new Set(current)

      if (next.has(topicId)) {
        next.delete(topicId)
      } else {
        next.add(topicId)
      }

      return next
    })
    setErrorMessage(null)
  }

  const updateNote = (topicId: number, note: string) => {
    setChangeNotes((current) => ({ ...current, [String(topicId)]: note }))
    setErrorMessage(null)
  }

  const toggleReason = (topicId: number, reasonKey: string, checked: boolean) => {
    setChangeReasons((current) => {
      const topicKey = String(topicId)
      const currentReasons = current[topicKey] ?? []
      const nextReasons = checked
        ? Array.from(new Set([...currentReasons, reasonKey]))
        : currentReasons.filter((currentReason) => currentReason !== reasonKey)

      return {
        ...current,
        [topicKey]: nextReasons,
      }
    })
    setErrorMessage(null)
  }

  const handleSave = async () => {
    if (!confirmation) {
      return
    }

    if (removesE1 && e1Explanation.trim() === "") {
      setErrorMessage("Laravel exige una explicación si E1 deja de ser material.")

      return
    }

    setSaving(true)
    setErrorMessage(null)

    try {
      const payload = buildMaterialityConfirmationPayload({
        selectedTopicIds: selectedTopics,
        p6TopicIds: confirmation.p6_topic_ids,
        changeReasons,
        changeNotes,
        e1Explanation,
      })

      await updateLaravelMaterialityConfirmation(payload, { csrfToken })
      router.push("/wizard/step-5")
      router.refresh()
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")

        return
      }

      setErrorMessage("Laravel no ha podido guardar la confirmación P8.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="flex-1 space-y-6">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">Selección final de temas relevantes</h1>
          <p className="mt-2 text-muted-foreground">
            Confirma los temas finales tras la ADM y revisa el impacto previsto sobre P9.
          </p>
        </div>
        <Button variant="ghost" size="sm" onClick={() => setShowInfoModal(true)} className="text-muted-foreground">
          <Info className="mr-1 h-4 w-4" />
          Info
        </Button>
      </div>

      {errorMessage ? (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {errorMessage}
        </div>
      ) : null}

      {loadingInitial ? (
        <Card>
          <CardContent className="pt-6 text-sm text-muted-foreground">Cargando confirmación P8...</CardContent>
        </Card>
      ) : !confirmation ? (
        <StatePanel
          icon={<AlertCircle className="h-5 w-5 text-amber-600" />}
          title="No hay propuesta P6"
          description="Completa P6 antes de confirmar la materialidad final."
          action={
            <Button type="button" onClick={() => router.push("/wizard/step-2")}>
              Volver a P6
            </Button>
          }
        />
      ) : confirmation.p6_topic_ids.length === 0 ? (
        <StatePanel
          icon={<AlertCircle className="h-5 w-5 text-amber-600" />}
          title="P6 no tiene temas propuestos"
          description="Laravel necesita una propuesta P6 completada y no vacía antes de guardar P8."
          action={
            <Button type="button" variant="outline" onClick={reload}>
              <RefreshCw className="h-4 w-4" />
              Actualizar
            </Button>
          }
        />
      ) : (
        <>
          <div className="grid gap-3 md:grid-cols-4">
            <SummaryCard label="P6" value={String(confirmation.p6_topic_ids.length)} />
            <SummaryCard label="Final" value={String(selectedTopics.size)} />
            <SummaryCard label="Añadidos" value={String(addedCount)} />
            <SummaryCard label="Retirados" value={String(removedCount)} />
          </div>

          <Card>
            <CardContent className="space-y-3 pt-6">
              <div className="flex flex-wrap gap-2">
                <Badge variant={confirmation.is_confirmed ? "default" : "secondary"}>
                  {confirmation.is_confirmed ? "Confirmado" : "Default P6"}
                </Badge>
                <Badge variant="outline">{confirmation.preview.activated_esrs_standards.join(", ") || "Sin estándares"}</Badge>
                <Badge variant="outline">{confirmation.preview.effort_level}</Badge>
              </div>
              <p className="text-sm text-muted-foreground">
                Estimación P9: {confirmation.preview.datapoint_estimate.total_datapoint_count} datapoints totales.
              </p>
            </CardContent>
          </Card>

          {removesE1 ? (
            <Card>
              <CardContent className="space-y-2 pt-6">
                <label htmlFor="e1Explanation" className="text-sm font-medium text-foreground">
                  Explicación requerida al retirar E1
                </label>
                <Textarea
                  id="e1Explanation"
                  value={e1Explanation}
                  onChange={(event) => setE1Explanation(event.target.value)}
                  maxLength={280}
                />
              </CardContent>
            </Card>
          ) : null}

          <div className="flex items-center gap-4">
            <div className="relative max-w-md flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Buscar tema ESRS..."
                value={searchQuery}
                onChange={(event) => setSearchQuery(event.target.value)}
                className="pl-10"
              />
            </div>
            <label className="flex items-center gap-2 text-sm">
              <Checkbox checked={showOnlySelected} onCheckedChange={(checked) => setShowOnlySelected(checked === true)} />
              Mostrar solo seleccionados
            </label>
          </div>

          <div className="space-y-3">
            {filteredTopics.map((topic) => {
              const selected = selectedTopics.has(topic.id)
              const wasP6 = p6TopicIds.has(topic.id)
              const changed = changedTopicIdSet.has(topic.id)

              return (
                <div key={topic.id} className="rounded-lg border border-border p-4">
                  <div className="flex items-start gap-3">
                    <Checkbox checked={selected} onCheckedChange={() => toggleTopic(topic.id)} className="mt-1" />
                    <div className="min-w-0 flex-1 space-y-2">
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">{topic.esrs_code}</Badge>
                        {wasP6 ? <Badge variant="secondary">P6</Badge> : null}
                        {selected && !wasP6 ? <Badge>Añadido</Badge> : null}
                        {!selected && wasP6 ? <Badge variant="destructive">Retirado</Badge> : null}
                        {changed ? <Badge variant="outline">Cambio</Badge> : null}
                      </div>
                      <div>
                        <h3 className="text-base font-medium text-foreground">{topicTitle(topic)}</h3>
                        <p className="text-sm text-muted-foreground">{topicSubtitle(topic)}</p>
                      </div>
                      {changed ? (
                        <div className="space-y-3">
                          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {CHANGE_REASON_OPTIONS.map((reason) => (
                              <label
                                key={`${topic.id}-${reason.key}`}
                                className="flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm text-foreground"
                              >
                                <Checkbox
                                  checked={(changeReasons[String(topic.id)] ?? []).includes(reason.key)}
                                  onCheckedChange={(checked) => toggleReason(topic.id, reason.key, checked === true)}
                                />
                                <span>{reason.label}</span>
                              </label>
                            ))}
                          </div>
                          <Textarea
                            placeholder="Nota opcional sobre el cambio"
                            value={changeNotes[String(topic.id)] ?? ""}
                            onChange={(event) => updateNote(topic.id, event.target.value)}
                          />
                        </div>
                      ) : null}
                    </div>
                  </div>
                </div>
              )
            })}
          </div>

          <div className="flex justify-end border-t pt-4">
            <Button onClick={handleSave} disabled={saving}>
              {saving ? "Guardando..." : "Confirmar y continuar"}
            </Button>
          </div>
        </>
      )}

      <Dialog open={showInfoModal} onOpenChange={setShowInfoModal}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-3">
              <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-sm font-medium text-primary-foreground">
                4
              </span>
              Selección final de temas relevantes
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-4 text-sm text-muted-foreground">
            <p>
              P8 registra la decisión final frente a la propuesta P6. Los cambios se guardan como delta y alimentan el
              preview de datapoints P9.
            </p>
            <div className="flex items-center gap-2 text-foreground">
              <CheckCircle2 className="h-4 w-4 text-accent" />
              <span>Guardar confirma la selección final en Laravel.</span>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function SummaryCard({ label, value }: { label: string; value: string }) {
  return (
    <Card>
      <CardContent className="pt-6">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className="mt-1 text-2xl font-semibold text-foreground">{value}</p>
      </CardContent>
    </Card>
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
    <Card>
      <CardContent className="pt-6">
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
      </CardContent>
    </Card>
  )
}

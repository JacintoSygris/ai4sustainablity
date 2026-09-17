"use client"

import type React from "react"

import { useEffect, useMemo, useRef, useState } from "react"
import { useRouter } from "next/navigation"
import { AlertCircle, CheckCircle2, Info, RefreshCw, Search } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Checkbox } from "@/components/ui/checkbox"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Term } from "@/components/wizard/term"
import {
  LaravelApiError,
  getLaravelEsrsTopics,
  getLaravelMaterialityConfirmation,
  getLaravelSession,
  laravelApiUrl,
  previewLaravelMaterialityConfirmation,
  updateLaravelMaterialityConfirmation,
  type LaravelEsrsTopic,
  type LaravelMaterialityConfirmation,
} from "@/lib/laravel-api"
import {
  CHANGE_REASON_OPTIONS,
  buildMaterialityConfirmationPayload,
  changedTopicIds,
  isStaleConfirmation,
  localized,
  removesE1FromTopics,
  sortTopics,
  topicMatches,
  topicSubtitle,
  topicTitle,
} from "@/lib/materiality-confirmation-state.mjs"
import { TopicSignalAssistant } from "@/components/wizard/topic-signal-assistant"

type ChangeNotes = Record<string, string>
type ChangeReasons = Record<string, string[]>
type Dimensions = Record<string, "impact" | "financial" | "both">
type GuidedAnswers = Record<string, any> // validated via build

type Mode = "direct" | "guided"

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

  // F3 two-mode + guided state
  const [mode, setMode] = useState<Mode>("direct")
  const [hasUserChosenMode, setHasUserChosenMode] = useState(false)
  const [guidedDrafts, setGuidedDrafts] = useState<GuidedAnswers>({}) // topicId -> guided answer
  const [inlineAssistantFor, setInlineAssistantFor] = useState<number | null>(null)
  const [previewEstimate, setPreviewEstimate] = useState<LaravelMaterialityConfirmation["preview"] | null>(null)
  const [previewLoading, setPreviewLoading] = useState(false)
  const previewTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  // localStorage key per plan
  const getDraftKey = (charId: number | undefined) => `p8_guided_draft_${charId ?? "unknown"}`

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
        const conf = confirmationResponse.data
        setConfirmation(conf)
        setCatalogTopics(sortTopics(topicsResponse.data))

        if (conf) {
          setSelectedTopics(new Set(conf.confirmed_topic_ids))
          setChangeReasons(conf.confirmation.change_reasons ?? {})
          setChangeNotes(conf.confirmation.change_reason_notes ?? {})
          setE1Explanation(conf.confirmation.e1_not_material_explanation ?? "")

          // seed dimensions/guided from server if present (additive)
          if (conf.confirmation?.dimensions) {
            // not editing here; used for initial if needed
          }
          if (conf.confirmation?.guided_answers) {
            setGuidedDrafts(conf.confirmation.guided_answers)
          }

          // decide initial mode per plan: acta_registered → direct offered first, else guided first
          const admReg = conf.adm?.acta_registered ?? false
          const initialMode: Mode = admReg ? "direct" : "guided"
          setMode(initialMode)
          setHasUserChosenMode(false)

          // load any local guided draft
          try {
            const draftRaw = typeof window !== "undefined" ? window.localStorage.getItem(getDraftKey(conf.characterization_id)) : null
            if (draftRaw) {
              const parsed = JSON.parse(draftRaw)
              if (parsed && typeof parsed === "object") setGuidedDrafts((prev) => ({ ...prev, ...parsed }))
            }
          } catch {}
        }
      } catch (error) {
        if (error instanceof LaravelApiError && error.status === 401) {
          router.replace("/login")

          return
        }

        setErrorMessage("No se ha podido cargar la selección final desde la plataforma.")
      } finally {
        if (mounted) {
          setLoadingInitial(false)
        }
      }
    }

    loadP8()

    return () => {
      mounted = false
      if (previewTimer.current) clearTimeout(previewTimer.current)
    }
  }, [reloadCounter, router])

  // persist guided drafts to localStorage (cleared on confirm)
  useEffect(() => {
    if (!confirmation?.characterization_id) return
    try {
      const key = getDraftKey(confirmation.characterization_id)
      if (Object.keys(guidedDrafts).length > 0) {
        window.localStorage.setItem(key, JSON.stringify(guidedDrafts))
      }
    } catch {}
  }, [guidedDrafts, confirmation?.characterization_id])

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
  const isStale = isStaleConfirmation(confirmation)

  // dimensions derived from guided or direct (simple: both if both high, etc)
  const dimensions: Dimensions = useMemo(() => {
    const out: Dimensions = {}
    const answers = { ... (confirmation?.confirmation?.guided_answers || {}), ...guidedDrafts }
    for (const [tid, ans] of Object.entries(answers)) {
      if (!ans) continue
      const d = (ans as any).suggested_result ? deriveQuickDim(ans) : undefined
      if (d) out[String(tid)] = d
    }
    return out
  }, [confirmation?.confirmation?.guided_answers, guidedDrafts])

  function deriveQuickDim(ans: any): "impact" | "financial" | "both" | undefined {
    const i = ans?.impacto
    const f = ans?.financiero
    const iHigh = i === "medio" || i === "alto"
    const fHigh = f === "medio" || f === "alto"
    if (iHigh && fHigh) return "both"
    if (iHigh) return "impact"
    if (fHigh) return "financial"
    return undefined
  }

  const adm = confirmation?.adm || { acta_registered: false, acta: null }
  const exposicionDefaults = confirmation?.exposicion_defaults || {}
  const decisionBasis = confirmation?.decision_basis || "none"

  // P6 history lookup (lazy tolerant)
  const [p6History, setP6History] = useState<Record<string, { decision: string; note?: string }>>({})
  const loadP6HistoryIfNeeded = async () => {
    if (Object.keys(p6History).length > 0) return
    try {
      // best effort; the proposal endpoint returns review for P6 actions
      const mod = await import("@/lib/laravel-api")
      // dynamic to avoid top import if not present; fallback tolerant
      const resp = await (mod as any).getLaravelMaterialityProposal?.()
      if (resp?.data?.review) {
        const actions = resp.data.review.topic_actions || {}
        const notes = resp.data.review.action_notes || {}
        const map: Record<string, any> = {}
        for (const [k, act] of Object.entries(actions)) {
          map[String(k)] = { decision: String(act), note: (notes as any)[k] }
        }
        setP6History(map)
      }
    } catch {
      // tolerate absence
    }
  }

  // debounced live preview for pinned bar (on selection/guided change)
  const refreshPreview = (candidateIds: number[]) => {
    if (!csrfToken || candidateIds.length === 0) {
      setPreviewEstimate(confirmation?.preview || null)
      return
    }
    if (previewTimer.current) clearTimeout(previewTimer.current)
    setPreviewLoading(true)
    previewTimer.current = setTimeout(async () => {
      try {
        const res = await previewLaravelMaterialityConfirmation({ candidate_topic_ids: candidateIds }, { csrfToken })
        if (res?.data?.preview) setPreviewEstimate(res.data.preview)
      } catch {
        // silent fallback to last server preview
        if (confirmation?.preview) setPreviewEstimate(confirmation.preview)
      } finally {
        setPreviewLoading(false)
      }
    }, 600)
  }

  // derived counts for pinned bar (use live selected)
  const addedCount = Array.from(selectedTopics).filter((topicId) => !p6TopicIds.has(topicId)).length
  const removedCount = Array.from(p6TopicIds).filter((topicId) => !selectedTopics.has(topicId)).length
  const unchangedCount = (confirmation?.p6_topic_ids?.length ?? 0) - removedCount

  const reload = () => setReloadCounter((current) => current + 1)

  // --- direct mode handlers (keep old toggle + delta chips, plus ayudame + add) ---
  const toggleTopic = (topicId: number) => {
    setSelectedTopics((current) => {
      const next = new Set(current)
      if (next.has(topicId)) next.delete(topicId)
      else next.add(topicId)
      return next
    })
    setErrorMessage(null)
    // live preview refresh
    const nextSel = Array.from(selectedTopics.has(topicId) ? new Set([...selectedTopics].filter((x) => x !== topicId)) : new Set([...selectedTopics, topicId]))
    refreshPreview(nextSel)
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
      return { ...current, [topicKey]: nextReasons }
    })
    setErrorMessage(null)
  }

  const openAssistantFor = (topicId: number) => {
    loadP6HistoryIfNeeded()
    setInlineAssistantFor(topicId)
  }

  const applyAssistantResult = (topicId: number, answer: any) => {
    // store guided answer (will feed dimensions + guided_answers on save)
    setGuidedDrafts((d) => ({ ...d, [String(topicId)]: answer }))
    // also toggle selection on if not (conservative for obs/material suggestions)
    setSelectedTopics((cur) => {
      const n = new Set(cur)
      if (answer?.final_result === "material" || answer?.suggested_result === "material") n.add(topicId)
      return n
    })
    setInlineAssistantFor(null)
    setErrorMessage(null)
    // trigger preview with current selection
    refreshPreview(Array.from(selectedTopics))
  }

  // --- guided mode ---
  const p6GuidedTopics = useMemo(() => {
    if (!confirmation) return []
    return catalogTopics.filter((t) => p6TopicIds.has(t.id))
  }, [catalogTopics, confirmation, p6TopicIds])

  const guidedProgress = useMemo(() => {
    const total = p6GuidedTopics.length || 1
    const done = p6GuidedTopics.filter((t) => !!guidedDrafts[String(t.id)]).length
    return { done, total }
  }, [p6GuidedTopics, guidedDrafts])

  const applyGuidedForTopic = (topicId: number, answer: any) => {
    setGuidedDrafts((d) => ({ ...d, [String(topicId)]: answer }))
    // auto select for material suggestions in guided
    if (answer?.final_result === "material") {
      setSelectedTopics((cur) => new Set([...cur, topicId]))
    }
    refreshPreview(Array.from(selectedTopics))
  }

  // "Hay algún otro tema" add from catalog (exposicion default normal for new)
  const [guidedAddOpen, setGuidedAddOpen] = useState(false)
  const [guidedAddQuery, setGuidedAddQuery] = useState("")
  const addTopicFromGuided = (topicId: number) => {
    setSelectedTopics((cur) => new Set([...cur, topicId]))
    // seed a normal exposicion default for added
    // The guided review remains available from the shared review surface.
    setGuidedAddOpen(false)
    setGuidedAddQuery("")
    refreshPreview(Array.from(new Set([...selectedTopics, topicId])))
  }

  // --- shared review surface groups ---
  const materialIds = useMemo(() => Array.from(selectedTopics).filter((id) => {
    const g = guidedDrafts[String(id)] || confirmation?.confirmation?.guided_answers?.[String(id)]
    return !g || g.final_result === "material"
  }), [selectedTopics, guidedDrafts, confirmation])
  const noMaterialIds = useMemo(() => Array.from(selectedTopics).filter((id) => {
    const g = guidedDrafts[String(id)] || confirmation?.confirmation?.guided_answers?.[String(id)]
    return g && g.final_result === "no_material"
  }), [selectedTopics, guidedDrafts, confirmation])
  const obsIds = useMemo(() => Array.from(selectedTopics).filter((id) => {
    const g = guidedDrafts[String(id)] || confirmation?.confirmation?.guided_answers?.[String(id)]
    return g && g.suggested_result === "en_observacion"
  }), [selectedTopics, guidedDrafts, confirmation])

  const toggleObsToNo = (id: number) => {
    // one-tap override: keep revisar true, final no_material
    const prev = guidedDrafts[String(id)] || {}
    const overridden = { ...prev, final_result: "no_material", revisar: true }
    setGuidedDrafts((d) => ({ ...d, [String(id)]: overridden }))
    // still in selected (binary confirmed), but marked
  }

  // --- save (extended payload) ---
  const handleSave = async () => {
    if (!confirmation) return

    if (removesE1 && e1Explanation.trim() === "") {
      setErrorMessage("La plataforma exige una explicación si E1 deja de ser material.")
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
        dimensions,
        guidedAnswers: guidedDrafts,
      })

      await updateLaravelMaterialityConfirmation(payload, { csrfToken })

      // clear guided draft storage on successful confirm
      try { window.localStorage.removeItem(getDraftKey(confirmation.characterization_id)) } catch {}

      router.push("/wizard/step-5")
      router.refresh()
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")
        return
      }
      setErrorMessage("La plataforma no ha podido guardar la selección final.")
    } finally {
      setSaving(false)
    }
  }

  // initial preview seed
  useEffect(() => {
    if (confirmation?.preview && !previewEstimate) setPreviewEstimate(confirmation.preview)
  }, [confirmation?.preview, previewEstimate])

  // refresh preview when selection changes (debounced inside)
  useEffect(() => {
    if (!loadingInitial && confirmation) {
      refreshPreview(Array.from(selectedTopics))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedTopics.size]) // coarse trigger; refreshPreview is debounced

  const filteredTopics = useMemo(() => {
    return catalogTopics.filter((topic) => {
      const selected = selectedTopics.has(topic.id)
      return topicMatches(topic, searchQuery) && (!showOnlySelected || selected)
    })
  }, [catalogTopics, searchQuery, selectedTopics, showOnlySelected])

  const currentPreview = previewEstimate || confirmation?.preview

  // group catalog for "Añadir tema" (Ambiente/Social/Gobernanza by first letter of esrs_code)
  const groupedCatalog = useMemo(() => {
    const groups: Record<string, LaravelEsrsTopic[]> = { Ambiente: [], Social: [], Gobernanza: [], Otros: [] }
    for (const t of catalogTopics) {
      const g = t.esrs_code?.[0] === "E" ? "Ambiente" : t.esrs_code?.[0] === "S" ? "Social" : t.esrs_code?.[0] === "G" ? "Gobernanza" : "Otros"
      groups[g].push(t)
    }
    return groups
  }, [catalogTopics])

  // --- render ---
  const showModeChooser = !hasUserChosenMode && !confirmation?.is_confirmed
  const showAdmBanner = true
  const admRegistered = adm.acta_registered
  const admText = admRegistered && adm.acta
    ? `Registraste tu análisis el ${adm.acta.completed_on || ""} (${adm.acta.method || ""}; participantes: ${adm.acta.participants || ""}). Confirma tus conclusiones.`
    : "No has registrado el análisis en el paso 3. Puedes confirmar igualmente: tu hoja de decisión quedará marcada como 'sin análisis registrado'."

  return (
    <div className="flex-1 space-y-6">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">Selección final de temas relevantes</h1>
          <p className="mt-2 text-muted-foreground">
            Confirma los temas finales tras tu análisis de doble <Term k="materialidad">materialidad</Term> y revisa
            cómo cambia tu lista de datapoints del paso 5.
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
          <CardContent className="pt-6 text-sm text-muted-foreground">Cargando selección final...</CardContent>
        </Card>
      ) : !confirmation ? (
        <StatePanel
          icon={<AlertCircle className="h-5 w-5 text-amber-600" />}
          title="No hay propuesta del paso 2"
          description="Completa el paso 2 antes de confirmar la materialidad final."
          action={<Button type="button" onClick={() => router.push("/wizard/step-2")}>Volver al paso 2</Button>}
        />
      ) : confirmation.p6_topic_ids.length === 0 ? (
        <StatePanel
          icon={<AlertCircle className="h-5 w-5 text-amber-600" />}
          title="El paso 2 no tiene temas propuestos"
          description="La plataforma necesita una propuesta del paso 2 completada y no vacía antes de guardar la selección final."
          action={<Button type="button" variant="outline" onClick={reload}><RefreshCw className="h-4 w-4" /> Actualizar</Button>}
        />
      ) : (
        <>
          {/* 1. Mode chooser (skippable later via tabs) */}
          {showModeChooser ? (
            <div className="grid gap-3 md:grid-cols-2">
              <Card className={`cursor-pointer border ${mode === "direct" ? "border-primary" : ""}`} onClick={() => { setMode("direct"); setHasUserChosenMode(true) }}>
                <CardContent className="pt-6">
                  <div className="font-semibold">Ya tengo mis conclusiones</div>
                  <p className="text-sm text-muted-foreground mt-1">Directo: confirma o ajusta la lista tras tu ADM (o sin acta registrada).</p>
                </CardContent>
              </Card>
              <Card className={`cursor-pointer border ${mode === "guided" ? "border-primary" : ""}`} onClick={() => { setMode("guided"); setHasUserChosenMode(true) }}>
                <CardContent className="pt-6">
                  <div className="font-semibold">Ayúdame a decidir tema por tema</div>
                  <p className="text-sm text-muted-foreground mt-1">Guiado: 4 señales por tema (impacto, financiero, confianza, exposición). Sugerencias solo informan.</p>
                </CardContent>
              </Card>
            </div>
          ) : (
            <div className="flex gap-2">
              <Button variant={mode === "direct" ? "default" : "outline"} size="sm" onClick={() => setMode("direct")}>Directo</Button>
              <Button variant={mode === "guided" ? "default" : "outline"} size="sm" onClick={() => setMode("guided")}>Guiado</Button>
            </div>
          )}

          {/* 2. ADM banner */}
          {showAdmBanner && (
            <Card className={admRegistered ? "" : "border-amber-300 bg-amber-50/50"}>
              <CardContent className="pt-6 text-sm">
                {admRegistered ? admText : <span className="text-amber-700">{admText}</span>}
              </CardContent>
            </Card>
          )}

          {/* F3: stale confirmation banner (exact copy, only when is_stale) */}
          {isStale ? (
            <div className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              Tu confirmación es anterior a tus últimos cambios en la propuesta del paso 2. Revisa la lista y vuelve a confirmar.
            </div>
          ) : null}

          {/* Pinned delta + live estimate */}
          <div className="sticky top-2 z-10">
            <Card>
              <CardContent className="py-2 text-sm flex flex-wrap items-center gap-x-4 gap-y-1">
                <span>{addedCount} añadidos · {removedCount} retirados · {unchangedCount} sin cambios</span>
                <span className="text-muted-foreground">
                  Estimación paso 5: {previewLoading ? "..." : (currentPreview?.datapoint_estimate?.total_datapoint_count ?? "—")} datapoints
                </span>
                <Button variant="outline" size="sm" onClick={() => setMode(mode === "direct" ? "guided" : "direct")}>Cambiar modo</Button>
              </CardContent>
            </Card>
          </div>

          {/* 3. DIRECT mode — delta-first list */}
          {mode === "direct" && (
            <>
              <div className="space-y-4">
                {/* Propuestos */}
                <div>
                  <div className="text-sm font-semibold mb-2">Propuestos (paso 2)</div>
                  {confirmation.p6_topic_ids.map((pid) => {
                    const topic = catalogTopics.find((t) => t.id === pid)
                    if (!topic) return null
                    const selected = selectedTopics.has(pid)
                    const hist = p6History[String(pid)]
                    return (
                      <div key={pid} className="rounded border p-3 mb-2 flex gap-3 items-start">
                        <Checkbox checked={selected} onCheckedChange={() => toggleTopic(pid)} className="mt-1" />
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2">
                            <Badge variant="outline">{topic.esrs_code}</Badge>
                            <span className="font-medium">{topicTitle(topic)}</span>
                            {hist ? <span className="text-xs text-muted-foreground">En el paso 2 lo marcaste '{hist.decision}'{hist.note ? ` — ${hist.note}` : ""}</span> : null}
                          </div>
                          <div className="mt-2">
                            <Button size="sm" variant="outline" onClick={() => openAssistantFor(pid)}>Ayúdame a decidir</Button>
                          </div>
                          {inlineAssistantFor === pid && (
                            <div className="mt-2"><TopicSignalAssistant topic={topic} exposicionDefault={(exposicionDefaults[String(pid)] as any) || "normal"} initialAnswer={guidedDrafts[String(pid)]} onResult={(ans) => applyAssistantResult(pid, ans)} /></div>
                          )}
                          {changedTopicIdSet.has(pid) && (
                            <div className="mt-2 space-y-2">
                              <div className="grid grid-cols-2 md:grid-cols-3 gap-2">
                                {CHANGE_REASON_OPTIONS.map((r) => (
                                  <label key={r.key} className="flex items-center gap-2 text-xs border rounded px-2 py-1">
                                    <Checkbox checked={(changeReasons[String(pid)] || []).includes(r.key)} onCheckedChange={(c) => toggleReason(pid, r.key, !!c)} />
                                    {r.label}
                                  </label>
                                ))}
                              </div>
                              <Textarea placeholder="Nota opcional" value={changeNotes[String(pid)] || ""} onChange={(e) => updateNote(pid, e.target.value)} />
                            </div>
                          )}
                        </div>
                      </div>
                    )
                  })}
                </div>

                {/* Retirados */}
                <div>
                  <div className="text-sm font-semibold mb-2">Retirados (re-check para volver)</div>
                  {Array.from(p6TopicIds).filter((id) => !selectedTopics.has(id)).map((pid) => {
                    const topic = catalogTopics.find((t) => t.id === pid)
                    if (!topic) return null
                    return (
                      <div key={pid} className="rounded border p-3 mb-2 flex gap-3">
                        <Checkbox checked={false} onCheckedChange={() => toggleTopic(pid)} />
                        <div>
                          <Badge variant="destructive">Retirado</Badge> {topicTitle(topic)}
                          <div className="mt-1"><Button size="sm" variant="outline" onClick={() => openAssistantFor(pid)}>Ayúdame a decidir</Button></div>
                          {inlineAssistantFor === pid && <div className="mt-2"><TopicSignalAssistant topic={topic} exposicionDefault={(exposicionDefaults[String(pid)] as any) || "normal"} onResult={(a) => applyAssistantResult(pid, a)} /></div>}
                        </div>
                      </div>
                    )
                  })}
                  {Array.from(p6TopicIds).filter((id) => !selectedTopics.has(id)).length === 0 && <div className="text-xs text-muted-foreground">Nada retirado.</div>}
                </div>

                {/* Nuevos + Añadir */}
                <div>
                  <div className="flex items-center justify-between mb-2">
                    <div className="text-sm font-semibold">Nuevos (añadidos por ti)</div>
                    <Button size="sm" variant="outline" onClick={() => { setSearchQuery(""); /* reuse dialog */ setShowInfoModal(false); /* simple: reuse search area */ }}>Añadir tema</Button>
                  </div>
                  {Array.from(selectedTopics).filter((id) => !p6TopicIds.has(id)).map((id) => {
                    const t = catalogTopics.find((x) => x.id === id)
                    if (!t) return null
                    return <div key={id} className="text-sm border rounded p-2 mb-1 flex justify-between"><span>{t.esrs_code} {topicTitle(t)}</span><Button size="sm" variant="ghost" onClick={() => toggleTopic(id)}>Quitar</Button></div>
                  })}
                </div>
              </div>

              {/* search / add affordance for direct */}
              <div className="flex items-center gap-2">
                <div className="relative flex-1 max-w-md">
                  <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                  <Input placeholder="Buscar y añadir tema del catálogo..." value={searchQuery} onChange={(e) => setSearchQuery(e.target.value)} className="pl-9" />
                </div>
                <Button variant="outline" onClick={() => { /* open simple add from filtered */ if (filteredTopics[0]) toggleTopic(filteredTopics[0].id) }}>Añadir primero filtrado</Button>
              </div>
            </>
          )}

          {/* 5. GUIDED mode */}
          {mode === "guided" && (
            <div className="space-y-4">
              <div className="text-sm">Progreso guiado: {guidedProgress.done} de {guidedProgress.total}</div>
              <div className="space-y-3">
                {p6GuidedTopics.map((topic) => (
                  <div key={topic.id} className="border rounded p-3">
                    <TopicSignalAssistant
                      topic={topic}
                      exposicionDefault={(exposicionDefaults[String(topic.id)] as any) || "normal"}
                      initialAnswer={guidedDrafts[String(topic.id)]}
                      onResult={(ans) => applyGuidedForTopic(topic.id, ans)}
                    />
                  </div>
                ))}
              </div>

              {/* closing question + catalog add for guided */}
              <Card>
                <CardContent className="pt-6 space-y-2">
                  <div className="font-medium">¿Hay algún otro tema que te preocupe?</div>
                  <div className="flex gap-2">
                    <Input placeholder="Buscar en catálogo (exposición = normal para añadidos)" value={guidedAddQuery} onChange={(e) => setGuidedAddQuery(e.target.value)} />
                    <Button variant="outline" onClick={() => setGuidedAddOpen(!guidedAddOpen)}>Buscar</Button>
                  </div>
                  {guidedAddOpen && (
                    <div className="max-h-48 overflow-auto border rounded p-2 text-sm">
                      {Object.entries(groupedCatalog).map(([g, list]) => (
                        <div key={g} className="mb-2">
                          <div className="text-xs uppercase text-muted-foreground">{g}</div>
                          {list.filter((t) => topicMatches(t, guidedAddQuery)).slice(0, 8).map((t) => (
                            <button key={t.id} className="block w-full text-left px-2 py-0.5 hover:bg-muted" onClick={() => addTopicFromGuided(t.id)}>{t.esrs_code} {topicTitle(t)}</button>
                          ))}
                        </div>
                      ))}
                    </div>
                  )}
                </CardContent>
              </Card>
            </div>
          )}

          {/* 6. Shared review surface (always visible for both modes once edits started) */}
          <div className="space-y-3">
            <div>
              <div className="font-semibold text-emerald-700 mb-1">Material</div>
              {materialIds.length === 0 && <div className="text-xs text-muted-foreground">—</div>}
              {materialIds.map((id) => {
                const t = catalogTopics.find((x) => x.id === id)
                const g = guidedDrafts[String(id)]
                return <div key={id} className="text-sm border rounded px-3 py-1 mb-1 flex justify-between"><span>{t ? `${t.esrs_code} ${topicTitle(t)}` : id} {g?.revisar ? <Badge variant="outline">revisar</Badge> : null}</span><Button size="sm" variant="ghost" onClick={() => toggleTopic(id)}>Quitar</Button></div>
              })}
            </div>
            <div>
              <div className="font-semibold text-rose-700 mb-1">No material</div>
              {noMaterialIds.length === 0 && <div className="text-xs text-muted-foreground">—</div>}
              {noMaterialIds.map((id) => {
                const t = catalogTopics.find((x) => x.id === id)
                return <div key={id} className="text-sm border rounded px-3 py-1 mb-1 flex justify-between"><span>{t ? `${t.esrs_code} ${topicTitle(t)}` : id}</span><Button size="sm" variant="ghost" onClick={() => toggleTopic(id)}>Quitar</Button></div>
              })}
            </div>
            <div>
              <div className="font-semibold text-amber-700 mb-1">En observación <Badge variant="outline">revisar el próximo ciclo</Badge></div>
              {obsIds.length === 0 && <div className="text-xs text-muted-foreground">—</div>}
              {obsIds.map((id) => {
                const t = catalogTopics.find((x) => x.id === id)
                return (
                  <div key={id} className="text-sm border rounded px-3 py-1 mb-1 flex justify-between items-center">
                    <span>{t ? `${t.esrs_code} ${topicTitle(t)}` : id} <span className="text-amber-600">(incluido por precaución)</span></span>
                    <div className="flex gap-2">
                      <Button size="sm" variant="outline" onClick={() => toggleObsToNo(id)}>Pasar a No material (mantener revisar)</Button>
                      <Button size="sm" variant="ghost" onClick={() => toggleTopic(id)}>Quitar</Button>
                    </div>
                  </div>
                )
              })}
            </div>
          </div>

          {/* E1 speed bump (2000) + textarea */}
          {removesE1 ? (
            <Card className="border-amber-300">
              <CardContent className="space-y-2 pt-6">
                <div className="text-sm font-medium text-amber-800">La normativa exige una explicación detallada si el cambio climático no es material. La mayoría de empresas lo mantienen como material.</div>
                <Textarea id="e1Explanation" value={e1Explanation} onChange={(e) => setE1Explanation(e.target.value)} maxLength={2000} placeholder="Explicación detallada (hasta 2000 caracteres)" />
              </CardContent>
            </Card>
          ) : null}

          {/* 7. Confirm + decision sheet CTA */}
          <div className="flex items-center justify-between border-t pt-4">
            <div>
              {confirmation.is_confirmed ? (
                <Button variant="outline" onClick={() => window.open(laravelApiUrl("/materiality-confirmation/decision-sheet"), "_blank")}>
                  Descargar hoja de decisión (para tu gestoría o auditoría)
                </Button>
              ) : null}
            </div>
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
              <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-sm font-medium text-primary-foreground">4</span>
              Selección final de temas relevantes
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-4 text-sm text-muted-foreground">
            <p>Este paso registra tu decisión final frente a la propuesta del paso 2. Los cambios quedan trazados y actualizan la estimación de datapoints del paso 5.</p>
            <div className="flex items-center gap-2 text-foreground"><CheckCircle2 className="h-4 w-4 text-accent" /><span>Guardar confirma la selección final en la plataforma.</span></div>
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

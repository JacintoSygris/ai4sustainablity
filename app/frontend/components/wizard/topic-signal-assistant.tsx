"use client"

import { ui , formatUi } from "@/lib/i18n/messages.mjs"
import { useLocale } from "@/components/locale-provider"

import { useId, useMemo, useState } from "react"
import { Textarea } from "@/components/ui/textarea"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import {
  suggestTopicResult,
  buildGuidedAnswer,
  buildGuidedAnswerForUserEdit,
  type IMPACT_LEVELS as _imp,
} from "@/lib/materiality-guided-state.mjs"
import { topicTitle } from "@/lib/materiality-confirmation-state.mjs"

type TopicLike = { id: number; esrs_code: string; [k: string]: any }

type SignalAnswer = {
  impacto: "bajo" | "medio" | "alto" | "no_lo_se"
  financiero: "bajo" | "medio" | "alto" | "no_lo_se"
  confianza: "baja" | "media" | "alta"
  exposicion: "normal" | "fuerte" | "descartada"
}

export type TopicSignalAssistantProps = {
  topic: TopicLike
  exposicionDefault: "normal" | "fuerte"
  initialAnswer?: Partial<SignalAnswer> & { note?: string | null }
  onResult: (answer: ReturnType<typeof buildGuidedAnswer>) => void
}

const IMPACT_LABEL: Record<SignalAnswer["impacto"], string> = {
  bajo: "Bajo/no aplica",
  medio: "Medio",
  alto: "Alto",
  no_lo_se: "No lo sé",
}
const FIN_LABEL: Record<SignalAnswer["financiero"], string> = { ...IMPACT_LABEL }
const CONF_LABEL: Record<SignalAnswer["confianza"], string> = {
  baja: "Baja",
  media: "Media",
  alta: "Alta",
}
const EXP_LABEL: Record<SignalAnswer["exposicion"], string> = {
  normal: "Normal",
  fuerte: "Fuerte",
  descartada: "Descartada por ti",
}

export function TopicSignalAssistant({ topic, exposicionDefault, initialAnswer, onResult }: TopicSignalAssistantProps) {
  const assistantId = useId()
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  const [signals, setSignals] = useState<SignalAnswer>(() => ({
    impacto: (initialAnswer?.impacto as any) || "bajo",
    financiero: (initialAnswer?.financiero as any) || "bajo",
    confianza: (initialAnswer?.confianza as any) || "media",
    exposicion: (initialAnswer?.exposicion as any) || exposicionDefault,
  }))
  const [note, setNote] = useState<string>(initialAnswer?.note || "")

  const suggestion = useMemo(() => suggestTopicResult(signals), [signals])

  const setSig = (k: keyof SignalAnswer, v: string) => {
    const nextSignals = { ...signals, [k]: v as any }
    setSignals(nextSignals)
    const answer = buildGuidedAnswerForUserEdit(nextSignals, note, true)
    if (answer) onResult(answer)
  }

  const setUserNote = (nextNote: string) => {
    setNote(nextNote)
    const answer = buildGuidedAnswerForUserEdit(signals, nextNote, true)
    if (answer) onResult(answer)
  }

  const confirmSignals = () => {
    const answer = buildGuidedAnswerForUserEdit(signals, note, true)
    if (answer) onResult(answer)
  }

  const currentExp = signals.exposicion
  const expTrace = exposicionDefault === "fuerte" && currentExp === "descartada" ? tr("Descartada por ti") : null

  const suggestionText = (() => {
    const label = suggestion.suggested_result === "material" ? tr("Material") : suggestion.suggested_result === "no_material" ? tr("No material") : tr("En observación")
    return formatUi(locale, "Sugerencia: {0} — puedes cambiarla", [label])
  })()

  return (
    <div className="space-y-3 rounded-lg border border-border p-3 text-sm">
      <div className="font-medium text-foreground">{topicTitle(topic, locale)} <Badge variant="outline" className="ml-1 align-middle">{topic.esrs_code}</Badge></div>

      {/* Impacto */}
      <fieldset>
        <legend className="text-xs font-medium text-muted-foreground mb-1">{tr("¿Cuánto puede afectar a personas o medioambiente?")}</legend>
        <div className="flex flex-wrap gap-1">
          {(["bajo", "medio", "alto", "no_lo_se"] as const).map((v) => (
            <label key={v} className="flex cursor-pointer items-center gap-2 rounded border border-border px-3 py-2 has-[:checked]:border-primary has-[:checked]:bg-primary/10">
              <input
                type="radio"
                name={`${assistantId}-impacto`}
                value={v}
                checked={signals.impacto === v}
                onChange={() => setSig("impacto", v)}
                className="accent-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
              />
              {tr(IMPACT_LABEL[v])}
            </label>
          ))}
        </div>
      </fieldset>

      {/* Financiero */}
      <fieldset>
        <legend className="text-xs font-medium text-muted-foreground mb-1">{tr("¿Cuánto puede afectar económicamente a la empresa?")}</legend>
        <div className="flex flex-wrap gap-1">
          {(["bajo", "medio", "alto", "no_lo_se"] as const).map((v) => (
            <label key={v} className="flex cursor-pointer items-center gap-2 rounded border border-border px-3 py-2 has-[:checked]:border-primary has-[:checked]:bg-primary/10">
              <input
                type="radio"
                name={`${assistantId}-financiero`}
                value={v}
                checked={signals.financiero === v}
                onChange={() => setSig("financiero", v)}
                className="accent-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
              />
              {tr(FIN_LABEL[v])}
            </label>
          ))}
        </div>
      </fieldset>

      {/* Confianza (defaults Media) */}
      <fieldset>
        <legend className="text-xs font-medium text-muted-foreground mb-1">{tr("¿Qué seguridad tienes sobre esta decisión?")}</legend>
        <div className="flex flex-wrap gap-1">
          {(["baja", "media", "alta"] as const).map((v) => (
            <label key={v} className="flex cursor-pointer items-center gap-2 rounded border border-border px-3 py-2 has-[:checked]:border-primary has-[:checked]:bg-primary/10">
              <input
                type="radio"
                name={`${assistantId}-confianza`}
                value={v}
                checked={signals.confianza === v}
                onChange={() => setSig("confianza", v)}
                className="accent-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
              />
              {tr(CONF_LABEL[v])}
            </label>
          ))}
        </div>
      </fieldset>

      {/* Exposición (editable, seeded from default, trace when downgraded) */}
      <fieldset>
        <legend className="text-xs font-medium text-muted-foreground mb-1">{tr("Exposición (derivado de tu sector/cadena; editable)")}</legend>
        <div className="flex flex-wrap gap-1">
          {(["normal", "fuerte", "descartada"] as const).map((v) => (
            <label key={v} className="flex cursor-pointer items-center gap-2 rounded border border-border px-3 py-2 has-[:checked]:border-primary has-[:checked]:bg-primary/10">
              <input
                type="radio"
                name={`${assistantId}-exposicion`}
                value={v}
                checked={currentExp === v}
                onChange={() => setSig("exposicion", v)}
                className="accent-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
              />
              {tr(EXP_LABEL[v])}
            </label>
          ))}
        </div>
        {expTrace ? <div className="mt-1 text-[10px] text-amber-600">{expTrace}</div> : null}
      </fieldset>

      {/* Live suggestion */}
      <div role="status" aria-live="polite" aria-atomic="true" className="rounded bg-muted/60 px-2 py-1 text-xs text-foreground">{suggestionText}{suggestion.revisar ? tr(" (revisar)") : ""}</div>

      <Button type="button" variant="outline" onClick={confirmSignals}>
        {tr("Confirmar respuestas actuales")}
      </Button>

      {/* Optional note max 300 */}
      <div>
        <label htmlFor={`${assistantId}-note`} className="mb-1 block text-xs font-medium">{tr("Nota opcional (máx 300)")}</label>
        <Textarea
          id={`${assistantId}-note`}
          placeholder={tr("Nota opcional (máx 300)")}
          value={note}
          maxLength={300}
          onChange={(e) => setUserNote(e.target.value)}
          className="text-xs"
        />
      </div>
    </div>
  )
}

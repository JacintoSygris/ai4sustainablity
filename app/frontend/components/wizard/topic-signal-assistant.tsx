"use client"

import { ui , formatUi } from "@/lib/i18n/messages.mjs"
import { useLocale } from "@/components/locale-provider"

import { useMemo, useState } from "react"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
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
      <div>
        <div className="text-xs font-medium text-muted-foreground mb-1">{tr("¿Cuánto puede afectar a personas o medioambiente?")}</div>
        <div className="flex flex-wrap gap-1">
          {(["bajo", "medio", "alto", "no_lo_se"] as const).map((v) => (
            <Button key={v} type="button" size="sm" variant={signals.impacto === v ? "default" : "outline"} onClick={() => setSig("impacto", v)}>
              {tr(IMPACT_LABEL[v])}
            </Button>
          ))}
        </div>
      </div>

      {/* Financiero */}
      <div>
        <div className="text-xs font-medium text-muted-foreground mb-1">{tr("¿Cuánto puede afectar económicamente a la empresa?")}</div>
        <div className="flex flex-wrap gap-1">
          {(["bajo", "medio", "alto", "no_lo_se"] as const).map((v) => (
            <Button key={v} type="button" size="sm" variant={signals.financiero === v ? "default" : "outline"} onClick={() => setSig("financiero", v)}>
              {tr(FIN_LABEL[v])}
            </Button>
          ))}
        </div>
      </div>

      {/* Confianza (defaults Media) */}
      <div>
        <div className="text-xs font-medium text-muted-foreground mb-1">{tr("¿Qué seguridad tienes sobre esta decisión?")}</div>
        <div className="flex flex-wrap gap-1">
          {(["baja", "media", "alta"] as const).map((v) => (
            <Button key={v} type="button" size="sm" variant={signals.confianza === v ? "default" : "outline"} onClick={() => setSig("confianza", v)}>
              {tr(CONF_LABEL[v])}
            </Button>
          ))}
        </div>
      </div>

      {/* Exposición (editable, seeded from default, trace when downgraded) */}
      <div>
        <div className="text-xs font-medium text-muted-foreground mb-1">{tr("Exposición (derivado de tu sector/cadena; editable)")}</div>
        <div className="flex flex-wrap gap-1">
          {(["normal", "fuerte", "descartada"] as const).map((v) => (
            <Button key={v} type="button" size="sm" variant={currentExp === v ? "default" : "outline"} onClick={() => setSig("exposicion", v)}>
              {tr(EXP_LABEL[v])}
            </Button>
          ))}
        </div>
        {expTrace ? <div className="mt-1 text-[10px] text-amber-600">{expTrace}</div> : null}
      </div>

      {/* Live suggestion */}
      <div className="rounded bg-muted/60 px-2 py-1 text-xs text-foreground">{suggestionText}{suggestion.revisar ? tr(" (revisar)") : ""}</div>

      {/* Optional note max 300 */}
      <div>
        <Textarea
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

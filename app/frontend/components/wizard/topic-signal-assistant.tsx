"use client"

import { useEffect, useMemo, useState } from "react"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import { Badge } from "@/components/ui/badge"
import {
  suggestTopicResult,
  buildGuidedAnswer,
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
  const [signals, setSignals] = useState<SignalAnswer>(() => ({
    impacto: (initialAnswer?.impacto as any) || "bajo",
    financiero: (initialAnswer?.financiero as any) || "bajo",
    confianza: (initialAnswer?.confianza as any) || "media",
    exposicion: (initialAnswer?.exposicion as any) || exposicionDefault,
  }))
  const [note, setNote] = useState<string>(initialAnswer?.note || "")

  const suggestion = useMemo(() => suggestTopicResult(signals), [signals])

  // emit on change (live)
  useEffect(() => {
    const ans = buildGuidedAnswer(signals, suggestion.suggested_result === "en_observacion" ? "material" : suggestion.suggested_result, note)
    // ensure final_result for obs path conservative default
    onResult(ans)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signals, note, suggestion.suggested_result])

  const setSig = (k: keyof SignalAnswer, v: string) => {
    setSignals((s) => ({ ...s, [k]: v as any }))
  }

  const currentExp = signals.exposicion
  const expTrace = exposicionDefault === "fuerte" && currentExp === "descartada" ? "Descartada por ti" : null

  const suggestionText = (() => {
    const label = suggestion.suggested_result === "material" ? "Material" : suggestion.suggested_result === "no_material" ? "No material" : "En observación"
    return `Sugerencia: ${label} — puedes cambiarla`
  })()

  return (
    <div className="space-y-3 rounded-lg border border-border p-3 text-sm">
      <div className="font-medium text-foreground">{topicTitle(topic)} <Badge variant="outline" className="ml-1 align-middle">{topic.esrs_code}</Badge></div>

      {/* Impacto */}
      <div>
        <div className="text-xs font-medium text-muted-foreground mb-1">¿Cuánto puede afectar a personas o medioambiente?</div>
        <div className="flex flex-wrap gap-1">
          {(["bajo", "medio", "alto", "no_lo_se"] as const).map((v) => (
            <Button key={v} type="button" size="sm" variant={signals.impacto === v ? "default" : "outline"} onClick={() => setSig("impacto", v)}>
              {IMPACT_LABEL[v]}
            </Button>
          ))}
        </div>
      </div>

      {/* Financiero */}
      <div>
        <div className="text-xs font-medium text-muted-foreground mb-1">¿Cuánto puede afectar económicamente a la empresa?</div>
        <div className="flex flex-wrap gap-1">
          {(["bajo", "medio", "alto", "no_lo_se"] as const).map((v) => (
            <Button key={v} type="button" size="sm" variant={signals.financiero === v ? "default" : "outline"} onClick={() => setSig("financiero", v)}>
              {FIN_LABEL[v]}
            </Button>
          ))}
        </div>
      </div>

      {/* Confianza (defaults Media) */}
      <div>
        <div className="text-xs font-medium text-muted-foreground mb-1">¿Qué seguridad tienes sobre esta decisión?</div>
        <div className="flex flex-wrap gap-1">
          {(["baja", "media", "alta"] as const).map((v) => (
            <Button key={v} type="button" size="sm" variant={signals.confianza === v ? "default" : "outline"} onClick={() => setSig("confianza", v)}>
              {CONF_LABEL[v]}
            </Button>
          ))}
        </div>
      </div>

      {/* Exposición (editable, seeded from default, trace when downgraded) */}
      <div>
        <div className="text-xs font-medium text-muted-foreground mb-1">Exposición (derivado de tu sector/cadena; editable)</div>
        <div className="flex flex-wrap gap-1">
          {(["normal", "fuerte", "descartada"] as const).map((v) => (
            <Button key={v} type="button" size="sm" variant={currentExp === v ? "default" : "outline"} onClick={() => setSig("exposicion", v)}>
              {EXP_LABEL[v]}
            </Button>
          ))}
        </div>
        {expTrace ? <div className="mt-1 text-[10px] text-amber-600">{expTrace}</div> : null}
      </div>

      {/* Live suggestion */}
      <div className="rounded bg-muted/60 px-2 py-1 text-xs text-foreground">{suggestionText}{suggestion.revisar ? " (revisar)" : ""}</div>

      {/* Optional note max 300 */}
      <div>
        <Textarea
          placeholder="Nota opcional (máx 300)"
          value={note}
          maxLength={300}
          onChange={(e) => setNote(e.target.value)}
          className="text-xs"
        />
      </div>
    </div>
  )
}

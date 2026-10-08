"use client"

import { ui } from "@/lib/i18n/messages.mjs"
import { useLocale } from "@/components/locale-provider"

import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"

const referralOptions = [
  { value: "redes_sociales", label: "Redes sociales" },
  { value: "recomendacion", label: "Recomendación de un conocido" },
  { value: "universidad", label: "Universidad" },
  { value: "chatbots_ia", label: "Asistentes conversacionales de IA" },
  { value: "sygris", label: "A través de la empresa Sygris" },
]

interface OnboardingStepTwoProps {
  onComplete: (data: { referralSources: string[] }) => void
  loading?: boolean
}

export function OnboardingStepTwo({ onComplete, loading }: OnboardingStepTwoProps) {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  const [selectedSources, setSelectedSources] = useState<string[]>([])

  const toggleSource = (value: string) => {
    setSelectedSources((prev) => (prev.includes(value) ? prev.filter((s) => s !== value) : [...prev, value]))
  }

  const canComplete = selectedSources.length > 0

  return (
    <div className="w-full max-w-md space-y-8">
      <div className="text-center">
        <h1 className="text-2xl font-semibold text-foreground">{tr("¿Cómo nos conociste?")}</h1>
      </div>

      <div className="space-y-4">
        {referralOptions.map((option) => (
          <div key={option.value} className="flex items-center space-x-3">
            <Checkbox
              id={option.value}
              checked={selectedSources.includes(option.value)}
              onCheckedChange={() => toggleSource(option.value)}
            />
            <label htmlFor={option.value} className="text-sm font-medium text-foreground cursor-pointer">
              {tr(option.label)}
            </label>
          </div>
        ))}
      </div>

      <Button
        onClick={() => onComplete({ referralSources: selectedSources })}
        disabled={!canComplete || loading}
        className="w-full"
      >
        {loading ? tr("Finalizando...") : tr("Finalizar registro")}
      </Button>
    </div>
  )
}

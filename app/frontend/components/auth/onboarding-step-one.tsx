"use client"

import { useState } from "react"
import { Button } from "@/components/ui/button"

const purposeOptions = [
  { value: "trabajo", label: "Trabajo" },
  { value: "estudios", label: "Estudios" },
  { value: "uso_personal", label: "Uso personal" },
  { value: "ong", label: "Organizaciones sin fines de lucro" },
]

const roleOptions = [
  { value: "freelance", label: "Freelance" },
  { value: "ceo", label: "CEO" },
  { value: "director", label: "Director" },
  { value: "lider_equipo", label: "Líder de equipo" },
  { value: "miembro_equipo", label: "Miembro del equipo" },
  { value: "nivel_ejecutivo", label: "Nivel ejecutivo" },
]

interface OnboardingStepOneProps {
  onNext: (data: { purpose: string; role: string }) => void
}

export function OnboardingStepOne({ onNext }: OnboardingStepOneProps) {
  const [purpose, setPurpose] = useState("")
  const [role, setRole] = useState("")

  const canContinue = purpose && role

  return (
    <div className="w-full max-w-md space-y-8">
      <div className="text-center">
        <h1 className="text-2xl font-semibold text-foreground">¿Qué te trae por aquí?</h1>
      </div>

      <div className="space-y-3">
        <div className="flex flex-wrap gap-2">
          {purposeOptions.map((option) => (
            <button
              key={option.value}
              type="button"
              onClick={() => setPurpose(option.value)}
              className={`rounded-full border px-4 py-2 text-sm font-medium transition-colors ${
                purpose === option.value
                  ? "border-primary bg-primary text-primary-foreground"
                  : "border-border bg-background text-foreground hover:border-primary/50"
              }`}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      <div className="space-y-4">
        <h2 className="text-lg font-medium text-foreground">¿Cómo describirías tu rol actual?</h2>
        <div className="flex flex-wrap gap-2">
          {roleOptions.map((option) => (
            <button
              key={option.value}
              type="button"
              onClick={() => setRole(option.value)}
              className={`rounded-full border px-4 py-2 text-sm font-medium transition-colors ${
                role === option.value
                  ? "border-primary bg-primary text-primary-foreground"
                  : "border-border bg-background text-foreground hover:border-primary/50"
              }`}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      <Button onClick={() => onNext({ purpose, role })} disabled={!canContinue} className="w-full">
        Siguiente
      </Button>
    </div>
  )
}

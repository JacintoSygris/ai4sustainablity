"use client"

import { useEffect, useState } from "react"
import { X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { WIZARD_STEPS } from "@/lib/wizard-steps"

const storageKey = "wizard_expectations_dismissed"

const copy = {
  es: {
    title: "Qué vas a hacer aquí",
    intro:
      "Esta guía te lleva por el proceso de materialidad del estándar europeo ESRS en 6 pasos. No necesitas conocimientos previos: cada paso explica lo que tienes que hacer.",
    steps: [
      "cuéntanos cómo es tu empresa (10-15 min)",
      "revisa los temas candidatos que propone la IA para tu sector (15-30 min)",
      "aprende a analizar cada tema con tu equipo, fuera de la aplicación (la guía es para llevárselo; el análisis puede llevar días)",
      "confirma tu lista final de temas materiales (15-30 min)",
      "repasa los datos concretos que tendrás que reunir (1-2 h la primera pasada)",
      "revisa el estado de tu informe y descarga los resultados (10 min)",
    ],
    needsTitle: "Qué necesitas",
    needs:
      "Conocer tu empresa (actividad, plantilla, facturación aproximada) y poder hablar con las personas que la conocen por dentro.",
    outcomeTitle: "Qué obtienes",
    outcome:
      "Una lista justificada de temas materiales, el inventario de datos que pide el estándar y descargas para compartir con tu gestoría o consultoría.",
    dismiss: "Ocultar",
  },
  en: {
    title: "What you will do here",
    intro:
      "This guide takes you through the materiality process for the European ESRS standard in 6 steps. You do not need previous knowledge: each step explains what to do.",
    steps: [
      "tell us what your company is like (10-15 min)",
      "review the candidate topics proposed by AI for your sector (15-30 min)",
      "learn how to analyze each topic with your team, outside the application (the guide is meant to take away; the analysis can take days)",
      "confirm your final list of material topics (15-30 min)",
      "review the specific data you will need to collect (1-2 h the first pass)",
      "review your report status and download the results (10 min)",
    ],
    needsTitle: "What you need",
    needs:
      "Know your company (activity, workforce, approximate revenue) and be able to speak with the people who know it from the inside.",
    outcomeTitle: "What you get",
    outcome:
      "A justified list of material topics, the inventory of data requested by the standard, and downloads to share with your accountant or consultant.",
    dismiss: "Hide",
  },
}

export function WizardExpectations() {
  const [visible, setVisible] = useState(true)

  useEffect(() => {
    setVisible(window.localStorage.getItem(storageKey) !== "true")
  }, [])

  const dismiss = () => {
    window.localStorage.setItem(storageKey, "true")
    setVisible(false)
  }

  if (!visible) {
    return null
  }

  return (
    <section className="rounded-xl border border-border bg-card p-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h2 className="text-lg font-semibold text-foreground">{copy.es.title}</h2>
          <p className="mt-2 text-sm text-muted-foreground">{copy.es.intro}</p>
        </div>
        <Button type="button" variant="ghost" size="sm" onClick={dismiss} aria-label={copy.es.dismiss}>
          <X className="h-4 w-4" />
        </Button>
      </div>

      <ol className="mt-4 space-y-2 text-sm text-muted-foreground">
        {copy.es.steps.map((description, index) => (
          <li key={WIZARD_STEPS[index].id} className="flex gap-2">
            <span className="font-medium text-foreground">Paso {WIZARD_STEPS[index].id}:</span>
            <span>
              <span className="font-medium text-foreground">{WIZARD_STEPS[index].title}</span> - {description}
            </span>
          </li>
        ))}
      </ol>

      <div className="mt-4 grid gap-3 text-sm md:grid-cols-2">
        <div className="rounded-lg bg-muted/50 p-3">
          <h3 className="font-medium text-foreground">{copy.es.needsTitle}</h3>
          <p className="mt-1 text-muted-foreground">{copy.es.needs}</p>
        </div>
        <div className="rounded-lg bg-muted/50 p-3">
          <h3 className="font-medium text-foreground">{copy.es.outcomeTitle}</h3>
          <p className="mt-1 text-muted-foreground">{copy.es.outcome}</p>
        </div>
      </div>
    </section>
  )
}

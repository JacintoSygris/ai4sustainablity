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
      "Esta guía te ayuda a ordenar el trabajo ASG (ambiental, social y de gobernanza), CSRD y ESRS. Cada paso explica qué se registra y qué debe revisar tu equipo.",
    steps: [
      "describe la organización y el ejercicio de referencia",
      "revisa los asuntos ASG candidatos propuestos por la aplicación",
      "ordena el análisis de doble materialidad con tu equipo",
      "confirma la lista final de asuntos materiales",
      "registra indicadores/datos ESRS, pendientes y no aplicables justificados",
      "revisa el resumen y descarga resultados, incluido XHTML/iXBRL si procede",
    ],
    needsTitle: "Qué necesitas",
    needs:
      "Conocer actividad, plantilla, facturación aproximada, alcance y poder contrastar impactos, riesgos y oportunidades con personas responsables.",
    outcomeTitle: "Qué obtienes",
    outcome:
      "Propuestas revisadas, decisiones guardadas, una lista de requisitos de divulgación e indicadores/datos, y un resumen de preparación. No es un informe final.",
    dismiss: "Ocultar",
  },
  en: {
    title: "What you will do here",
    intro:
      "This guide helps you organise ESG work (environmental, social and governance; ASG in Spanish), CSRD and ESRS. Each step explains what is recorded and what your team must review.",
    steps: [
      "describe the organisation and reporting year",
      "review the ESG candidate matters proposed by the application",
      "organise double materiality assessment with your team",
      "confirm the final list of material matters",
      "record ESRS indicators/data, pending items and justified non-applicable items",
      "review the summary and download results, including XHTML/iXBRL where applicable",
    ],
    needsTitle: "What you need",
    needs:
      "Know activity, workforce, approximate turnover and scope, and be able to discuss impacts, risks and opportunities with responsible people.",
    outcomeTitle: "What you get",
    outcome:
      "Reviewed proposals, saved decisions, a list of Disclosure Requirements and indicators/data, and a preparation summary. It is not a final report.",
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

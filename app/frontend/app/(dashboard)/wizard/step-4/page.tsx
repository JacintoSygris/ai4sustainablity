import { WizardSidebar } from "@/components/wizard/wizard-sidebar"
import { FinalTopicsSelection } from "@/components/wizard/final-topics-selection"
import { AcademyTips } from "@/components/wizard/academy-tips"

const tips = [
  "La propuesta P6 es un punto de partida; P8 registra la decisión final posterior a la doble materialidad.",
  "Si eliminas E1 cuando estaba propuesto, Laravel exigirá una explicación corta para trazabilidad ESRS.",
  "El preview P9 se recalcula desde la selección final confirmada.",
]

const importantNote = {
  title: "Confirmación final",
  content:
    "La selección guardada en P8 es la que activa los estándares y datapoints que se mostrarán después en P9.",
}

export default function Step4Page() {
  const wizardSteps = [
    {
      id: 1,
      title: "Encuesta inicial",
      description: "Contesta unas preguntas rápidas sobre tu empresa. ¡Así podremos ayudarte mejor!",
      status: "completed" as const,
    },
    {
      id: 2,
      title: "Revisión de temas materiales",
      description: "Revisa los temas sugeridos por Airis y modifícalos si lo consideras necesario.",
      status: "completed" as const,
    },
    {
      id: 3,
      title: "Doble materialidad",
      description: "Realiza el análisis de doble materialidad siguiendo las indicaciones marcadas.",
      status: "completed" as const,
    },
    {
      id: 4,
      title: "Selección final de temas relevantes",
      description: "Tras realizar el análisis, identifica los temas más relevantes para tu empresa.",
      status: "current" as const,
    },
    {
      id: 5,
      title: "Datapoints (ESRS)",
      description: "Rellena los indicadores sugeridos con la información de tu empresa.",
      status: "upcoming" as const,
    },
    {
      id: 6,
      title: "Informe",
      description: "Revisa el borrador y las descargas preparadas por Laravel.",
      status: "upcoming" as const,
    },
  ]

  return (
    <div className="flex flex-col gap-8 lg:flex-row">
      <WizardSidebar steps={wizardSteps} currentStep={4} viewingStep={4} />

      <FinalTopicsSelection />

      <AcademyTips title="Recuerde" icon="warning" tips={tips} importantNote={importantNote} />
    </div>
  )
}

import { WizardSidebar } from "@/components/wizard/wizard-sidebar"
import { ReportDraftPanel } from "@/components/wizard/report-draft-panel"

export default function Step6Page() {
  const steps = [
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
      status: "completed" as const,
    },
    {
      id: 5,
      title: "Datapoints (ESRS)",
      description: "Rellena los indicadores sugeridos con la información de tu empresa.",
      status: "completed" as const,
    },
    {
      id: 6,
      title: "Informe",
      description: "Revisa el borrador y las descargas preparadas por Laravel.",
      status: "current" as const,
    },
  ]

  return (
    <div className="flex flex-col gap-8 lg:flex-row">
      <WizardSidebar steps={steps} currentStep={6} viewingStep={6} />

      <ReportDraftPanel />
    </div>
  )
}

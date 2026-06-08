import { WizardSidebar } from "@/components/wizard/wizard-sidebar"
import { AcademyTips } from "@/components/wizard/academy-tips"
import { DoubleMaterialityGuide } from "@/components/wizard/double-materiality-guide"

const academyTips = {
  title: "Airis Academy",
  tips: [
    "La doble materialidad evalúa cada asunto desde impacto y desde efecto financiero; P7 guía el trabajo externo y P8 registra la confirmación final.",
  ],
  links: [
    {
      title: "Plantillas ADM desde Laravel",
      href: "#templates",
    },
  ],
}

export default function Step3Page() {
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
      status: "current" as const,
    },
    {
      id: 4,
      title: "Selección final de temas relevantes",
      description: "Tras realizar el análisis, identifica los temas más relevantes para tu empresa.",
      status: "upcoming" as const,
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
      <WizardSidebar steps={wizardSteps} currentStep={3} viewingStep={3} />

      <DoubleMaterialityGuide />

      <AcademyTips title={academyTips.title} tips={academyTips.tips} links={academyTips.links} />
    </div>
  )
}

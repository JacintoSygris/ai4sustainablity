import { WizardSidebar } from "@/components/wizard/wizard-sidebar"
import { AcademyTips } from "@/components/wizard/academy-tips"
import { DoubleMaterialityGuide } from "@/components/wizard/double-materiality-guide"
import { wizardStepsForView } from "@/lib/wizard-steps"

const academyTips = {
  title: "Consejos prácticos",
  tips: [
    "La doble materialidad evalúa cada asunto desde el impacto y desde el efecto financiero; el paso 3 te guía para hacer el análisis fuera de la aplicación y el paso 4 registra tu confirmación final.",
  ],
}

export default function Step3Page() {
  const wizardSteps = wizardStepsForView(3)

  return (
    <div className="flex flex-col gap-8 lg:flex-row">
      <WizardSidebar steps={wizardSteps} currentStep={3} viewingStep={3} />

      <DoubleMaterialityGuide />

      <AcademyTips title={academyTips.title} tips={academyTips.tips} />
    </div>
  )
}

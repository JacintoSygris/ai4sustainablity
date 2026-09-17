import { WizardSidebar } from "@/components/wizard/wizard-sidebar"
import { AcademyTips } from "@/components/wizard/academy-tips"
import { MaterialTopicsForm } from "@/components/wizard/material-topics-form"
import { wizardStepsForView } from "@/lib/wizard-steps"

const academyTips = {
  title: "Consejos prácticos",
  tips: [
    "Revisa cada asunto propuesto por la IA y marca si lo aceptas, lo rechazas o lo dejas en duda para la doble materialidad.",
    "Si rechazas un asunto, deja una nota breve: esa explicación será útil para la trazabilidad posterior.",
  ],
}

export default function Step2Page() {
  const wizardSteps = wizardStepsForView(2)

  return (
    <div className="flex flex-col gap-8 lg:flex-row">
      <WizardSidebar steps={wizardSteps} currentStep={2} viewingStep={2} />

      <MaterialTopicsForm />

      <AcademyTips title={academyTips.title} tips={academyTips.tips} />
    </div>
  )
}

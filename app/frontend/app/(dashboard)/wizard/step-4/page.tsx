import { ui } from "@/lib/i18n/messages.mjs"
import { getLaravelServerLocale } from "@/lib/laravel-server"
import { WizardSidebar } from "@/components/wizard/wizard-sidebar"
import { FinalTopicsSelection } from "@/components/wizard/final-topics-selection"
import { AcademyTips } from "@/components/wizard/academy-tips"
import { wizardStepsForView } from "@/lib/wizard-steps"

const tips = [
  "La propuesta del paso 2 es un punto de partida; la selección final registra la decisión posterior a la doble importancia relativa.",
  "Si eliminas E1 cuando estaba propuesto, la plataforma exigirá una explicación corta para trazabilidad NEIS.",
  "La vista previa de datos normativos del paso 5 se recalcula desde la selección final confirmada.",
]

const importantNote = {
  title: "Confirmación final",
  content:
    "La selección final guardada activa los estándares y datos normativos que se mostrarán después en el paso 5.",
}

export default async function Step4Page() {
  const locale = await getLaravelServerLocale()
  const tr = (message: string) => ui(locale, message)

  const wizardSteps = wizardStepsForView(4)

  return (
    <div className="flex flex-col gap-8 lg:flex-row">
      <WizardSidebar steps={wizardSteps} currentStep={4} viewingStep={4} />

      <FinalTopicsSelection />

      <AcademyTips title={tr("Recuerde")} icon="warning" tips={tips} importantNote={importantNote} />
    </div>
  )
}

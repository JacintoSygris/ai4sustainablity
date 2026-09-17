import { WizardSidebar } from "@/components/wizard/wizard-sidebar"
import { ReportDraftPanel } from "@/components/wizard/report-draft-panel"
import { wizardStepsForView } from "@/lib/wizard-steps"

export default function Step6Page() {
  const steps = wizardStepsForView(6)

  return (
    <div className="flex min-w-0 flex-col gap-8 lg:flex-row">
      <WizardSidebar steps={steps} currentStep={6} viewingStep={6} />

      <ReportDraftPanel />
    </div>
  )
}

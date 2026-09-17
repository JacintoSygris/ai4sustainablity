import { WizardSidebar } from "@/components/wizard/wizard-sidebar"
import { EsrsDatapointsForm } from "@/components/wizard/esrs-datapoints-form"
import { wizardStepsForView } from "@/lib/wizard-steps"

export default function Step5Page() {
  const steps = wizardStepsForView(5)

  return (
    <div className="flex flex-col gap-8 lg:flex-row">
      <WizardSidebar steps={steps} currentStep={5} viewingStep={5} />

      <EsrsDatapointsForm />
    </div>
  )
}

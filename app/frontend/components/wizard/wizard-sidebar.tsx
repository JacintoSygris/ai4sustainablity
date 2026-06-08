import Link from "next/link"
import { ArrowLeft, Check } from "lucide-react"

interface WizardStep {
  id: number
  title: string
  description: string
  status: "completed" | "current" | "upcoming"
}

interface WizardSidebarProps {
  steps: WizardStep[]
  currentStep: number
  viewingStep: number
}

export function WizardSidebar({ steps, currentStep, viewingStep }: WizardSidebarProps) {
  return (
    <aside className="w-full lg:w-80 shrink-0">
      <Link href="/dashboard" className="mb-6 flex items-center gap-2 text-sm text-primary hover:underline">
        <ArrowLeft className="h-4 w-4" />
        Volver a inicio
      </Link>

      <nav className="space-y-2">
        {steps.map((step) => {
          const isCompleted = step.status === "completed"
          const isViewing = step.id === viewingStep
          const isClickable = isCompleted || step.status === "current"

          const stepContent = (
            <>
              <div
                className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-medium ${
                  isCompleted
                    ? "bg-accent text-accent-foreground"
                    : isViewing
                      ? "bg-primary text-primary-foreground"
                      : "bg-muted text-muted-foreground"
                }`}
              >
                {isCompleted ? <Check className="h-4 w-4" /> : step.id}
              </div>
              <div>
                <h3
                  className={`font-medium ${isViewing ? "text-primary" : isCompleted ? "text-foreground" : "text-muted-foreground"}`}
                >
                  {step.title}
                </h3>
                <p className="mt-1 text-sm text-muted-foreground">{step.description}</p>
              </div>
            </>
          )

          if (isClickable) {
            return (
              <Link
                key={step.id}
                href={`/wizard/step-${step.id}`}
                className={`flex gap-4 rounded-xl p-4 transition-colors cursor-pointer hover:bg-primary/5 ${
                  isViewing ? "bg-primary/10 border border-primary/20" : "bg-transparent"
                }`}
              >
                {stepContent}
              </Link>
            )
          }

          return (
            <div
              key={step.id}
              className={`flex gap-4 rounded-xl p-4 transition-colors ${
                isViewing ? "bg-primary/10 border border-primary/20" : "bg-transparent"
              }`}
            >
              {stepContent}
            </div>
          )
        })}
      </nav>
    </aside>
  )
}

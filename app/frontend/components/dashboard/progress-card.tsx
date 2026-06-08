import Link from "next/link"
import { Check } from "lucide-react"
import { Button } from "@/components/ui/button"

interface ProgressCardProps {
  currentStep: number
  totalSteps: number
  hasStarted: boolean
}

export function ProgressCard({ currentStep, totalSteps, hasStarted }: ProgressCardProps) {
  return (
    <div className="relative overflow-hidden rounded-2xl border border-border bg-card p-6 md:p-8">
      <div className="flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
        <div className="flex-1">
          <h2 className="text-xl font-semibold text-primary">Accede a tu informe ESG</h2>
          <p className="mt-2 text-muted-foreground">
            {hasStarted
              ? "Continúa generando tu informe ESG desde donde lo dejaste."
              : "Comienza a generar tu informe con ayuda de la inteligencia artificial 💡"}
          </p>

          {hasStarted ? (
            <>
              <div className="mt-6 flex items-center gap-2">
                {Array.from({ length: totalSteps }).map((_, i) => {
                  const stepNumber = i + 1
                  const isCompleted = stepNumber < currentStep
                  const isCurrent = stepNumber === currentStep
                  const isAccessible = stepNumber <= currentStep

                  const stepContent = (
                    <div
                      className={`flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium transition-colors ${
                        isCompleted
                          ? "bg-accent text-accent-foreground"
                          : isCurrent
                            ? "bg-primary text-primary-foreground"
                            : "bg-muted text-muted-foreground"
                      } ${isAccessible ? "cursor-pointer hover:opacity-80" : ""}`}
                    >
                      {isCompleted ? <Check className="h-4 w-4" /> : stepNumber}
                    </div>
                  )

                  return (
                    <div key={stepNumber} className="flex items-center">
                      {i > 0 && <div className={`h-0.5 w-8 md:w-12 ${isCompleted ? "bg-accent" : "bg-border"}`} />}
                      {isAccessible ? <Link href={`/wizard/step-${stepNumber}`}>{stepContent}</Link> : stepContent}
                    </div>
                  )
                })}
              </div>
              <Button className="mt-6" asChild>
                <Link href={`/wizard/step-${currentStep}`}>Continuar con el informe</Link>
              </Button>
            </>
          ) : (
            <Button className="mt-6" asChild>
              <Link href="/wizard/step-1">¡Empezar a generar el informe!</Link>
            </Button>
          )}
        </div>

        {/* Illustration */}
        <div className="hidden md:block">
          <img src="/esg-globe-illustration.png" alt="ESG Report Illustration" className="h-40 w-auto object-contain" />
        </div>
      </div>
    </div>
  )
}

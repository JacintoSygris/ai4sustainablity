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
          <h2 className="text-xl font-semibold text-primary">Continúa tu preparación ESRS</h2>
          <p className="mt-2 text-muted-foreground">
            {hasStarted
              ? "Retoma el recorrido desde el último punto guardado."
              : "Empieza describiendo la organización y revisando las propuestas automáticas."}
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
                <Link href={`/wizard/step-${currentStep}`}>Continuar el recorrido</Link>
              </Button>
            </>
          ) : (
            <Button className="mt-6" asChild>
              <Link href="/wizard/step-1">Empezar la preparación</Link>
            </Button>
          )}
        </div>

        <div className="hidden md:block">
          <img
            src="/esg-globe-illustration.png"
            alt="Ilustración de preparación de sostenibilidad"
            className="h-40 w-auto object-contain"
          />
        </div>
      </div>
    </div>
  )
}

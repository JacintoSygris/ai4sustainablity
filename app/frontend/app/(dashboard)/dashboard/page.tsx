import { ProgressCard } from "@/components/dashboard/progress-card"
import { AcademySection } from "@/components/dashboard/academy-section"
import {
  getLaravelServerCharacterization,
  getLaravelServerReportReadiness,
  getLaravelServerSession,
} from "@/lib/laravel-server"
import type { LaravelCharacterization, LaravelReportReadiness } from "@/lib/laravel-api"

function sectionReady(status: string | undefined): boolean {
  return status === "ready" || status === "complete"
}

function dashboardProgress(characterization: LaravelCharacterization | null, readiness: LaravelReportReadiness | null) {
  const totalSteps = 6

  if (!characterization) {
    return { currentStep: 1, hasStarted: false, totalSteps }
  }

  if (!readiness) {
    return { currentStep: characterization.status === "completed" ? 2 : 1, hasStarted: true, totalSteps }
  }

  const sections = readiness.sections

  if (!sectionReady(sections.characterization?.status)) {
    return { currentStep: 1, hasStarted: true, totalSteps }
  }

  if (!sectionReady(sections.materiality_proposal?.status)) {
    return { currentStep: 2, hasStarted: true, totalSteps }
  }

  if (!sectionReady(sections.materiality_confirmation?.status)) {
    return { currentStep: 4, hasStarted: true, totalSteps }
  }

  if (!sectionReady(sections.datapoint_responses?.status)) {
    return { currentStep: 5, hasStarted: true, totalSteps }
  }

  return { currentStep: 6, hasStarted: true, totalSteps }
}

export default async function DashboardPage() {
  const [session, characterization, readiness] = await Promise.all([
    getLaravelServerSession(),
    getLaravelServerCharacterization(),
    getLaravelServerReportReadiness(),
  ])

  const userName = session?.user?.name?.split(" ")[0] || "Usuario"
  const { currentStep, hasStarted, totalSteps } = dashboardProgress(characterization, readiness)

  return (
    <div>
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-foreground">¡Hola, {userName}!</h1>
        <p className="mt-2 text-muted-foreground">
          Te damos la bienvenida a Airis, tu asistente inteligente para generar informes ESG 100 % conformes con ESRS,
          Taxonomía UE y CSRD.
        </p>
      </div>

      <ProgressCard currentStep={currentStep} totalSteps={totalSteps} hasStarted={hasStarted} />

      <AcademySection />
    </div>
  )
}

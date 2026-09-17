import { WizardSidebar } from "@/components/wizard/wizard-sidebar"
import { AcademyTips } from "@/components/wizard/academy-tips"
import { InitialSurveyForm } from "@/components/wizard/initial-survey-form"
import { WizardExpectations } from "@/components/wizard/wizard-expectations"
import { wizardStepsForView } from "@/lib/wizard-steps"

const tips = [
  "Usa datos del último ejercicio cerrado.",
  "Si operas en varias regiones, incluye las que concentren al menos el 90 % de tus operaciones.",
  "Sé conciso: basta con 2–3 frases para describir tu actividad principal.",
]

const importanceItems = [
  {
    title: "Sector económico",
    description: "Te permite comparar tu empresa con otras del mismo sector y generar benchmarks sectoriales fiables.",
  },
  {
    title: "Ingresos anuales (€)",
    description:
      "La dimensión financiera es clave para ajustar el nivel de detalle y requisitos de reporte según las normas ESRS.",
  },
  {
    title: "Número de empleados",
    description:
      "Determina el alcance de obligaciones de divulgación y el tamaño de la organización para la doble materialidad.",
  },
  {
    title: "Ámbito geográfico",
    description: "Ayuda a captar los distintos marcos regulatorios y riesgos ESG específicos de cada región.",
  },
  {
    title: "Productos / Servicios",
    description: "Facilita el análisis de impactos directos e indirectos asociados a tus líneas de negocio.",
  },
]

export default function WizardStep1Page() {
  const wizardSteps = wizardStepsForView(1)

  return (
    <div className="flex flex-col gap-8 lg:flex-row">
      <WizardSidebar steps={wizardSteps} currentStep={1} viewingStep={1} />

      <main className="flex-1 space-y-6">
        <WizardExpectations />
        <InitialSurveyForm />
      </main>

      <AcademyTips
        title="Consejos prácticos"
        tips={tips}
        importanceTitle="¿Por qué es importante cada campo?"
        importanceItems={importanceItems}
      />
    </div>
  )
}

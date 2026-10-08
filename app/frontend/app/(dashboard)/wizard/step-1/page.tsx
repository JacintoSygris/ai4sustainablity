import { ui } from "@/lib/i18n/messages.mjs"
import { getLaravelServerLocale } from "@/lib/laravel-server"
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
    description: "Ayuda a situar la actividad en un contexto sectorial para revisar asuntos ASG candidatos.",
  },
  {
    title: "Ingresos anuales (€)",
    description:
      "La información sobre ingresos ayuda a contextualizar el tamaño y el nivel de detalle del trabajo ASG/NEIS.",
  },
  {
    title: "Número de empleados",
    description:
      "Ayuda a contextualizar el tamaño y alcance de la organización; no determina obligaciones.",
  },
  {
    title: "Ámbito geográfico",
    description: "Ayuda a situar las operaciones y los riesgos de sostenibilidad que deberán revisarse.",
  },
  {
    title: "Productos / Servicios",
    description: "Facilita el análisis de impactos directos e indirectos asociados a tus líneas de negocio.",
  },
]

export default async function WizardStep1Page() {
  const locale = await getLaravelServerLocale()
  const tr = (message: string) => ui(locale, message)

  const wizardSteps = wizardStepsForView(1)

  return (
    <div className="flex flex-col gap-8 lg:flex-row">
      <WizardSidebar steps={wizardSteps} currentStep={1} viewingStep={1} />

      <main className="flex-1 space-y-6">
        <WizardExpectations />
        <InitialSurveyForm />
      </main>

      <AcademyTips
        title={tr("Consejos prácticos")}
        tips={tips}
        importanceTitle={tr("¿Por qué es importante cada campo?")}
        importanceItems={importanceItems}
      />
    </div>
  )
}

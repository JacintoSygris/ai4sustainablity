import { HelpCircle, Sparkles, FileText } from "lucide-react"

export function FeaturesSection() {
  const features = [
    {
      icon: HelpCircle,
      title: "Sin conocimientos previos",
      description:
        "No necesitas experiencia técnica ni en sostenibilidad. Solo sigue las indicaciones de Airis y completa la información paso a paso.",
    },
    {
      icon: Sparkles,
      title: "Inteligencia artificial ESG",
      description:
        "Nuestra inteligencia artificial está entrenada con las mejores prácticas de sostenibilidad para generar contenido preciso y adaptado.",
    },
    {
      icon: FileText,
      title: "Informes automáticos",
      description:
        "Genera un informe ESG al instante, listo para presentar a tus stakeholders y cumplir con las normativas.",
    },
  ]

  return (
    <section className="py-20 bg-secondary/30">
      <div className="container mx-auto px-4">
        <div className="grid gap-8 md:grid-cols-3">
          {features.map((feature) => (
            <div key={feature.title} className="flex flex-col items-center text-center">
              <div className="mb-4 flex h-20 w-20 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <feature.icon className="h-9 w-9" aria-hidden="true" />
              </div>
              <h3 className="text-lg font-semibold text-foreground">{feature.title}</h3>
              <p className="mt-2 text-sm text-muted-foreground">{feature.description}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

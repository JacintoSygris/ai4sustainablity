import { HelpCircle, Sparkles, FileText } from "lucide-react"

export function FeaturesSection() {
  const features = [
    {
      icon: HelpCircle,
      title: "Recorrido guiado",
      description:
        "La interfaz separa la descripción de la organización, la revisión de temas, la materialidad y la recogida de información.",
    },
    {
      icon: Sparkles,
      title: "Propuestas revisables",
      description:
        "Las sugerencias automáticas son puntos de partida. La selección final y los motivos siguen siendo decisiones humanas.",
    },
    {
      icon: FileText,
      title: "Salidas de trabajo",
      description:
        "Descarga hojas de decisión, listas CSV, resúmenes de preparación y paquetes técnicos condicionados para revisión.",
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

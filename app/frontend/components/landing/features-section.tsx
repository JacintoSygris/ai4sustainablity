"use client"

import { ui } from "@/lib/i18n/messages.mjs"
import { useLocale } from "@/components/locale-provider"

import { HelpCircle, Sparkles, FileText } from "lucide-react"

export function FeaturesSection() {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  const features = [
    {
      icon: HelpCircle,
      title: tr("Recorrido guiado"),
      description:
        tr("La interfaz separa la caracterización, la revisión de asuntos ASG, la doble importancia relativa y la recogida de datos normativos NEIS."),
    },
    {
      icon: Sparkles,
      title: tr("Propuestas revisables"),
      description:
        tr("Las sugerencias automáticas son puntos de partida. La organización confirma la doble importancia relativa y los motivos."),
    },
    {
      icon: FileText,
      title: tr("Salidas de trabajo"),
      description:
        tr("Descarga hojas de decisión, listas CSV, resúmenes de preparación y trazabilidad de evidencias."),
    },
  ]

  return (
    <section className="py-20 bg-secondary/30">
      <div className="container mx-auto px-4">
        <div className="grid gap-8 md:grid-cols-3">
          {features.map((feature) => (
            <div key={tr(feature.title)} className="flex flex-col items-center text-center">
              <div className="mb-4 flex h-20 w-20 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <feature.icon className="h-9 w-9" aria-hidden="true" />
              </div>
              <h3 className="text-lg font-semibold text-foreground">{tr(feature.title)}</h3>
              <p className="mt-2 text-sm text-muted-foreground">{tr(feature.description)}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

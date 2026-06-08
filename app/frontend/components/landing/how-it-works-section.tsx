export function HowItWorksSection() {
  const steps = [
    {
      number: 1,
      title: "Caracterización inicial",
      description: "Define tu sector, tamaño, alcance geográfico y productos/servicios.",
    },
    {
      number: 2,
      title: "Análisis de doble materialidad",
      description: "Identifica y prioriza los impactos medioambientales y sociales más relevantes.",
    },
    {
      number: 3,
      title: "Generación automática",
      description: "Crea tu informe en xHTML + iXBRL con un solo clic.",
    },
    {
      number: 4,
      title: "Revisión y exportación",
      description: "Verifica el contenido y descarga el paquete listo para subir a ESAP.",
    },
  ]

  return (
    <section className="py-20 bg-background">
      <div className="container mx-auto px-4">
        <div className="grid gap-12 lg:grid-cols-2">
          {/* Left side - Description */}
          <div className="rounded-2xl bg-primary p-8 text-primary-foreground lg:p-12">
            <h2 className="text-2xl font-bold md:text-3xl">¿Cómo te ayuda Airis?</h2>
            <p className="mt-4 text-primary-foreground/80">
              Airis automatiza todo el flujo de trabajo de tu reporte de sostenibilidad, desde la caracterización
              inicial hasta la generación final del informe listo para ESAP.
            </p>
            <button className="mt-6 rounded-lg border border-primary-foreground/30 px-6 py-2 text-sm font-medium transition-colors hover:bg-primary-foreground/10">
              Descubrir
            </button>
          </div>

          {/* Right side - Steps */}
          <div className="space-y-4">
            {steps.map((step) => (
              <div
                key={step.number}
                className="flex items-start gap-4 rounded-xl bg-primary p-4 text-primary-foreground"
              >
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-foreground/20 text-lg font-bold">
                  {step.number}
                </div>
                <div>
                  <h3 className="font-semibold">{step.title}</h3>
                  <p className="mt-1 text-sm text-primary-foreground/80">{step.description}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </section>
  )
}

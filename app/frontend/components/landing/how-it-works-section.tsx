export function HowItWorksSection() {
  const steps = [
    {
      number: 1,
      title: "Caracterización inicial",
      description: "Define tu sector, tamaño, alcance geográfico y productos/servicios.",
    },
    {
      number: 2,
      title: "Revisión de temas",
      description: "Contrasta las propuestas con tu actividad, cadena de valor y criterio del equipo responsable.",
    },
    {
      number: 3,
      title: "Materialidad y datos",
      description: "Registra la selección humana y reúne información, pendientes y referencias de evidencia.",
    },
    {
      number: 4,
      title: "Resumen y exportación",
      description: "Consulta el estado de preparación y descarga salidas de trabajo para revisión posterior.",
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
              Airis organiza el recorrido y deja visible qué está registrado, qué falta y qué sigue dependiendo de tu
              equipo.
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

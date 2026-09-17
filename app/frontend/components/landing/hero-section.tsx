import Link from "next/link"
import { Button } from "@/components/ui/button"

export function HeroSection() {
  return (
    <section className="relative overflow-hidden bg-gradient-to-b from-primary/5 to-background py-20 md:py-28">
      <div className="container mx-auto px-4">
        <div className="grid items-center gap-12 lg:grid-cols-2">
          <div className="max-w-xl">
            <h1 className="text-balance text-4xl font-bold tracking-tight text-foreground md:text-5xl lg:text-6xl">
              Ordena tu trabajo ESRS,
              <br />
              <span className="text-foreground">con decisiones humanas y límites claros</span>
            </h1>
            <p className="mt-6 text-lg text-muted-foreground">
              Airis te ayuda a describir tu organización, revisar temas propuestos, registrar decisiones de materialidad
              y preparar un resumen de información disponible y pendiente.
            </p>
            <Button size="lg" className="mt-8" asChild>
              <Link href="/register">Empezar ahora</Link>
            </Button>
          </div>

          <div className="relative hidden lg:block">
            <img
              src="/dashboard-esg-report-interface-mockup.jpg"
              alt="Vista previa de la interfaz de Airis"
              className="w-full rounded-lg shadow-2xl"
            />
          </div>
        </div>
      </div>

      {/* Decorative elements */}
      <div className="absolute right-0 top-20 -z-10 h-72 w-72 rounded-full bg-accent/20 blur-3xl" />
      <div className="absolute -left-20 bottom-0 -z-10 h-96 w-96 rounded-full bg-primary/10 blur-3xl" />
    </section>
  )
}

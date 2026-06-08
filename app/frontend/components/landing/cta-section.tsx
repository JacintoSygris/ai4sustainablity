import Link from "next/link"
import { Button } from "@/components/ui/button"

export function CtaSection() {
  return (
    <section className="relative overflow-hidden py-20">
      <div className="absolute inset-0 z-0">
        <img src="/forest-sustainability-nature-green.jpg" alt="Naturaleza sostenible" className="h-full w-full object-cover" />
        <div className="absolute inset-0 bg-primary/80" />
      </div>

      <div className="container relative z-10 mx-auto px-4 text-center">
        <h2 className="text-balance text-2xl font-bold text-primary-foreground md:text-3xl">
          Consigue un informe completo en formatos xHTML e iXBRL,
          <br />
          perfectamente alineado con la normativa y listo para su entrega.
        </h2>
        <Button size="lg" variant="secondary" className="mt-8" asChild>
          <Link href="/register">Pruébalo gratis</Link>
        </Button>
      </div>
    </section>
  )
}

"use client"

import { ui } from "@/lib/i18n/messages.mjs"
import { useLocale } from "@/components/locale-provider"

import Link from "next/link"
import { Button } from "@/components/ui/button"

export function CtaSection() {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  return (
    <section className="relative overflow-hidden py-20">
      <div className="absolute inset-0 z-0">
        <img src="/forest-sustainability-nature-green.jpg" alt={tr("Naturaleza sostenible")} className="h-full w-full object-cover" />
        <div className="absolute inset-0 bg-primary/80" />
      </div>

      <div className="container relative z-10 mx-auto px-4 text-center">
        <h2 className="text-balance text-2xl font-bold text-primary-foreground md:text-3xl">
          {" "}{tr("Prepara tu paquete de trabajo ASG/NEIS con evidencias trazables,")}{" "}<br />
          {" "}{tr("control humano y límites claros antes de la entrega.")}{" "}</h2>
        <Button size="lg" variant="secondary" className="mt-8" asChild>
          <Link href="/register">{tr("Pruébalo gratis")}</Link>
        </Button>
      </div>
    </section>
  )
}

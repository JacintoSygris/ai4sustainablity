"use client"

import { useLocale } from "@/components/locale-provider"
import { ui } from "@/lib/i18n/messages.mjs"

export default function ErrorPage({ reset }: { reset: () => void }) {
  const { locale } = useLocale()
  return <main className="container mx-auto max-w-2xl space-y-4 px-4 py-12">
    <h1 className="text-2xl font-semibold">{ui(locale, "No se ha podido cargar esta página.")}</h1>
    <button type="button" className="rounded-md border px-4 py-2" onClick={reset}>{ui(locale, "Reintentar")}</button>
  </main>
}

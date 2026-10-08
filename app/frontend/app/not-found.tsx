import Link from "next/link"
import { getLaravelServerLocale } from "@/lib/laravel-server"
import { ui } from "@/lib/i18n/messages.mjs"

export default async function NotFound() {
  const locale = await getLaravelServerLocale()
  return <main className="container mx-auto max-w-2xl space-y-4 px-4 py-12">
    <h1 className="text-2xl font-semibold">{ui(locale, "Página no encontrada")}</h1>
    <p>{ui(locale, "No se ha encontrado la página solicitada.")}</p>
    <Link className="text-primary underline" href="/dashboard">{ui(locale, "Volver al panel")}</Link>
  </main>
}

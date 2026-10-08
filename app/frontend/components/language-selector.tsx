"use client"

import { useId, useState } from "react"
import { useLocale, type AppLocale } from "@/components/locale-provider"

export function LanguageSelector() {
  const { locale, changing, changeLocale } = useLocale()
  const id = useId()
  const [failed, setFailed] = useState(false)
  return <div className="flex flex-wrap items-center gap-2 text-sm">
    <label htmlFor={id}>{locale === "es" ? "Idioma" : "Language"}</label>
    <select id={id} value={locale} disabled={changing} aria-busy={changing}
      className="rounded-md border border-input bg-background px-2 py-2 focus-visible:ring-2 focus-visible:ring-primary"
      onChange={async (event) => {
        setFailed(false)
        try { await changeLocale(event.target.value as AppLocale) } catch { setFailed(true) }
      }}>
      <option value="es" lang={locale}>{locale === "es" ? "Español" : "Spanish"}</option>
      <option value="en" lang={locale}>{locale === "es" ? "Inglés" : "English"}</option>
    </select>
    {failed && <span role="alert">{locale === "es" ? "No se pudo cambiar el idioma. Inténtalo de nuevo." : "Could not change the language. Try again."}</span>}
  </div>
}

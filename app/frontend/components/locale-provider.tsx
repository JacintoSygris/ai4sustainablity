"use client"

import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from "react"
import { useRouter } from "next/navigation"
import { getLaravelLocale, updateLaravelLocale } from "@/lib/laravel-api"
import { localeMetadata, normalizeLocale } from "@/lib/i18n/locale.mjs"

export type AppLocale = "es" | "en"
const LocaleContext = createContext<{
  locale: AppLocale
  changing: boolean
  changeLocale: (locale: AppLocale) => Promise<void>
}>({ locale: "es", changing: false, changeLocale: async () => {} })

export function LocaleProvider({ initialLocale = "es", children }: { initialLocale?: AppLocale; children: ReactNode }) {
  const router = useRouter()
  const inFlight = useRef(false)
  const [locale, setLocale] = useState<AppLocale>(initialLocale)
  const [changing, setChanging] = useState(false)
  const changeVersion = useRef(0)
  useEffect(() => {
    let active = true
    const version = changeVersion.current
    // Recover the browser preference if the server-rendered lookup was unavailable.
    getLaravelLocale().then((response) => {
      if (active && version === changeVersion.current) {
        const recovered = normalizeLocale(response.data.locale)
        setLocale(recovered)
        if (recovered !== initialLocale) router.refresh()
      }
    }).catch(() => { /* Keep the server-rendered locale during a transient outage. */ })
    return () => { active = false }
  }, [])
  useEffect(() => {
    document.documentElement.lang = locale
    document.title = localeMetadata[locale].title
    document.querySelector('meta[name="description"]')?.setAttribute("content", localeMetadata[locale].description)
  }, [locale])

  async function changeLocale(next: AppLocale) {
    if (next !== "es" && next !== "en") throw new Error("Unsupported locale")
    if (inFlight.current || next === locale) return
    inFlight.current = true
    const previous = locale
    changeVersion.current += 1
    setChanging(true)
    try {
      // Guest-capable CSRF bootstrap; the cookie is issued by the same authority as downloads.
      const context = await getLaravelLocale()
      const response = await updateLaravelLocale(next, context.data.csrf_token)
      if (response.data.locale !== next) throw new Error("Locale preference was not persisted")
      setLocale(next)
      // Merge refreshed server components without replacing client state or draft ownership.
      router.refresh()
    } catch (error) {
      setLocale(previous)
      throw error
    } finally {
      inFlight.current = false
      setChanging(false)
    }
  }
  return <LocaleContext.Provider value={{ locale, changing, changeLocale }}>{children}</LocaleContext.Provider>
}
export function useLocale() { return useContext(LocaleContext) }

"use client"

import { useEffect, useReducer } from "react"
import { getBrowserConsent } from "../public/consent/core.mjs"

type Purpose = "preferences" | "recovery" | "realtime"

/** A render's callbacks keep this grant: revocation invalidates queued work permanently. */
export function useOptionalStorage(purpose: Purpose) {
  const [, redraw] = useReducer((value: number) => value + 1, 0)
  const api = getBrowserConsent()
  useEffect(() => api?.subscribe(() => redraw()), [api])
  const grant = api?.lease(purpose)
  return {
    getItem: (key: string): string | null => grant?.read(key) ?? null,
    setItem: (key: string, value: string): boolean => grant?.write(key, value) ?? false,
    removeItem: (key: string): boolean => grant?.remove(key) ?? false,
  }
}

export function persistSidebar(open: boolean, maxAge: number) {
  if (!getBrowserConsent()?.hasConsent("preferences")) return false
  document.cookie = `sidebar_state=${open}; path=/; max-age=${maxAge}; SameSite=Lax`
  return true
}

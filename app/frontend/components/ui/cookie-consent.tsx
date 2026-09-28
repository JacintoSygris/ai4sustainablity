"use client"

import { useEffect, useRef } from "react"
import { getBrowserConsent } from "@/public/consent/core.mjs"
import { mountConsent } from "@/public/consent/ui.mjs"

export function CookieConsent() {
  const host = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const api = getBrowserConsent()
    if (host.current && api) return mountConsent(host.current, api)
  }, [])
  return <div ref={host} />
}

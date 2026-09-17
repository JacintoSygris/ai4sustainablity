"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { Cookie } from "lucide-react"
import { Button } from "@/components/ui/button"

const STORAGE_KEY = "airis-cookie-consent"

export function CookieConsent() {
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    try {
      const stored = window.localStorage.getItem(STORAGE_KEY)
      if (!stored) {
        setVisible(true)
      }
    } catch {
      // If storage is unavailable we simply do not surface the banner.
    }
  }, [])

  const acknowledge = () => {
    try {
      window.localStorage.setItem(STORAGE_KEY, "necessary-only")
    } catch {
      // Ignore storage failures; hiding the banner for this session is enough.
    }
    setVisible(false)
  }

  if (!visible) {
    return null
  }

  return (
    <div
      role="region"
      aria-label="Aviso de cookies"
      className="fixed inset-x-0 bottom-0 z-50 border-t border-border bg-background/95 p-4 backdrop-blur"
    >
      <div className="container mx-auto flex flex-col items-start gap-4 md:flex-row md:items-center md:justify-between">
        <div className="flex items-start gap-3">
          <Cookie className="mt-0.5 h-5 w-5 shrink-0 text-primary" aria-hidden="true" />
          <p className="text-sm text-muted-foreground">
            Usamos solo cookies necesarias para iniciar sesión y mantener tu sesión segura. No usamos cookies de
            seguimiento ni de terceros.{" "}
            <Link href="/privacy" className="text-primary hover:underline">
              Más información
            </Link>
            .
          </p>
        </div>
        <Button type="button" onClick={acknowledge} className="shrink-0">
          Entendido
        </Button>
      </div>
    </div>
  )
}

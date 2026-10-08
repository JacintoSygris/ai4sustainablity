"use client"

import { LanguageSelector } from "@/components/language-selector"
import { useLocale } from "@/components/locale-provider"
import { ui } from "@/lib/i18n/messages.mjs"
import Link from "next/link"
import { HelpCircle } from "lucide-react"

export function AuthHeader() {
  const { locale } = useLocale()
  return (
    <header className="sticky top-0 z-50 w-full border-b border-border/40 bg-background/95 backdrop-blur">
      <div className="container mx-auto flex h-16 items-center justify-between px-4">
        <Link href="/" className="flex items-center gap-1">
          <span className="text-2xl font-bold text-primary">Airis</span>
          <span className="text-xs text-muted-foreground">{ui(locale, "Por Sygris")}</span>
        </Link>

        <div className="flex items-center gap-4">
          <Link href="/help" className="flex items-center gap-2 text-sm text-primary hover:underline">
            <HelpCircle className="h-4 w-4" />
            {ui(locale, "¿Necesitas ayuda?")}
          </Link>

          <LanguageSelector />
        </div>
      </div>
    </header>
  )
}

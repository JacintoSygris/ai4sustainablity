import Link from "next/link"
import { HelpCircle, Globe } from "lucide-react"

export function AuthHeader() {
  return (
    <header className="sticky top-0 z-50 w-full border-b border-border/40 bg-background/95 backdrop-blur">
      <div className="container mx-auto flex h-16 items-center justify-between px-4">
        <Link href="/" className="flex items-center gap-1">
          <span className="text-2xl font-bold text-primary">Airis</span>
          <span className="text-xs text-muted-foreground">By Sygris</span>
        </Link>

        <div className="flex items-center gap-4">
          <Link href="/help" className="flex items-center gap-2 text-sm text-primary hover:underline">
            <HelpCircle className="h-4 w-4" />
            ¿Necesitas ayuda?
          </Link>

          <div className="flex items-center gap-2 rounded-lg border border-input px-3 py-2 text-sm">
            <Globe className="h-4 w-4 text-muted-foreground" />
            <span>Español</span>
          </div>
        </div>
      </div>
    </header>
  )
}

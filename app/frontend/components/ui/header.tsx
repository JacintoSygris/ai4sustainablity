import Link from "next/link"
import { Button } from "@/components/ui/button"

export function Header({ showAuthButtons = true }: { showAuthButtons?: boolean }) {
  return (
    <header className="sticky top-0 z-50 w-full border-b border-border/40 bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60">
      <div className="container mx-auto flex h-16 items-center justify-between px-4">
        <Link href="/" className="flex items-center gap-1">
          <span className="text-2xl font-bold text-primary">Airis</span>
          <span className="text-xs text-muted-foreground">By Sygris</span>
        </Link>

        {showAuthButtons && (
          <div className="flex items-center gap-3">
            <Button variant="outline" asChild>
              <Link href="/register">Regístrate</Link>
            </Button>
            <Button asChild>
              <Link href="/login">Iniciar sesión</Link>
            </Button>
          </div>
        )}
      </div>
    </header>
  )
}

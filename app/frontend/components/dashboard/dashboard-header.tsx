"use client"

import Link from "next/link"
import { useRouter } from "next/navigation"
import { HelpCircle } from "lucide-react"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"

interface DashboardHeaderProps {
  user: {
    name: string | null
    email: string
    image?: string | null
  }
}

function readCookie(name: string): string | null {
  const encoded = document.cookie
    .split(";")
    .map((cookie) => cookie.trim())
    .find((cookie) => cookie.startsWith(`${name}=`))

  if (!encoded) {
    return null
  }

  return decodeURIComponent(encoded.slice(name.length + 1))
}

export function DashboardHeader({ user }: DashboardHeaderProps) {
  const router = useRouter()

  const handleLogout = async () => {
    const csrfToken = readCookie("XSRF-TOKEN")
    const headers = new Headers()

    if (csrfToken) {
      headers.set("X-CSRF-TOKEN", csrfToken)
      headers.set("X-XSRF-TOKEN", csrfToken)
    }

    await fetch("/logout", {
      credentials: "include",
      headers,
      method: "POST",
    })

    router.push("/login")
    router.refresh()
  }

  const displayName = user.name || user.email
  const initials = displayName
    .split(" ")
    .map((n) => n[0])
    .join("")
    .toUpperCase()
    .slice(0, 2)

  return (
    <header className="sticky top-0 z-50 w-full border-b border-border/40 bg-background/95 backdrop-blur">
      <div className="container mx-auto flex h-16 items-center justify-between px-4">
        <Link href="/dashboard" className="flex items-center gap-1">
          <span className="text-2xl font-bold text-primary">Airis</span>
          <span className="text-xs text-muted-foreground">By Sygris</span>
        </Link>

        <div className="flex items-center gap-4">
          <Link href="/help" className="flex items-center gap-2 text-sm text-primary hover:underline">
            <HelpCircle className="h-4 w-4" />
            ¿Necesitas ayuda?
          </Link>

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button className="rounded-full focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">
                <Avatar className="h-10 w-10 border-2 border-accent">
                  <AvatarImage src={user.image || undefined} alt={displayName} />
                  <AvatarFallback className="bg-accent text-accent-foreground">{initials}</AvatarFallback>
                </Avatar>
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
              <div className="px-2 py-1.5">
                <p className="text-sm font-medium">{displayName}</p>
                <p className="text-xs text-muted-foreground">{user.email}</p>
              </div>
              <DropdownMenuSeparator />
              <DropdownMenuItem asChild>
                <Link href="/settings">Configuración</Link>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={handleLogout} className="text-destructive">
                Cerrar sesión
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>
    </header>
  )
}

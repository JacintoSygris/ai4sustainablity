"use client"

import { useSystemMessage, systemCopy } from "@/lib/i18n/use-system-message"

import { ui } from "@/lib/i18n/messages.mjs"
import { useLocale } from "@/components/locale-provider"

import type React from "react"

import { useState } from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { Eye, EyeOff } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { fetchLaravelCsrfToken, laravelAuthHeaders, laravelValidationMessage } from "@/lib/laravel-auth"

export function LoginForm() {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  const router = useRouter()
  const [email, setEmail] = useState("")
  const [password, setPassword] = useState("")
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useSystemMessage("")
  const [loading, setLoading] = useState(false)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError("")
    setLoading(true)

    try {
      const csrfToken = await fetchLaravelCsrfToken("/laravel/login")
      const response = await fetch("/laravel/login", {
        body: new URLSearchParams({
          _token: csrfToken,
          email,
          password,
        }),
        credentials: "include",
        headers: laravelAuthHeaders(csrfToken),
        method: "POST",
        redirect: "manual",
      })

      if (!(response.ok || response.type === "opaqueredirect" || (response.status >= 300 && response.status < 400))) {
        setError(await laravelValidationMessage(response, tr("Error al iniciar sesión"), locale))
        return
      }

      router.push("/dashboard")
      router.refresh()
    } catch {
      setError(systemCopy("Error al iniciar sesión. Verifica tus credenciales."))
    } finally {
      setLoading(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="w-full max-w-md space-y-6">
      <div className="text-center">
        <h1 className="text-2xl font-semibold text-foreground">{tr("Iniciar sesión")}</h1>
      </div>

      {error && <div className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}

      <div className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="email">{tr("Email")}</Label>
          <Input
            id="email"
            type="email"
            placeholder={tr("nombre@empresa.com")}
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </div>

        <div className="space-y-2">
          <Label htmlFor="password">{tr("Contraseña")}</Label>
          <div className="relative">
            <Input
              id="password"
              type={showPassword ? "text" : "password"}
              placeholder="••••••••••••••"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
            <button
              type="button"
              className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
              onClick={() => setShowPassword(!showPassword)}
            >
              {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
            </button>
          </div>
        </div>
      </div>

      <Button type="submit" className="w-full" disabled={loading}>
        {loading ? tr("Iniciando sesión...") : tr("Iniciar sesión")}
      </Button>

      <div className="text-center">
        <Link href="/forgot-password" className="text-sm text-primary hover:underline">
          {" "}{tr("¿Has olvidado la contraseña?")}{" "}</Link>
      </div>

      <div className="text-center text-sm text-muted-foreground">
        {" "}{tr("¿No tienes cuenta?")}{" "}
        <Link href="/register" className="text-primary hover:underline">
          {" "}{tr("Regístrate")}{" "}</Link>
      </div>
    </form>
  )
}

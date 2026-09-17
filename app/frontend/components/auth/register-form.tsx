"use client"

import type React from "react"

import { useEffect, useRef, useState } from "react"
import Link from "next/link"
import Script from "next/script"
import { useRouter } from "next/navigation"
import { Eye, EyeOff } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { getLaravelRegisterConfig } from "@/lib/laravel-api"
import { fetchLaravelCsrfToken, laravelAuthHeaders, laravelValidationMessage } from "@/lib/laravel-auth"

const DEFAULT_HONEYPOT_FIELD = "company_website"
const TURNSTILE_SCRIPT_SRC = "https://challenges.cloudflare.com/turnstile/v0/api.js"

export function RegisterForm() {
  const router = useRouter()
  const formRef = useRef<HTMLFormElement>(null)
  const [name, setName] = useState("")
  const [email, setEmail] = useState("")
  const [password, setPassword] = useState("")
  const [confirmPassword, setConfirmPassword] = useState("")
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState("")
  const [loading, setLoading] = useState(false)
  const [honeypotField, setHoneypotField] = useState(DEFAULT_HONEYPOT_FIELD)
  const [turnstileSiteKey, setTurnstileSiteKey] = useState<string | null>(null)
  const [requireEmailVerification, setRequireEmailVerification] = useState(false)

  useEffect(() => {
    let mounted = true

    getLaravelRegisterConfig()
      .then((response) => {
        if (!mounted) {
          return
        }

        const config = response.data

        if (config.honeypot_field) {
          setHoneypotField(config.honeypot_field)
        }

        setTurnstileSiteKey(config.turnstile_site_key ?? null)
        setRequireEmailVerification(Boolean(config.require_email_verification))
      })
      .catch(() => {
        // Registration keeps working with the honeypot default and without a
        // challenge widget when the configuration endpoint is unavailable.
      })

    return () => {
      mounted = false
    }
  }, [])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError("")

    if (password !== confirmPassword) {
      setError("Las contraseñas no coinciden")
      return
    }

    if (password.length < 8) {
      setError("La contraseña debe tener al menos 8 caracteres")
      return
    }

    setLoading(true)

    try {
      const csrfToken = await fetchLaravelCsrfToken("/laravel/register")
      const params = new URLSearchParams({
        _token: csrfToken,
        email,
        name,
        password,
        password_confirmation: confirmPassword,
      })

      // Bot trap: submit whatever a bot typed into the off-screen field (empty
      // for real users) so the server can reject automated sign-ups.
      const honeypotInput = formRef.current?.elements.namedItem(honeypotField) as HTMLInputElement | null
      params.set(honeypotField, honeypotInput?.value ?? "")

      // Cloudflare injects this token field when the challenge widget renders.
      const challengeInput = formRef.current?.querySelector<HTMLInputElement>(
        'input[name="cf-turnstile-response"]',
      )
      if (challengeInput?.value) {
        params.set("cf-turnstile-response", challengeInput.value)
      }

      const response = await fetch("/laravel/register", {
        body: params,
        credentials: "include",
        headers: laravelAuthHeaders(csrfToken),
        method: "POST",
        redirect: "manual",
      })

      if (!(response.ok || response.type === "opaqueredirect" || (response.status >= 300 && response.status < 400))) {
        setError(await laravelValidationMessage(response, "Error al crear la cuenta"))
        return
      }

      router.push(requireEmailVerification ? "/verify-email" : "/dashboard")
      router.refresh()
    } catch {
      setError("Error al crear la cuenta. Intenta de nuevo.")
    } finally {
      setLoading(false)
    }
  }

  return (
    <form ref={formRef} onSubmit={handleSubmit} className="w-full max-w-md space-y-6">
      {turnstileSiteKey ? <Script src={TURNSTILE_SCRIPT_SRC} strategy="afterInteractive" /> : null}

      <div className="text-center">
        <h1 className="text-2xl font-semibold text-foreground">Crear cuenta</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Organiza información ESRS con propuestas revisables y decisiones humanas
        </p>
      </div>

      {error && <div className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}

      <div className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="name">Nombre completo</Label>
          <Input
            id="name"
            type="text"
            placeholder="Juan Hernández"
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
          />
        </div>

        <div className="space-y-2">
          <Label htmlFor="email">Email</Label>
          <Input
            id="email"
            type="email"
            placeholder="nombre@empresa.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </div>

        <div className="space-y-2">
          <Label htmlFor="password">Contraseña</Label>
          <div className="relative">
            <Input
              id="password"
              type={showPassword ? "text" : "password"}
              placeholder="Mínimo 8 caracteres"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              minLength={8}
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

        <div className="space-y-2">
          <Label htmlFor="confirmPassword">Confirmar contraseña</Label>
          <Input
            id="confirmPassword"
            type="password"
            placeholder="Repite tu contraseña"
            value={confirmPassword}
            onChange={(e) => setConfirmPassword(e.target.value)}
            required
          />
        </div>
      </div>

      {/* Anti-spam honeypot: hidden from real users, left empty; bots that fill it are rejected. */}
      <div aria-hidden="true" className="pointer-events-none absolute -left-[9999px] h-px w-px overflow-hidden opacity-0">
        <label htmlFor={honeypotField}>No rellenes este campo</label>
        <input
          id={honeypotField}
          name={honeypotField}
          type="text"
          tabIndex={-1}
          autoComplete="off"
          defaultValue=""
        />
      </div>

      {turnstileSiteKey ? (
        <div className="cf-turnstile" data-sitekey={turnstileSiteKey} data-theme="auto" />
      ) : null}

      <Button type="submit" className="w-full" disabled={loading}>
        {loading ? "Creando cuenta..." : "Crear cuenta"}
      </Button>

      <div className="text-center text-sm text-muted-foreground">
        ¿Ya tienes cuenta?{" "}
        <Link href="/login" className="text-primary hover:underline">
          Inicia sesión
        </Link>
      </div>
    </form>
  )
}

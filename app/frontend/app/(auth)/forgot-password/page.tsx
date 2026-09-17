"use client"

import type React from "react"

import Link from "next/link"
import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { fetchLaravelCsrfToken, laravelAuthHeaders, laravelValidationMessage } from "@/lib/laravel-auth"

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState("")
  const [error, setError] = useState("")
  const [status, setStatus] = useState("")
  const [loading, setLoading] = useState(false)

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    setError("")
    setStatus("")
    setLoading(true)

    try {
      const csrfToken = await fetchLaravelCsrfToken("/laravel/forgot-password")
      const response = await fetch("/laravel/forgot-password", {
        body: new URLSearchParams({
          _token: csrfToken,
          email,
        }),
        credentials: "include",
        headers: laravelAuthHeaders(csrfToken),
        method: "POST",
        redirect: "manual",
      })

      if (response.status === 419) {
        setError(await laravelValidationMessage(response, "La sesión de seguridad ha caducado."))
        return
      }

      setStatus("Si existe una cuenta con ese email, enviaremos un enlace para restablecer la contraseña.")
    } catch {
      setError("No se ha podido solicitar el enlace. Inténtalo de nuevo.")
    } finally {
      setLoading(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="w-full max-w-md space-y-6">
      <div className="text-center">
        <h1 className="text-2xl font-semibold text-foreground">Recuperar contraseña</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Indica tu email y te enviaremos un enlace de recuperación si la cuenta existe.
        </p>
      </div>

      {status ? <div className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{status}</div> : null}
      {error ? <div className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">{error}</div> : null}

      <div className="space-y-2">
        <Label htmlFor="email">Email</Label>
        <Input
          id="email"
          type="email"
          placeholder="nombre@empresa.com"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          required
        />
      </div>

      <Button type="submit" className="w-full" disabled={loading}>
        {loading ? "Solicitando enlace..." : "Enviar enlace de recuperación"}
      </Button>

      <div className="text-center">
        <Link href="/login" className="text-sm text-primary hover:underline">
          Volver a iniciar sesión
        </Link>
      </div>
    </form>
  )
}

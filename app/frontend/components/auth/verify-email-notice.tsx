"use client"

import { useState } from "react"
import { useRouter } from "next/navigation"
import { Mail, RefreshCw } from "lucide-react"
import { Button } from "@/components/ui/button"
import { fetchLaravelCsrfToken, laravelAuthHeaders } from "@/lib/laravel-auth"

export function VerifyEmailNotice({ email }: { email: string }) {
  const router = useRouter()
  const [status, setStatus] = useState<"idle" | "sending" | "sent" | "error">("idle")

  const resend = async () => {
    setStatus("sending")

    try {
      const csrfToken = await fetchLaravelCsrfToken("/laravel/email/verification-notification")
      const response = await fetch("/laravel/email/verification-notification", {
        credentials: "include",
        headers: laravelAuthHeaders(csrfToken),
        method: "POST",
        redirect: "manual",
      })

      if (response.ok || response.type === "opaqueredirect" || (response.status >= 300 && response.status < 400)) {
        setStatus("sent")
        return
      }

      setStatus("error")
    } catch {
      setStatus("error")
    }
  }

  return (
    <div className="w-full max-w-md space-y-6 text-center">
      <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-primary/10">
        <Mail className="h-7 w-7 text-primary" aria-hidden="true" />
      </div>

      <div className="space-y-2">
        <h1 className="text-2xl font-semibold text-foreground">Confirma tu correo</h1>
        <p className="text-sm text-muted-foreground">
          Hemos enviado un enlace de confirmación a <span className="font-medium text-foreground">{email}</span>. Abre ese
          correo y pulsa el enlace para activar tu cuenta.
        </p>
      </div>

      <div className="rounded-lg border border-border bg-muted/40 p-4 text-left text-sm text-muted-foreground">
        <p>
          ¿No lo encuentras? Revisa la carpeta de correo no deseado o spam. El enlace puede tardar unos minutos en llegar.
        </p>
      </div>

      {status === "sent" ? (
        <div className="rounded-lg bg-primary/10 p-3 text-sm text-primary">
          Hemos vuelto a enviarte el correo de confirmación.
        </div>
      ) : null}

      {status === "error" ? (
        <div className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
          No hemos podido reenviar el correo. Inténtalo de nuevo en unos minutos.
        </div>
      ) : null}

      <div className="space-y-3">
        <Button type="button" className="w-full" onClick={resend} disabled={status === "sending"}>
          {status === "sending" ? "Enviando..." : "Reenviar correo"}
        </Button>
        <Button type="button" variant="outline" className="w-full" onClick={() => router.refresh()}>
          <RefreshCw className="h-4 w-4" />
          Ya lo he confirmado
        </Button>
      </div>
    </div>
  )
}

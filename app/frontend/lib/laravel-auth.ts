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

function csrfFromHtml(html: string): string | null {
  const match = html.match(/name=["']_token["'][^>]*value=["']([^"']+)["']/)

  return match?.[1] ?? null
}

export async function fetchLaravelCsrfToken(path: "/laravel/login" | "/laravel/register" = "/laravel/login") {
  const response = await fetch(path, {
    credentials: "include",
  })
  const html = await response.text()

  return csrfFromHtml(html) ?? readCookie("XSRF-TOKEN") ?? ""
}

export function laravelAuthHeaders(csrfToken: string): Headers {
  const headers = new Headers({
    Accept: "application/json",
  })

  if (csrfToken) {
    headers.set("X-CSRF-TOKEN", csrfToken)
    headers.set("X-XSRF-TOKEN", csrfToken)
  }

  return headers
}

export async function laravelValidationMessage(response: Response, fallback: string): Promise<string> {
  if (response.status === 419) {
    return "La sesión de seguridad ha caducado. Recarga la página e inténtalo de nuevo."
  }

  if (response.headers.get("Content-Type")?.includes("application/json")) {
    const payload = (await response.json()) as {
      message?: string
      errors?: Record<string, string[]>
    }
    const firstError = payload.errors ? Object.values(payload.errors)[0]?.[0] : null

    return firstError || payload.message || fallback
  }

  return fallback
}

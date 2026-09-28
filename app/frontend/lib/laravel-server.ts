import { headers } from "next/headers"
import type {
  LaravelApiEnvelope,
  LaravelCharacterization,
  LaravelFrontendSession,
  LaravelReportReadiness,
} from "@/lib/laravel-api"
import { resolveLaravelApiTarget } from "@/lib/laravel-server-origin.mjs"

const defaultTimeoutMs = 15000

function cleanApiPath(path: string): string {
  if (path.startsWith("/api/")) {
    return path.slice(4)
  }

  return path.startsWith("/") ? path : `/${path}`
}

function apiTarget(path: string): { target: ReturnType<typeof resolveLaravelApiTarget>; url: string } {
  const target = resolveLaravelApiTarget(
    process.env.LARAVEL_API_ORIGIN,
    process.env.NODE_ENV,
    process.env.LARAVEL_INTERNAL_API_ORIGIN,
    process.env.LARAVEL_CANONICAL_ORIGIN,
  )

  return { target, url: `${target.origin}/api${cleanApiPath(path)}` }
}

async function laravelServerApi<T>(path: string): Promise<LaravelApiEnvelope<T> | null> {
  const incomingHeaders = await headers()
  const { target, url } = apiTarget(path)

  const controller = new AbortController()
  const timeout = setTimeout(() => controller.abort(), defaultTimeoutMs)
  const outboundHeaders = new Headers({
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  })
  outboundHeaders.set("Host", target.host)
  outboundHeaders.set("X-Forwarded-Proto", target.forwardedProto)
  const cookie = incomingHeaders.get("cookie")

  if (cookie) {
    outboundHeaders.set("Cookie", cookie)
  }

  try {
    const response = await fetch(url, {
      cache: "no-store",
      credentials: "include",
      headers: outboundHeaders,
      signal: controller.signal,
    })

    if (response.status === 401 || response.status === 403) {
      return null
    }

    const contentType = response.headers.get("Content-Type") ?? ""
    const responseText = await response.text()

    if (!response.ok) {
      throw new Error(`Platform API request failed with status ${response.status}`)
    }

    if (!contentType.includes("application/json") || !responseText) {
      return null
    }

    return JSON.parse(responseText) as LaravelApiEnvelope<T>
  } finally {
    clearTimeout(timeout)
  }
}

export async function getLaravelServerSession(): Promise<LaravelFrontendSession | null> {
  const response = await laravelServerApi<LaravelFrontendSession>("/auth/session")

  return response?.data ?? null
}

export async function getLaravelServerCharacterization(): Promise<LaravelCharacterization | null> {
  const response = await laravelServerApi<LaravelCharacterization | null>("/characterization")

  return response?.data ?? null
}

export async function getLaravelServerReportReadiness(): Promise<LaravelReportReadiness | null> {
  const response = await laravelServerApi<LaravelReportReadiness | null>("/report")

  return response?.data ?? null
}

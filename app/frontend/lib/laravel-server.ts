import { headers } from "next/headers"
import type {
  LaravelApiEnvelope,
  LaravelCharacterization,
  LaravelFrontendSession,
  LaravelReportReadiness,
} from "@/lib/laravel-api"

const laravelApiOrigin = process.env.LARAVEL_API_ORIGIN?.replace(/\/$/, "")
const defaultTimeoutMs = 15000

type HeaderBag = Awaited<ReturnType<typeof headers>>

function cleanApiPath(path: string): string {
  if (path.startsWith("/api/")) {
    return path.slice(4)
  }

  return path.startsWith("/") ? path : `/${path}`
}

function requestOrigin(incomingHeaders: HeaderBag): string | null {
  const host = incomingHeaders.get("x-forwarded-host") ?? incomingHeaders.get("host")

  if (!host) {
    return null
  }

  const protocol = incomingHeaders.get("x-forwarded-proto") ?? (host.startsWith("localhost") ? "http" : "https")

  return `${protocol}://${host}`
}

function apiUrl(path: string, incomingHeaders: HeaderBag): string | null {
  const base = laravelApiOrigin ?? requestOrigin(incomingHeaders)

  if (!base) {
    return null
  }

  return `${base}/api${cleanApiPath(path)}`
}

async function laravelServerApi<T>(path: string): Promise<LaravelApiEnvelope<T> | null> {
  const incomingHeaders = await headers()
  const url = apiUrl(path, incomingHeaders)

  if (!url) {
    return null
  }

  const controller = new AbortController()
  const timeout = setTimeout(() => controller.abort(), defaultTimeoutMs)
  const outboundHeaders = new Headers({
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  })
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
      throw new Error(`Laravel server API request failed with status ${response.status}`)
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

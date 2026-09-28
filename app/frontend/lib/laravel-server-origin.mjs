function isLoopback(hostname) {
  return hostname === "localhost" || hostname === "127.0.0.1" || hostname === "::1"
}

function absoluteOrigin(value, name) {
  const candidate = value?.trim()
  if (!candidate) {
    throw new Error(`${name} is required for authenticated server-side Laravel requests`)
  }

  let url
  try {
    url = new URL(candidate)
  } catch {
    throw new Error(`${name} must be an absolute HTTP(S) origin`)
  }

  if (
    !["http:", "https:"].includes(url.protocol) ||
    url.username !== "" ||
    url.password !== "" ||
    url.pathname !== "/" ||
    url.search !== "" ||
    url.hash !== ""
  ) {
    throw new Error(`${name} must be an absolute HTTP(S) origin without credentials, path, query, or fragment`)
  }

  return url
}

/**
 * Resolve the only origin allowed to receive the browser's Laravel session
 * cookie from server-side rendering. Request headers are deliberately not an
 * authority for this destination.
 *
 * @param {string | undefined} configuredOrigin
 * @param {string | undefined} nodeEnv
 * @param {string | undefined} configuredInternalOrigin
 * @param {string | undefined} configuredCanonicalOrigin
 * @returns {{origin: string, host: string, forwardedProto: string}}
 */
export function resolveLaravelApiTarget(configuredOrigin, nodeEnv, configuredInternalOrigin, configuredCanonicalOrigin) {
  const legacyOrigin = configuredOrigin?.trim()
  const internalOrigin = configuredInternalOrigin?.trim()
  if (!legacyOrigin && !internalOrigin) {
    throw new Error("LARAVEL_API_ORIGIN or LARAVEL_INTERNAL_API_ORIGIN is required for authenticated server-side Laravel requests")
  }

  const url = absoluteOrigin(legacyOrigin || internalOrigin, legacyOrigin ? "LARAVEL_API_ORIGIN" : "LARAVEL_INTERNAL_API_ORIGIN")
  const production = nodeEnv === "production"

  if (production && url.protocol !== "https:" && !isLoopback(url.hostname)) {
    if (!configuredInternalOrigin) {
      throw new Error("LARAVEL_INTERNAL_API_ORIGIN is required for non-loopback HTTP in production")
    }
    const internal = absoluteOrigin(configuredInternalOrigin, "LARAVEL_INTERNAL_API_ORIGIN")
    if (internal.origin !== url.origin) {
      throw new Error("LARAVEL_API_ORIGIN must exactly match LARAVEL_INTERNAL_API_ORIGIN")
    }
  }

  const canonical = configuredCanonicalOrigin
    ? absoluteOrigin(configuredCanonicalOrigin, "LARAVEL_CANONICAL_ORIGIN")
    : production && url.protocol !== "https:"
      ? null
      : url
  if (!canonical || (production && canonical.protocol !== "https:")) {
    throw new Error("LARAVEL_CANONICAL_ORIGIN must be an absolute HTTPS origin in production")
  }

  return { origin: url.origin, host: canonical.host, forwardedProto: canonical.protocol.slice(0, -1) }
}

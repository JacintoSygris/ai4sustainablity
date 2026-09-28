import assert from "node:assert/strict"
import { existsSync, readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath, pathToFileURL } from "node:url"
import test from "node:test"

const root = dirname(dirname(fileURLToPath(import.meta.url)))

function read(relativePath) {
  return readFileSync(join(root, relativePath), "utf8")
}

test("dashboard and wizard layouts use Laravel session, not Better Auth", () => {
  for (const relativePath of ["app/(dashboard)/layout.tsx", "app/(dashboard)/wizard/layout.tsx"]) {
    const source = read(relativePath)

    assert.doesNotMatch(source, /@\/lib\/auth/, `${relativePath} must not import Better Auth`)
    assert.doesNotMatch(source, /auth\.api\.getSession/, `${relativePath} must not call Better Auth session APIs`)
    assert.match(source, /getLaravelServerSession/, `${relativePath} must load the Laravel browser session`)
    assert.match(source, /redirect\(["']\/login["']\)/, `${relativePath} must redirect unauthenticated users`)
  }
})

test("dashboard page derives progress from Laravel workflow state", () => {
  const source = read("app/(dashboard)/dashboard/page.tsx")

  assert.doesNotMatch(source, /@\/lib\/auth/, "dashboard page must not import Better Auth")
  assert.doesNotMatch(source, /@\/lib\/queries/, "dashboard page must not import local Turso report queries")
  assert.doesNotMatch(source, /getUserReport|getActiveReport/, "dashboard page must not read local report state")
  assert.match(source, /getLaravelServerCharacterization/, "dashboard page must read Laravel characterization")
  assert.match(source, /getLaravelServerReportReadiness/, "dashboard page must read Laravel report readiness")
  assert.match(
    source,
    /sections\.double_materiality_guide\?\.status/,
    "dashboard progress must stop at Step 3 while the double-materiality guide is incomplete",
  )
})

test("dashboard header logs out through Laravel, not Better Auth client", () => {
  const source = read("components/dashboard/dashboard-header.tsx")

  assert.doesNotMatch(source, /authClient|signOut/, "dashboard header must not use Better Auth client logout")
  assert.match(source, /fetch\(["']\/logout["']/, "dashboard header must post to Laravel logout")
  assert.match(source, /X-XSRF-TOKEN|X-CSRF-TOKEN/, "dashboard header must send Laravel CSRF token")
})

test("logout distinguishes signed-out 401 from retryable non-401 failures", async () => {
  const { runLaravelLogout } = await import(pathToFileURL(join(root, "lib/laravel-logout.mjs")).href)
  const failure = (status) => Object.assign(new Error(`HTTP ${status}`), { status })

  assert.deepEqual(
    await runLaravelLogout({ refreshSession: async () => { throw failure(401) }, postLogout: async () => ({ ok: true, status: 204 }) }),
    { outcome: "signed_out" },
  )
  assert.deepEqual(
    await runLaravelLogout({ refreshSession: async () => { throw failure(500) }, postLogout: async () => ({ ok: true, status: 204 }) }),
    { outcome: "retryable_error", stage: "session" },
  )
  assert.deepEqual(
    await runLaravelLogout({ refreshSession: async () => ({ csrfToken: "token" }), postLogout: async () => ({ ok: false, status: 401 }) }),
    { outcome: "signed_out" },
  )
  assert.deepEqual(
    await runLaravelLogout({ refreshSession: async () => ({ csrfToken: "token" }), postLogout: async () => ({ ok: false, status: 500 }) }),
    { outcome: "retryable_error", stage: "logout" },
  )
  assert.deepEqual(
    await runLaravelLogout({ refreshSession: async () => ({ csrfToken: "token" }), postLogout: async () => { throw new Error("network") } }),
    { outcome: "retryable_error", stage: "logout" },
  )
})

test("server Laravel helper and Next rewrites cover dashboard session and web logout", () => {
  const helperPath = "lib/laravel-server.ts"

  assert.equal(existsSync(join(root, helperPath)), true, `${helperPath} must exist`)

  const helper = read(helperPath)
  const nextConfig = read("next.config.mjs")

  assert.match(helper, /headers\(\)/, "server helper must forward incoming Next headers")
  assert.match(helper, /cookie/i, "server helper must forward browser cookies")
  assert.doesNotMatch(helper, /authorization|Authorization/, "server helper must not depend on Apache Basic Auth")
  assert.match(helper, /auth\/session/, "server helper must call Laravel session endpoint")
  assert.match(nextConfig, /source:\s*["']\/logout["']/, "Next must proxy Laravel logout")
  assert.match(nextConfig, /source:\s*["']\/characterization\/:path\*["']/, "Next must proxy Laravel PDF route")
})

test("server Laravel calls never derive their credentialed destination from request headers", async () => {
  const originHelperPath = join(root, "lib/laravel-server-origin.mjs")
  assert.equal(existsSync(originHelperPath), true, "a testable fail-closed origin helper must exist")

  const { resolveLaravelApiTarget } = await import(pathToFileURL(originHelperPath).href)

  assert.throws(() => resolveLaravelApiTarget(undefined, "production"), /LARAVEL_API_ORIGIN/)
  assert.throws(
    () => resolveLaravelApiTarget("http://api.example.test", "production", undefined, "https://app.example.test"),
    /LARAVEL_INTERNAL_API_ORIGIN/,
  )
  assert.deepEqual(
    resolveLaravelApiTarget(
      "http://laravel:8080/",
      "production",
      "http://laravel:8080",
      "https://app.example.test",
    ),
    { origin: "http://laravel:8080", host: "app.example.test", forwardedProto: "https" },
  )
  assert.deepEqual(
    resolveLaravelApiTarget(undefined, "production", "http://laravel:8080", "https://app.example.test"),
    { origin: "http://laravel:8080", host: "app.example.test", forwardedProto: "https" },
    "separate Jenkins deployments may configure the explicit internal/canonical pair without the legacy alias",
  )
  assert.deepEqual(
    resolveLaravelApiTarget("http://127.0.0.1:8080/", "production", undefined, "https://app.example.test"),
    { origin: "http://127.0.0.1:8080", host: "app.example.test", forwardedProto: "https" },
  )
  assert.deepEqual(
    resolveLaravelApiTarget("https://api.example.test/", "production", undefined, "https://app.example.test"),
    { origin: "https://api.example.test", host: "app.example.test", forwardedProto: "https" },
  )
  assert.deepEqual(
    resolveLaravelApiTarget("https://app.example.test/", "production"),
    { origin: "https://app.example.test", host: "app.example.test", forwardedProto: "https" },
    "an existing canonical HTTPS production origin remains self-describing",
  )
  assert.throws(
    () => resolveLaravelApiTarget("http://laravel:8080", "production", "http://other:8080", "https://app.example.test"),
    /exactly match/,
  )
  assert.throws(
    () => resolveLaravelApiTarget("http://laravel:8080", "production", "http://laravel:8080", "http://app.example.test"),
    /LARAVEL_CANONICAL_ORIGIN/,
  )

  const helper = read("lib/laravel-server.ts")
  assert.doesNotMatch(helper, /x-forwarded-host|requestOrigin/, "SSR must not derive an API origin from request headers")
  assert.match(helper, /resolveLaravelApiTarget/, "SSR must use the validated configured target")
  assert.match(helper, /outboundHeaders\.set\("Host", target\.host\)/, "SSR must send the canonical Host")
  assert.match(helper, /X-Forwarded-Proto/, "SSR must preserve the canonical HTTPS scheme")
})

test("Next frontend does not carry project-level Basic Auth", () => {
  const packageJson = read("package.json")
  const envExample = read(".env.example")

  assert.match(packageJson, /"next":\s*"16\./, "frontend remains on Next 16")
  assert.equal(existsSync(join(root, "proxy.ts")), false, "proxy.ts must not enforce a separate auth gate")
  assert.doesNotMatch(envExample, /BASIC_AUTH|I4S_DISABLE_BASIC_AUTH/, "frontend env example must not advertise Basic Auth")
})

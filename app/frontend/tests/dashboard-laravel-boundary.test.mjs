import assert from "node:assert/strict"
import { existsSync, readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
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
})

test("dashboard header logs out through Laravel, not Better Auth client", () => {
  const source = read("components/dashboard/dashboard-header.tsx")

  assert.doesNotMatch(source, /authClient|signOut/, "dashboard header must not use Better Auth client logout")
  assert.match(source, /fetch\(["']\/logout["']/, "dashboard header must post to Laravel logout")
  assert.match(source, /X-XSRF-TOKEN|X-CSRF-TOKEN/, "dashboard header must send Laravel CSRF token")
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

test("Next frontend does not carry project-level Basic Auth", () => {
  const packageJson = read("package.json")
  const envExample = read(".env.example")

  assert.match(packageJson, /"next":\s*"16\./, "frontend remains on Next 16")
  assert.equal(existsSync(join(root, "proxy.ts")), false, "proxy.ts must not enforce a separate auth gate")
  assert.doesNotMatch(envExample, /BASIC_AUTH|I4S_DISABLE_BASIC_AUTH/, "frontend env example must not advertise Basic Auth")
})

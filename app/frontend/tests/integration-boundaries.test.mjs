import assert from "node:assert/strict"
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"

const root = dirname(dirname(fileURLToPath(import.meta.url)))

function read(relativePath) {
  return readFileSync(join(root, relativePath), "utf8")
}

function listFiles(relativeDir, predicate = () => true, options = {}) {
  const base = join(root, relativeDir)
  if (!existsSync(base)) {
    return []
  }

  const excludeArchive = options.excludeArchive ?? true
  const files = []
  const visit = (dir) => {
    for (const entry of readdirSync(dir)) {
      const fullPath = join(dir, entry)
      const relativePath = fullPath.slice(root.length + 1).replaceAll("\\", "/")

      if ((excludeArchive && relativePath.startsWith("archive/")) || relativePath.startsWith(".next/") || relativePath.startsWith("node_modules/")) {
        continue
      }

      if (statSync(fullPath).isDirectory()) {
        visit(fullPath)
      } else if (predicate(relativePath)) {
        files.push(relativePath)
      }
    }
  }

  visit(base)
  return files
}

test("Phase 2 Laravel API client is same-origin by default and CSRF-capable", () => {
  const relativePath = "lib/laravel-api.ts"

  assert.equal(existsSync(join(root, relativePath)), true, `${relativePath} must exist`)

  const source = read(relativePath)

  assert.match(source, /\/api/, "client must default to same-origin /api")
  assert.match(source, /credentials:\s*["']include["']/, "client must include browser credentials")
  assert.match(source, /auth\/session/, "client must expose the Laravel frontend session endpoint")
  assert.match(source, /getLaravelSession/, "client must provide a typed session bootstrap helper")
  assert.match(source, /headers\.set\(["']Accept["'][\s\S]*application\/json/, "client must request JSON to avoid Laravel HTML redirects")
  assert.match(source, /X-Requested-With/, "client must mark browser API calls as XMLHttpRequest")
  assert.match(source, /X-CSRF-TOKEN|X-XSRF-TOKEN/, "client must support Laravel CSRF headers")
  assert.match(source, /AbortController|timeout/i, "client must define request timeout behavior")
})

test("Next local dev can reserve /api/* for Laravel through rewrites", () => {
  const source = read("next.config.mjs")

  assert.match(source, /LARAVEL_API_ORIGIN/, "config must read LARAVEL_API_ORIGIN")
  assert.match(source, /async\s+rewrites\s*\(/, "config must define rewrites")
  assert.match(source, /source:\s*["']\/api\/:path\*["']/, "config must reserve /api/:path*")
  assert.match(source, /destination:\s*.*\/api\/:path\*/, "rewrite destination must preserve Laravel /api path")
})

test("imported Next backend routes are retired from active Next API paths", () => {
  const retiredRouteFiles = [
    "app/api/auth/[...all]/route.ts",
    "app/api/user/onboarding/route.ts",
    "app/api/wizard/reset-step/route.ts",
    "app/api/wizard/step-1/route.ts",
    "app/api/wizard/step-2/route.ts",
    "app/api/wizard/step-3/route.ts",
    "app/api/wizard/step-4/route.ts",
  ]

  for (const file of retiredRouteFiles) {
    assert.equal(existsSync(join(root, file)), false, `${file} must not remain routable in active Next app/api`)
  }
})

test("imported Better Auth Turso Drizzle helpers and scripts are superseded outside active paths", () => {
  const retiredActiveFiles = [
    "lib/auth-client.ts",
    "lib/auth.ts",
    "lib/db.ts",
    "lib/queries.ts",
    "lib/schema.ts",
    "lib/local-next-backend.ts",
    "scripts/run-migrations.js",
    "scripts/001-create-auth-tables.sql",
    "scripts/002-fix-auth-tables.sql",
    "scripts/003-create-reports-table.sql",
    "scripts/004-add-onboarding-fields.sql",
    "scripts/005-add-survey-data.sql",
  ]

  for (const file of retiredActiveFiles) {
    assert.equal(existsSync(join(root, file)), false, `${file} must not remain active`)
  }

  const archivedFiles = listFiles("archive/2026-06-06-imported-next-backend", () => true, { excludeArchive: false })

  assert.equal(archivedFiles.length, 19, "retired backend source must be preserved as archived files")

  for (const file of archivedFiles) {
    assert.doesNotMatch(file, /\.(ts|tsx)$/)
  }
})

test("active frontend source no longer imports retired backend packages or helpers", () => {
  const activeFiles = [
    ...listFiles("app", (file) => /\.(ts|tsx|js|mjs)$/.test(file)),
    ...listFiles("components", (file) => /\.(ts|tsx|js|mjs)$/.test(file)),
    ...listFiles("lib", (file) => /\.(ts|tsx|js|mjs)$/.test(file)),
    ...listFiles("scripts", (file) => /\.(ts|tsx|js|mjs)$/.test(file)),
  ]

  const forbiddenImport =
    /from\s+["'](?:better-auth(?:\/[^"']*)?|drizzle-orm(?:\/[^"']*)?|@libsql\/client|@\/lib\/(?:auth|auth-client|db|queries|schema|local-next-backend))["']|import\(["'](?:better-auth(?:\/[^"']*)?|drizzle-orm(?:\/[^"']*)?|@libsql\/client|@\/lib\/(?:auth|auth-client|db|queries|schema|local-next-backend))["']\)/

  for (const file of activeFiles) {
    assert.doesNotMatch(read(file), forbiddenImport, `${file} must not import the retired local backend`)
  }
})

test("environment example exposes only active Laravel integration variables without real values", () => {
  const source = read(".env.example")

  assert.match(source, /^LARAVEL_API_ORIGIN=/m)
  assert.match(source, /^NEXT_PUBLIC_LARAVEL_API_BASE_URL=/m)
  assert.doesNotMatch(source, /^I4S_AUTH_PROVIDER=/m)
  assert.doesNotMatch(source, /^I4S_ENABLE_LOCAL_NEXT_BACKEND=/m)
  assert.doesNotMatch(source, /^BETTER_AUTH_SECRET=/m)
  assert.doesNotMatch(source, /^TURSO_AUTH_TOKEN=/m)
  assert.doesNotMatch(source, /^TURSO_DATABASE_URL=/m)
  assert.doesNotMatch(source, /=.+\S/, "example env must list names only, not real values")
})

test("package manifest has no direct retired local backend dependencies", () => {
  const packageJson = JSON.parse(read("package.json"))

  assert.equal(packageJson.dependencies?.["better-auth"], undefined)
  assert.equal(packageJson.dependencies?.["drizzle-orm"], undefined)
  assert.equal(packageJson.dependencies?.["@libsql/client"], undefined)
})

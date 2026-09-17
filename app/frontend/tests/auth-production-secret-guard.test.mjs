import assert from "node:assert/strict"
import { existsSync, readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"

const root = dirname(dirname(fileURLToPath(import.meta.url)))

function read(relativePath) {
  return readFileSync(join(root, relativePath), "utf8")
}

test("retired Better Auth production secret contract is absent from active frontend", () => {
  assert.equal(existsSync(join(root, "lib/auth.ts")), false, "retired Better Auth server helper must not be active")

  const envExample = read(".env.example")
  const packageJson = JSON.parse(read("package.json"))

  assert.doesNotMatch(envExample, /^BETTER_AUTH_SECRET=/m)
  assert.doesNotMatch(envExample, /^TURSO_AUTH_TOKEN=/m)
  assert.doesNotMatch(envExample, /^TURSO_DATABASE_URL=/m)
  assert.doesNotMatch(envExample, /^I4S_ENABLE_LOCAL_NEXT_BACKEND=/m)
  assert.doesNotMatch(envExample, /^I4S_AUTH_PROVIDER=/m)
  assert.equal(packageJson.dependencies?.["better-auth"], undefined)
  assert.equal(packageJson.dependencies?.["@libsql/client"], undefined)
  assert.equal(packageJson.dependencies?.["drizzle-orm"], undefined)
})

import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"

const root = dirname(dirname(fileURLToPath(import.meta.url)))

function read(relativePath) {
  return readFileSync(join(root, relativePath), "utf8")
}

test("login form posts credentials to Laravel auth, not Better Auth", () => {
  const source = read("components/auth/login-form.tsx")

  assert.doesNotMatch(source, /authClient|signIn\.email|better-auth/, "login must not use Better Auth client")
  assert.match(source, /fetchLaravelCsrfToken/, "login must fetch a Laravel CSRF token")
  assert.match(source, /fetch\(["']\/laravel\/login["']/, "login must post through the Laravel login proxy")
  assert.match(source, /_token/, "login must include the Laravel form token")
})

test("register form posts account creation to Laravel auth, not Better Auth or local onboarding state", () => {
  const source = read("components/auth/register-form.tsx")

  assert.doesNotMatch(source, /authClient|signUp\.email|better-auth/, "register must not use Better Auth client")
  assert.doesNotMatch(source, /\/api\/user\/onboarding/, "register must not save onboarding through local Next backend")
  assert.match(source, /fetchLaravelCsrfToken/, "register must fetch a Laravel CSRF token")
  assert.match(source, /fetch\(["']\/laravel\/register["']/, "register must post through the Laravel register proxy")
  assert.match(source, /password_confirmation/, "register must use Laravel's password confirmation field")
})

test("Next rewrites expose dedicated Laravel auth form proxies without replacing Next pages", () => {
  const source = read("next.config.mjs")

  assert.match(source, /source:\s*["']\/laravel\/login["']/, "Next local rewrite must expose the Laravel /laravel/login alias")
  assert.match(source, /destination:\s*.*\/laravel\/login/, "login proxy must target the Laravel login alias")
  assert.match(source, /source:\s*["']\/laravel\/register["']/, "Next local rewrite must expose the Laravel /laravel/register alias")
  assert.match(source, /destination:\s*.*\/laravel\/register/, "register proxy must target the Laravel register alias")
})

// Apache deployment invariant: the live vhost must keep /laravel/login and
// /laravel/register excluded from the Next proxy. Laravel owns those aliases so
// browser CSRF and auth submits bypass the public /login and /register Next pages.

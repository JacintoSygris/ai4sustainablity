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

test("auth forms fetch a fresh Laravel CSRF form token for each submit", () => {
  const source = read("lib/laravel-auth.ts")

  assert.match(source, /cache:\s*["']no-store["']/, "CSRF form fetches must not reuse stale Laravel auth HTML")
  assert.match(source, /credentials:\s*["']include["']/, "CSRF form fetches must include the Laravel session cookies")
})

test("auth pages redirect already-authenticated Laravel sessions", () => {
  const source = read("app/(auth)/layout.tsx")

  assert.match(source, /getLaravelServerSession/, "auth layout must check Laravel's current session")
  assert.match(source, /redirect\(["']\/dashboard["']\)/, "authenticated users must not see stale login/register forms")
})

test("logout uses the encrypted XSRF cookie as Laravel's X-XSRF-TOKEN header", () => {
  const source = read("components/dashboard/dashboard-header.tsx")

  assert.match(source, /readCookie\(["']XSRF-TOKEN["']\)/, "logout must read the Laravel XSRF cookie")
  assert.match(source, /headers\.set\(["']X-XSRF-TOKEN["'], csrfToken\)/, "logout must send cookie CSRF through X-XSRF-TOKEN")
  assert.doesNotMatch(
    source,
    /headers\.set\(["']X-CSRF-TOKEN["'], csrfToken\)/,
    "logout must not send encrypted XSRF cookie through the plain X-CSRF-TOKEN header",
  )
})

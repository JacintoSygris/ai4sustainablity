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

test("forgot password form requests Laravel reset links through the auth proxy", () => {
  const source = read("app/(auth)/forgot-password/page.tsx")

  assert.doesNotMatch(source, /no está activada|no est.a activada|restablezca tu acceso/i, "forgot-password page must not be a disabled placeholder")
  assert.match(source, /fetchLaravelCsrfToken/, "forgot-password form must fetch a Laravel CSRF token")
  assert.match(source, /fetch\(["']\/laravel\/forgot-password["']/, "forgot-password form must post through the Laravel reset proxy")
  assert.match(source, /_token/, "forgot-password form must include the Laravel form token")
  assert.match(source, /setStatus/, "forgot-password form must show the broker response without leaking account existence")
})

test("Next rewrites expose dedicated Laravel auth form proxies without replacing Next pages", () => {
  const source = read("next.config.mjs")

  assert.match(source, /source:\s*["']\/laravel\/login["']/, "Next local rewrite must expose the Laravel /laravel/login alias")
  assert.match(source, /destination:\s*.*\/laravel\/login/, "login proxy must target the Laravel login alias")
  assert.match(source, /source:\s*["']\/laravel\/register["']/, "Next local rewrite must expose the Laravel /laravel/register alias")
  assert.match(source, /destination:\s*.*\/laravel\/register/, "register proxy must target the Laravel register alias")
  assert.match(source, /source:\s*["']\/laravel\/forgot-password["']/, "Next local rewrite must expose the Laravel /laravel/forgot-password alias")
  assert.match(source, /destination:\s*.*\/laravel\/forgot-password/, "forgot-password proxy must target the Laravel forgot-password alias")
  assert.match(source, /source:\s*["']\/profile["']/, "Next local rewrite must expose the Laravel /profile account page")
  assert.match(source, /destination:\s*.*\/profile/, "profile proxy must target Laravel profile management")
  assert.match(source, /source:\s*["']\/build\/:path\*["']/, "Laravel Vite assets must stay same-origin behind the Next proxy")
  assert.match(source, /destination:\s*.*\/build\/:path\*/, "Laravel Vite asset proxy must target the Laravel build assets")
})

// Routing invariant: /laravel/login and /laravel/register must remain Laravel
// aliases so browser CSRF and auth submits bypass the public Next pages.

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

test("logout refreshes Laravel CSRF before posting to Laravel logout", () => {
  const source = read("components/dashboard/dashboard-header.tsx")

  assert.match(source, /getLaravelSession/, "logout must refresh the Laravel session metadata before posting")
  assert.match(source, /session\.data\.csrf_token/, "logout must use the fresh Laravel CSRF token from the session endpoint")
  assert.match(source, /readCookie\(["']XSRF-TOKEN["']\)/, "logout must read the Laravel XSRF cookie")
  assert.match(source, /headers\.set\(["']X-CSRF-TOKEN["'], csrfToken\)/, "logout must send the fresh Laravel CSRF token through X-CSRF-TOKEN")
})

test("settings page shows the Laravel session and links to real account management", () => {
  const source = read("app/(dashboard)/settings/page.tsx")

  assert.doesNotMatch(source, /gesti.n avanzada de cuenta no est. activada|release privada/i, "settings must not be a disabled placeholder")
  assert.match(source, /getLaravelServerSession/, "settings must read the Laravel session")
  assert.match(source, /session\.user\.email/, "settings must show the authenticated user's email")
  assert.match(source, /href=["']\/profile["']/, "settings must link to the real Laravel profile page")
})

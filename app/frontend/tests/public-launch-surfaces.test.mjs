import assert from "node:assert/strict"
import { existsSync, readFileSync } from "node:fs"
import { join } from "node:path"
import test from "node:test"

function read(relativePath) {
  return readFileSync(join(process.cwd(), relativePath), "utf8")
}

test("privacy page exists with GDPR rights, retention and external-AI clauses", () => {
  const path = "app/(legal)/privacy/page.tsx"
  assert.equal(existsSync(join(process.cwd(), path)), true)

  const source = read(path)

  assert.match(source, /Borrador pendiente de revisión legal/)
  assert.match(source, /\[PENDIENTE: datos del responsable\]/)
  // Data categories collected.
  assert.match(source, /Datos de tu cuenta/)
  assert.match(source, /Respuestas de caracterización/)
  assert.match(source, /Documentos que subes/)
  // Uploaded documents analysed only on the platform's own server, never external AI.
  assert.match(source, /propio servidor de la plataforma/)
  assert.match(source, /No enviamos tus documentos/)
  assert.match(source, /inteligencia artificial externos/)
  // Retention + full purge on deletion.
  assert.match(source, /se elimina por completo/)
  assert.match(source, /borramos de forma definitiva/)
  // GDPR rights.
  assert.match(source, /RGPD/)
  assert.match(source, /Acceso/)
  assert.match(source, /Rectificación/)
  assert.match(source, /Supresión/)
  assert.match(source, /Portabilidad/)
})

test("terms page exists with the not-official-filing limits and draft marker", () => {
  const path = "app/(legal)/terms/page.tsx"
  assert.equal(existsSync(join(process.cwd(), path)), true)

  const source = read(path)

  assert.match(source, /Borrador pendiente de revisión legal/)
  assert.match(source, /\[PENDIENTE: datos del responsable\]/)
  assert.match(source, /No es una presentación oficial/)
  assert.match(source, /No es un servicio de aseguramiento/)
})

test("footer links to both legal pages", () => {
  const source = read("components/ui/footer.tsx")

  assert.match(source, /href="\/privacy"/)
  assert.match(source, /href="\/terms"/)
})

test("cookie consent banner is minimal, honest and persisted", () => {
  const path = "components/ui/cookie-consent.tsx"
  assert.equal(existsSync(join(process.cwd(), path)), true)

  const source = read(path)

  assert.match(source, /solo cookies necesarias para iniciar sesión/)
  assert.match(source, /localStorage/)
  // Rendered from the root layout.
  const layout = read("app/layout.tsx")
  assert.match(layout, /<CookieConsent \/>/)
})

test("register form renders a hidden honeypot consumed from the register config", () => {
  const source = read("components/auth/register-form.tsx")

  // Config is fetched and the honeypot name comes from it.
  assert.match(source, /getLaravelRegisterConfig/)
  assert.match(source, /config\.honeypot_field/)
  assert.match(source, /name=\{honeypotField\}/)
  // Hidden, off-screen, not focusable, not type=hidden, always empty for real users.
  assert.match(source, /aria-hidden="true"/)
  assert.match(source, /tabIndex=\{-1\}/)
  assert.match(source, /-left-\[9999px\]/)
  assert.match(source, /defaultValue=""/)
  assert.doesNotMatch(source, /name=\{honeypotField\}[^>]*type="hidden"/)
})

test("register form loads the Turnstile widget only when a site key is present", () => {
  const source = read("components/auth/register-form.tsx")

  assert.match(source, /challenges\.cloudflare\.com\/turnstile\/v0\/api\.js/)
  assert.match(source, /cf-turnstile/)
  assert.match(source, /data-sitekey=\{turnstileSiteKey\}/)
  assert.match(source, /cf-turnstile-response/)
  // Widget and script are gated behind the presence of the key.
  assert.match(source, /turnstileSiteKey \? <Script/)
})

test("verify-email screen exists with a resend action and spam note", () => {
  const pagePath = "app/verify-email/page.tsx"
  const noticePath = "components/auth/verify-email-notice.tsx"
  assert.equal(existsSync(join(process.cwd(), pagePath)), true)
  assert.equal(existsSync(join(process.cwd(), noticePath)), true)

  const notice = read(noticePath)

  assert.match(notice, /Reenviar correo/)
  assert.match(notice, /\/laravel\/email\/verification-notification/)
  assert.match(notice, /spam/)
})

test("session-based verification gate and 409 handling are wired", () => {
  const dashboardLayout = read("app/(dashboard)/layout.tsx")
  assert.match(dashboardLayout, /require_email_verification/)
  assert.match(dashboardLayout, /redirect\("\/verify-email"\)/)

  const api = read("lib/laravel-api.ts")
  assert.match(api, /isEmailUnverifiedError/)
  assert.match(api, /email_unverified/)
  assert.match(api, /\/verify-email/)
})

test("P10 report surface shows a prominent scope disclaimer", () => {
  const source = read("components/wizard/report-draft-panel.tsx")

  assert.match(source, /Alcance y límites de este informe/)
  assert.match(source, /No es una presentación oficial/)
  assert.match(source, /No es un servicio de aseguramiento/)
  assert.match(source, /Taxonomía de la UE/)
  assert.match(source, /xHTML ni iXBRL/)
})

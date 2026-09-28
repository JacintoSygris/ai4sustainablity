import assert from "node:assert/strict"
import { createHash } from "node:crypto"
import { existsSync, readFileSync } from "node:fs"
import { join } from "node:path"
import test from "node:test"

function read(relativePath) {
  return readFileSync(join(process.cwd(), relativePath), "utf8")
}

test("privacy page exists with production legal identity, GDPR rights and local document analysis", () => {
  const path = "app/(legal)/privacy/page.tsx"
  assert.equal(existsSync(join(process.cwd(), path)), true)

  const source = read(path)

  assert.doesNotMatch(source, /Borrador pendiente de revisión legal|Última actualización: pendiente de publicación|\[PENDIENTE:/)
  assert.match(source, /26\/09\/2026/)
  assert.match(source, /CAMBRIDGE BUSINESS INITIATIVES, S\.L\./)
  assert.match(source, /B85512895/)
  assert.match(source, /C\/Teide 4/)
  assert.match(source, /\+34 91 623 73 84/)
  assert.match(source, /mailto:dpo@sygris\.com/)
  assert.match(source, /mailto:dpo@cbiconsulting\.es/)
  assert.match(source, /sygris\.com\/legal\/aviso-legal\//)
  assert.match(source, /sygris\.com\/legal\/politica-privacidad\//)
  assert.match(source, /Google.*Microsoft|Microsoft.*Google/s)
  assert.match(source, /https:\/\/www\.aepd\.es\//)
  // Data categories collected.
  assert.match(source, /Datos de tu cuenta/)
  assert.match(source, /Respuestas de caracterización/)
  assert.match(source, /Documentos que subes/)
  // Uploaded documents analysed only on the platform's own server, never external AI.
  assert.match(source, /propio servidor de la plataforma/)
  assert.match(source, /No enviamos tus documentos/)
  assert.match(source, /inteligencia artificial externos/)
  // Deletion distinguishes a temporary 409 rejection from accepted asynchronous removal.
  assert.match(source, /extrayendo.*termine|termine.*extracción/s)
  assert.match(source, /baja se acepta.*eliminación física/s)
  assert.match(source, /identificador.*fecha.*temas/s)
  assert.match(source, /citas.*páginas/s)
  // GDPR rights.
  assert.match(source, /RGPD/)
  assert.match(source, /Acceso/)
  assert.match(source, /Rectificación/)
  assert.match(source, /Supresión/)
  assert.match(source, /Portabilidad/)
})

test("terms page exists with the not-official-filing limits and production legal identity", () => {
  const path = "app/(legal)/terms/page.tsx"
  assert.equal(existsSync(join(process.cwd(), path)), true)

  const source = read(path)

  assert.doesNotMatch(source, /Borrador pendiente de revisión legal|Última actualización: pendiente de publicación|\[PENDIENTE:/)
  assert.match(source, /26\/09\/2026/)
  assert.match(source, /CAMBRIDGE BUSINESS INITIATIVES, S\.L\./)
  assert.match(source, /B85512895/)
  assert.match(source, /No es una presentación oficial/)
  assert.match(source, /No es un servicio de aseguramiento/)
  assert.match(source, /política de privacidad.*solicitar/s)
  assert.doesNotMatch(source, /<h2[^>]*>Contacto[\s\S]*No es una presentación oficial/)
})

test("footer links to both legal pages", () => {
  const source = read("components/ui/footer.tsx")

  assert.match(source, /href="\/privacy"/)
  assert.match(source, /href="\/terms"/)
})

test("both footers use the official funding marks and preserve the grant identifiers", () => {
  const next = read("components/ui/footer.tsx")
  const blade = read(join("..", "web", "resources/views/layouts/guest.blade.php"))
  for (const source of [next, blade]) {
    assert.match(source, /comunidad-madrid-positivo\.png/)
    assert.match(source, /fondos-europeos-oficial\.jpg/)
    assert.match(source, /ue-cofinanciado-oficial\.png/)
    assert.match(source, /IA4SustainabilityReport/)
    assert.match(source, /09-PYN1-00054\.1\/2023/)
    assert.match(source, /Comunidad de Madrid/)
    assert.match(source, /Unión Europea|FEDER/)
    assert.doesNotMatch(source, /madrid-region-logo\.jpg|european-funds-logo\.jpg|eu-flag-cofinanced\.jpg/)
  }
  for (const asset of [
    ["public/funding/pymes-2023/comunidad-madrid-positivo.png", "6b1b890b9ab70d1e3ad8c906a065738986798c90f71dc07cb660cd8d5e3ef835"],
    ["public/funding/pymes-2023/fondos-europeos-oficial.jpg", "f791b4a6cc2422a49a5aa776489b0644928289c980c8242a99382bc59cc48d94"],
    ["public/funding/pymes-2023/ue-cofinanciado-oficial.png", "c1f4f7e9e9e9ec46e6446a849f7c19f4bbfd292c11eb44230b3c30cc4e34e56c"],
  ]) {
    const absolute = join(process.cwd(), asset[0])
    assert.equal(existsSync(absolute), true)
    assert.equal(createHash("sha256").update(readFileSync(absolute)).digest("hex"), asset[1])
  }
  for (const asset of [
    ["../web/public/funding/pymes-2023/comunidad-madrid-positivo.png", "6b1b890b9ab70d1e3ad8c906a065738986798c90f71dc07cb660cd8d5e3ef835"],
    ["../web/public/funding/pymes-2023/fondos-europeos-oficial.jpg", "f791b4a6cc2422a49a5aa776489b0644928289c980c8242a99382bc59cc48d94"],
    ["../web/public/funding/pymes-2023/ue-cofinanciado-oficial.png", "c1f4f7e9e9e9ec46e6446a849f7c19f4bbfd292c11eb44230b3c30cc4e34e56c"],
  ]) {
    const absolute = join(process.cwd(), asset[0])
    assert.equal(existsSync(absolute), true)
    assert.equal(createHash("sha256").update(readFileSync(absolute)).digest("hex"), asset[1])
  }
  assert.match(next, /gap-16/)
  assert.match(blade, /gap-16/)
  assert.match(next, /py-16/)
  assert.match(blade, /py-16/)
  assert.match(next, /h-14/)
  assert.match(blade, /h-14/)
  assert.match(next, /w-\[320px\].*max-w-full/s)
  assert.match(blade, /w-\[320px\].*max-w-full/s)
})

test("cookie consent mounts the shared UI after hydration and links to its inventory", () => {
  const path = "components/ui/cookie-consent.tsx"
  assert.equal(existsSync(join(process.cwd(), path)), true)

  const source = read(path)

  assert.match(source, /useEffect/)
  assert.match(source, /mountConsent\(host.current, api\)/)
  const ui = read("public/consent/ui.mjs")
  assert.match(ui, /cookies necesarias/)
  assert.match(ui, /no utilizamos analítica ni publicidad/)
  const inventory = read("app/(legal)/cookies/page.tsx")
  assert.match(inventory, /Google.*Microsoft|Microsoft.*Google/s)
  assert.match(inventory, /Cerrar sesión/)
  assert.match(inventory, /datos del sitio desde\s+la configuración del navegador/)
  const privacy = read("app/(legal)/privacy/page.tsx")
  assert.match(privacy, /borrado[^.]*fallar[^.]*datos del sitio/s)
  // Rendered from the root layout.
  const layout = read("app/layout.tsx")
  assert.match(layout, /<CookieConsent \/>/)
})

test("document deletion distinguishes extraction conflict from accepted pending removal", () => {
  const controller = read(join("..", "web", "app/Http/Controllers/Api/CharacterizationDocumentController.php"))
  assert.match(controller, /abort_if\([\s\S]*STATUS_EXTRACTING[\s\S]*409/)
  assert.match(controller, /purge_status.*pending/)
  assert.match(controller, /deleted.*true/)
})

test("local browser drafts are cleared after save, not by logout", () => {
  const p9 = read("components/wizard/esrs-datapoints-form.tsx")
  const dashboard = read("components/dashboard/dashboard-header.tsx")
  assert.match(p9, /recoveryStorage\.removeItem\(localStorageDraftKey/)
  assert.doesNotMatch(dashboard, /localStorage\.(removeItem|clear)\(/)
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

test("register form delegates to the explicitly activated shared security control", () => {
  const source = read("components/auth/register-form.tsx")

  assert.match(source, /mountSecurityCheck/)
  assert.match(source, /enabled: registrationEnabled/)
  assert.match(source, /siteKey: turnstileSiteKey/)
  assert.match(source, /cf-turnstile-response/)
  // Widget and script are gated behind the presence of the key.
  assert.doesNotMatch(source, /<Script/)
})

test("register form fails closed until the backend explicitly enables registration", () => {
  const source = read("components/auth/register-form.tsx")
  const apiSource = read("lib/laravel-api.ts")

  assert.match(apiSource, /registration_enabled: boolean/)
  assert.match(source, /useState\(false\)/)
  assert.match(source, /setRegistrationEnabled\(Boolean\(config\.registration_enabled\)\)/)
  assert.match(source, /\.catch\(\(\) => \{\s*setRegistrationEnabled\(false\)/s)
  assert.match(source, /if \(!registrationEnabled\) \{\s*setError\(/s)
  assert.match(source, /action: "register"/)
  assert.match(source, /disabled=\{loading \|\| !registrationEnabled\}/)
  assert.match(source, /router\.push\("\/login\?registration=pending"\)/)
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

  assert.match(source, /Alcance y límites de estas salidas/)
  assert.match(source, /No es una presentación oficial/)
  assert.match(source, /No es un servicio de aseguramiento/)
  assert.match(source, /Taxonomía de la UE/)
  assert.match(source, /candidato XHTML\/iXBRL no se ofrece como descarga/)
})

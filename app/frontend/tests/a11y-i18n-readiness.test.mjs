import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { join } from "node:path"
import test from "node:test"

function read(relativePath) {
  return readFileSync(join(process.cwd(), relativePath), "utf8")
}

test("Spanish application shell declares locale and localized metadata", () => {
  const layout = read("app/layout.tsx")

  assert.match(layout, /<html lang=["']es["']>/)
  assert.match(layout, /Preparaci[oó]n ESRS asistida/)
  assert.doesNotMatch(layout, /\/favicon\.png/)
  assert.match(layout, /\/icon-light-32x32\.png/)
  assert.match(layout, /\/apple-icon\.png/)
})

test("active UI primitives do not leak English accessibility copy", () => {
  const files = [
    "components/dashboard/progress-card.tsx",
    "components/ui/command.tsx",
    "components/ui/pagination.tsx",
    "components/ui/sidebar.tsx",
    "components/ui/spinner.tsx",
  ]

  const englishUiPatterns = [
    /ESG Report Illustration/,
    /aria-label=["']Loading["']/,
    />Loading</,
    /Toggle Sidebar/,
    /Go to previous page/,
    /Go to next page/,
    />Previous</,
    />Next</,
    /More pages/,
    /Search for a command to run/,
    /No results found/,
    />Sidebar</,
    /Displays the mobile sidebar/,
  ]

  const offenders = files.flatMap((file) => {
    const source = read(file)
    return englishUiPatterns
      .filter((pattern) => pattern.test(source))
      .map((pattern) => `${file}: ${pattern}`)
  })

  assert.deepEqual(offenders, [])
})

test("dashboard header exposes named account controls", () => {
  const source = read("components/dashboard/dashboard-header.tsx")

  assert.match(source, /aria-label=["']Abrir men[uú] de usuario["']/)
  assert.match(source, /<HelpCircle[^>]+aria-hidden=["']true["']/)
})

test("Next frontend applies baseline security headers to rendered routes", () => {
  const source = read("next.config.mjs")

  assert.match(source, /async headers\(\)/)
  assert.match(source, /X-Content-Type-Options/)
  assert.match(source, /nosniff/)
  assert.match(source, /Referrer-Policy/)
  assert.match(source, /strict-origin-when-cross-origin/)
  assert.match(source, /X-Frame-Options/)
  assert.match(source, /DENY/)
  assert.match(source, /Permissions-Policy/)
})

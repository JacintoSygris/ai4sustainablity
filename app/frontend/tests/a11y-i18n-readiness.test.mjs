import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { join } from "node:path"
import test from "node:test"

function read(relativePath) {
  return readFileSync(join(process.cwd(), relativePath), "utf8")
}

test("Spanish application shell declares locale and localized metadata", () => {
  const layout = read("app/layout.tsx")

  assert.match(layout, /<html lang=\{locale\}>/)
  assert.match(layout, /getLaravelServerLocale/)
  assert.match(read("lib/i18n/locale.mjs"), /Preparaci[oó]n NEIS asistida/)
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

  assert.match(source, /aria-label=\{tr\(["']Abrir men[uú] de usuario["']\)\}/)
  assert.match(source, /<HelpCircle[^>]+aria-hidden=["']true["']/)
})

test("materiality workflows expose labelled searches and announced errors", () => {
  const p6 = read("components/wizard/material-topics-form.tsx")
  const p8 = read("components/wizard/final-topics-selection.tsx")

  assert.match(p6, /aria-label=\{tr\(["']Buscar tema NEIS["']\)\}/)
  assert.match(p8, /aria-label=\{tr\(["']Buscar tema NEIS["']\)\}/)
  assert.match(p6, /role=["']alert["']/)
  assert.match(p8, /role=["']alert["']/)
})

test("dashboard logout treats an expired Laravel session as signed out", () => {
  const source = read("components/dashboard/dashboard-header.tsx")
  const logout = read("lib/laravel-logout.mjs")

  assert.match(source, /runLaravelLogout/)
  assert.match(source, /role=["']alert["']/)
  assert.match(logout, /error\?\.status === 401/)
  assert.match(logout, /response\?\.status === 401/)
  assert.match(source, /router\.replace\(["']\/login["']\)/)
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


test("wizard presentation does not expose internal notes, phase codes or the previous time-bound instruction", () => {
  const survey = read("components/wizard/initial-survey-form.tsx")
  const internalNotesLabel = ["Notas", " internas"].join("")
  assert.ok(!survey.includes(`label="${internalNotesLabel}"`))
  assert.doesNotMatch(survey, /htmlFor="notes"|value=\{formData.notes\}/)
  assert.match(survey, /notes: formData.notes.trim\(\) \|\| null/, "stored notes must be preserved in the payload")
  const topics = read("components/wizard/material-topics-form.tsx")
  assert.doesNotMatch(topics, /\{proposal.ai.summary\}/)
  const datapoints = read("components/wizard/esrs-datapoints-form.tsx")
  assert.match(datapoints, /El objetivo de este paso no es responderlo todo: es inventariar qué tienes y qué te falta/)
  assert.doesNotMatch(datapoints, /El objetivo de hoy|\{phaseSummary.status \|\|/)
  const forbiddenRuntimeCopy = new RegExp([
    "\\bP(?:5|6|7|8|9|10)\\b",
    ["small", "10"].join(""),
    ["Prueba", " E2E"].join(""),
    ["AI", " proposed"].join(""),
  ].join("|"))
  for (const file of ["initial-survey-form", "material-topics-form", "double-materiality-guide", "esrs-datapoints-form", "final-topics-selection", "report-draft-panel", "wizard-expectations", "wizard-sidebar"]) {
    const source = read(`components/wizard/${file}.tsx`)
    // User-facing JSX prose, excluding identifiers, API keys and comments.
    const prose = [...source.matchAll(/>([^<>{}]+)</g)].map(match => match[1]).join(" ")
    assert.doesNotMatch(prose, forbiddenRuntimeCopy, file)
  }
})


test("characterization HTML and PDF summary never print internal notes", () => {
  const view = read("../web/resources/views/characterization/summary.blade.php")
  assert.doesNotMatch(view, /Arr::get\(\$formData, ['"]notes['"]|No additional notes provided/)
  const controller = read("../web/app/Http/Controllers/CharacterizationController.php")
  assert.match(controller, /Pdf::loadView\('characterization.summary'/)
  assert.match(controller, /return view\('characterization.summary'/)
})

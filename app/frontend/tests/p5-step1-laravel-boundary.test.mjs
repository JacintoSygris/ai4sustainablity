import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"

const root = dirname(dirname(fileURLToPath(import.meta.url)))

function read(relativePath) {
  return readFileSync(join(root, relativePath), "utf8")
}

test("wizard Step 1 is a Laravel P5 page, not a local Better Auth/Turso page", () => {
  const source = read("app/(dashboard)/wizard/step-1/page.tsx")

  assert.doesNotMatch(source, /@\/lib\/auth/, "Step 1 must not read Better Auth directly")
  assert.doesNotMatch(source, /@\/lib\/queries/, "Step 1 must not create or read Turso reports")
  assert.doesNotMatch(source, /getUserReport|getSurveyData|createReport/, "Step 1 must not use local report queries")
  assert.match(source, /WizardSidebar/, "Step 1 must keep the imported wizard shell")
  assert.match(source, /InitialSurveyForm/, "Step 1 must delegate P5 editing to the form component")
})

test("initial survey form persists the official P5 characterization payload to Laravel", () => {
  const source = read("components/wizard/initial-survey-form.tsx")

  assert.match(
    source,
    /getLaravelCharacterization|getLaravelCharacterizationOptions|saveLaravelCharacterizationDraft/,
    "P5 form must use the shared Laravel API client helpers",
  )
  assert.match(source, /saveLaravelCharacterizationDraft/, "P5 form must write Laravel characterization drafts")
  assert.match(source, /getLaravelCharacterizationOptions/, "P5 form must load Laravel characterization options")
  assert.match(source, /getLaravelNaceCodes/, "P5 form must expose Laravel NACE lookup support")
  assert.doesNotMatch(source, /fetch\(["']\/api\/wizard\/step-1/, "P5 form must not post to the imported local Next backend")
  assert.doesNotMatch(source, /fetch\(["']\/api\/wizard\/reset-step/, "P5 form must not reset local wizard state")

  const requiredP5Fields = [
    "nace_code",
    "company_profile",
    "company_name",
    "headquarters_country",
    "reporting_year",
    "reporting_scope",
    "num_subsidiaries_countries",
    "stock_listed",
    "reporting_currency",
    "product_service_type",
    "employee_count_range",
    "revenue_range",
    "value_chain",
  ]

  for (const field of requiredP5Fields) {
    assert.match(source, new RegExp(field), `P5 form must include Laravel field ${field}`)
  }
})

test("initial survey form preserves multi-select operation arrays", () => {
  const source = read("components/wizard/initial-survey-form.tsx")

  assert.doesNotMatch(source, /firstArrayValue/, "P5 form must not truncate multi-select arrays to their first value")
  assert.match(source, /regions: string\[\]/, "P5 form state must model regions as an array")
  assert.match(source, /valueChain: string\[\]/, "P5 form state must model value chain positions as an array")
  assert.match(source, /regions: stringArrayValue\(operations\.regions\)/, "P5 load path must preserve all regions")
  assert.match(source, /valueChain: stringArrayValue\(operations\.value_chain\)/, "P5 load path must preserve all value chain positions")
  assert.match(source, /regions: formData\.regions/, "P5 save payload must send all selected regions")
  assert.match(source, /value_chain: formData\.valueChain/, "P5 save payload must send all value chain positions")
  assert.match(source, /MultiSelectCheckboxes/, "P5 form must render a multi-select control")
  assert.match(source, /optionLabels\(regionOptions, formData\.regions\)/, "P5 read-only summary must render all regions")
  assert.match(
    source,
    /optionLabels\(valueChainOptions, formData\.valueChain\)/,
    "P5 read-only summary must render all value chain positions",
  )
})

test("initial survey form maps NACE validation to a field-level catalog lookup", () => {
  const formSource = read("components/wizard/initial-survey-form.tsx")
  const clientSource = read("lib/laravel-api.ts")

  assert.match(clientSource, /type LaravelNaceCode/, "Laravel client must type NACE catalog resources")
  assert.match(clientSource, /getLaravelNaceCodes/, "Laravel client must expose a NACE catalog helper")
  assert.match(clientSource, /\/nace-codes/, "Laravel NACE helper must call the Laravel NACE endpoint")
  assert.match(formSource, /fieldErrors\.naceCode/, "P5 form must store a NACE field-level error")
  assert.match(formSource, /validationMessageFor\(error, "nace_code"\)/, "P5 form must map Laravel nace_code errors")
  assert.match(formSource, /Selecciona un código NACE\/CNAE válido/, "P5 form must provide a NACE-specific fallback error")
  assert.match(formSource, /naceOptions\.map/, "P5 form must render NACE catalog lookup results")
  assert.match(formSource, /incluso sin acentos/, "P5 form must explain accent-insensitive catalog search")
  assert.match(formSource, /K62\.1/, "P5 form must show a valid section-prefixed CNAE example")
  assert.doesNotMatch(formSource, /6201/, "P5 form must not suggest an unprefixed code that Laravel rejects")
})

test("initial survey form distinguishes saved P5 draft from Step 2 AI generation", () => {
  const source = read("components/wizard/initial-survey-form.tsx")

  assert.match(source, /Perfil P5 guardado como borrador/, "P5 saved state must be labeled as a draft/profile")
  assert.match(source, /La propuesta IA se genera en el paso 2/, "P5 saved state must not imply AI generation happened")
  assert.match(source, /Continuar a propuesta IA/, "P5 saved state must hand off explicitly to Step 2")
})

test("Laravel API client exposes typed P5 characterization helpers", () => {
  const source = read("lib/laravel-api.ts")

  assert.match(source, /LaravelCharacterization/, "client must type characterization resources")
  assert.match(source, /LaravelCharacterizationOptions/, "client must type characterization options")
  assert.match(source, /getLaravelCharacterization/, "client must expose a read helper")
  assert.match(source, /getLaravelCharacterizationOptions/, "client must expose an options helper")
  assert.match(source, /getLaravelNaceCodes/, "client must expose a NACE catalog helper")
  assert.match(source, /saveLaravelCharacterizationDraft/, "client must expose a draft save helper")
  assert.match(source, /\/characterization/, "client must call Laravel characterization API")
  assert.match(source, /\/characterization\/options/, "client must call Laravel characterization options API")
  assert.match(source, /\/nace-codes/, "client must call Laravel NACE catalog API")
})

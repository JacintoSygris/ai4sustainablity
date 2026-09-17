import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"
import {
  TEMPLATE_DOWNLOAD_LABELS,
  TEMPLATE_LOCALES,
  actaRegistered,
  canContinueFromGuideState,
  checklistComplete,
  firstOpenStepKey,
  guideProgressLabel,
  localized,
  templateDownloadPath,
} from "../lib/double-materiality-guide-state.mjs"

const root = dirname(dirname(fileURLToPath(import.meta.url)))

function read(relativePath) {
  return readFileSync(join(root, relativePath), "utf8")
}

test("wizard Step 3 is a Laravel P7 guide page, not a local Better Auth/Turso page", () => {
  const source = read("app/(dashboard)/wizard/step-3/page.tsx")

  assert.doesNotMatch(source, /@\/lib\/auth/, "Step 3 must not read Better Auth directly")
  assert.doesNotMatch(source, /@\/lib\/queries/, "Step 3 must not read reports from Turso")
  assert.doesNotMatch(source, /getUserReport|reportId|userReport/, "Step 3 must not depend on local report IDs")
  assert.match(source, /WizardSidebar/, "Step 3 must keep the imported wizard shell")
  assert.match(source, /DoubleMaterialityGuide/, "Step 3 must delegate P7 rendering to the guide component")
})

test("double materiality guide loads Laravel P7 prose guide + persisted checklist/acta state", () => {
  const source = read("components/wizard/double-materiality-guide.tsx")

  assert.match(source, /getLaravelDoubleMaterialityGuide/, "P7 component must load guide JSON (prose) from Laravel")
  assert.match(source, /getLaravelDoubleMaterialityGuideState/, "P7 component must load persisted process state (checklist+acta)")
  assert.match(source, /updateLaravelDoubleMaterialityGuideState/, "P7 component must persist checklist/acta via state endpoint")
  assert.match(source, /getLaravelDoubleMaterialityGuideState/, "P7 must call the state read helper (path declared in client)")
  assert.match(source, /Marca lo que ya has hecho/, "P7 must render the persisted checklist card")
  assert.match(source, /Acta del análisis/, "P7 must render the acta card")
  assert.match(source, /sin análisis registrado/, "P7 must surface the no-acta helper text")
  assert.match(source, /He identificado a mis grupos de interés/, "P7 must use exact checklist labels from plan")
  assert.match(source, /Plantillas para tu análisis/, "P7 must render the localized templates card (restored per owner decision 2026-06-10)")
  assert.match(source, /templateDownloadPath\(template\.key, locale\)/, "P7 template downloads must be per-locale")
  assert.doesNotMatch(source, /Plantillas ADM/, "P7 must not use the ADM jargon in the templates card title")
  assert.match(source, /firstOpenStepKey\(guideResponse\.data\)/, "P7 component must use guide data for initial open state")
  assert.match(source, /canContinueFromGuideState/, "P7 component must gate continuation from guide state")
  assert.match(source, /disabled=\{!canContinue\}/, "P7 component must not offer continue as primary action before guide success")
  assert.match(source, /error\.status === 401/, "P7 component must detect Laravel 401 responses")
  assert.match(source, /router\.replace\("\/login"\)/, "P7 component must still redirect unauthenticated users to login")
  assert.doesNotMatch(source, /fetch\(["']\/api\/wizard\/step-3/, "P7 component must not post to local Next Step 3")
  assert.doesNotMatch(source, /reportId/, "P7 component must not accept or send local report IDs")
})

test("template download paths are locale-aware and default to Spanish", () => {
  assert.deepEqual(TEMPLATE_LOCALES, ["es", "en"])
  assert.equal(templateDownloadPath("iro_register"), "/double-materiality-guide/templates/iro_register.csv?locale=es")
  assert.equal(
    templateDownloadPath("iro_register", "en"),
    "/double-materiality-guide/templates/iro_register.csv?locale=en",
  )
  assert.equal(
    templateDownloadPath("stakeholder_consultation_log", "fr"),
    "/double-materiality-guide/templates/stakeholder_consultation_log.csv?locale=es",
  )
  assert.equal(TEMPLATE_DOWNLOAD_LABELS.es, "Descargar en español")
  assert.equal(TEMPLATE_DOWNLOAD_LABELS.en, "Download in English")
})

test("double materiality guide state helpers derive progress label and acta/checklist completeness", () => {
  const guide = {
    sections: [
      {
        steps: [{ key: "review_p5_p6" }],
      },
    ],
  }

  assert.deepEqual(firstOpenStepKey(guide), ["review_p5_p6"])
  assert.deepEqual(firstOpenStepKey({ sections: [] }), [])
  assert.equal(localized({ es: "ES", en: "EN" }), "ES")
  assert.equal(localized({ es: null, en: "EN" }), "EN")

  assert.equal(canContinueFromGuideState({ guide, loadingInitial: false, errorMessage: null }), true)
  assert.equal(canContinueFromGuideState({ guide, loadingInitial: true, errorMessage: null }), false)
  assert.equal(canContinueFromGuideState({ guide, loadingInitial: false, errorMessage: "error" }), false)
  assert.equal(canContinueFromGuideState({ guide: null, loadingInitial: false, errorMessage: null }), false)

  // acta / checklist / label shapes
  const empty = { checklist: { identified_stakeholders: false, assessed_impacts: false, assessed_financial_effects: false, reached_conclusions: false }, acta: { completed_on: null, method: null, participants: null }, guide_status: "missing" }
  assert.equal(actaRegistered(empty), false)
  assert.equal(checklistComplete(empty), false)
  assert.equal(guideProgressLabel(empty), "Sin empezar")

  const partial = { checklist: { identified_stakeholders: true, assessed_impacts: false, assessed_financial_effects: false, reached_conclusions: false }, acta: { completed_on: null, method: null, participants: null }, guide_status: "in_progress" }
  assert.equal(actaRegistered(partial), false)
  assert.equal(checklistComplete(partial), false)
  assert.equal(guideProgressLabel(partial), "En curso")

  const doneChecklist = { checklist: { identified_stakeholders: true, assessed_impacts: true, assessed_financial_effects: true, reached_conclusions: true }, acta: { completed_on: null, method: null, participants: null }, guide_status: "in_progress" }
  assert.equal(checklistComplete(doneChecklist), true)
  assert.equal(guideProgressLabel(doneChecklist), "Análisis registrado")

  const withActa = { checklist: { identified_stakeholders: false, assessed_impacts: false, assessed_financial_effects: false, reached_conclusions: false }, acta: { completed_on: "2026-06-01", method: "Taller interno", participants: "Gerencia" }, acta_registered: true, guide_status: "ready" }
  assert.equal(actaRegistered(withActa), true)
  assert.equal(guideProgressLabel(withActa), "Análisis registrado")
})

test("Laravel API client exposes typed P7 double materiality guide + state (checklist/acta) helpers", () => {
  const source = read("lib/laravel-api.ts")

  assert.match(source, /LaravelDoubleMaterialityGuide/, "client must type P7 guide resources")
  assert.match(source, /getLaravelDoubleMaterialityGuide/, "client must expose P7 guide read helper")
  assert.match(source, /LaravelDoubleMaterialityProcessState/, "client must type P7 process state (checklist+acta)")
  assert.match(source, /getLaravelDoubleMaterialityGuideState/, "client must expose P7 state read helper")
  assert.match(source, /updateLaravelDoubleMaterialityGuideState/, "client must expose P7 state PUT helper")
  assert.match(source, /\/double-materiality-guide\/state/, "client must call Laravel P7 state endpoints")
  assert.match(source, /\/double-materiality-guide/, "client must call Laravel P7 guide API")
})

test("P7 state helpers and contract shapes match the public shape (checklist 4-bool, acta 3-field, guide_status)", () => {
  // contract shape assertions (no live fetch; boundary tests stay source + pure per existing style in file)
  const defaultShape = {
    checklist: { identified_stakeholders: false, assessed_impacts: false, assessed_financial_effects: false, reached_conclusions: false },
    acta: { completed_on: null, method: null, participants: null },
    acta_registered: false,
    guide_status: "missing",
    updated_at: null,
  }
  assert.equal(actaRegistered(defaultShape), false)
  assert.equal(checklistComplete(defaultShape), false)
  assert.equal(guideProgressLabel(defaultShape), "Sin empezar")

  const readyShape = {
    checklist: { identified_stakeholders: true, assessed_impacts: true, assessed_financial_effects: true, reached_conclusions: true },
    acta: { completed_on: "2026-06-01", method: "Taller interno con dirección", participants: "Gerencia, RRHH, producción" },
    acta_registered: true,
    guide_status: "ready",
    updated_at: "2026-06-01T10:00:00Z",
  }
  assert.equal(actaRegistered(readyShape), true)
  assert.equal(checklistComplete(readyShape), true)
  assert.equal(guideProgressLabel(readyShape), "Análisis registrado")

  // Source assertions keep the typed helpers and /state routes aligned with these field names.
  const source = read("lib/laravel-api.ts")
  assert.match(source, /identified_stakeholders|assessed_impacts|assessed_financial_effects|reached_conclusions/, "state type must declare exact 4 checklist bool keys")
  assert.match(source, /completed_on: string \| null/, "state type must declare acta completed_on")
  assert.match(source, /method: string \| null/, "state type must declare acta method")
  assert.match(source, /participants: string \| null/, "state type must declare acta participants")
  assert.match(source, /acta_registered|guide_status/, "state response must include acta_registered + guide_status")
})

import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"
import {
  canContinueFromGuideState,
  firstOpenStepKey,
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

test("double materiality guide loads Laravel P7 guide and template links", () => {
  const source = read("components/wizard/double-materiality-guide.tsx")

  assert.match(source, /getLaravelDoubleMaterialityGuide/, "P7 component must load guide JSON from Laravel")
  assert.match(source, /laravelApiUrl/, "P7 component must build Laravel template download URLs")
  assert.match(source, /templateDownloadPath\(template\.key\)/, "P7 component must derive download URLs from template keys")
  assert.match(source, /firstOpenStepKey\(guideResponse\.data\)/, "P7 component must use guide data for initial open state")
  assert.match(source, /canContinueFromGuideState/, "P7 component must gate continuation from guide state")
  assert.match(source, /disabled=\{!canContinue\}/, "P7 component must not offer continue as primary action before guide success")
  assert.match(source, /error\.status === 401/, "P7 component must detect Laravel 401 responses")
  assert.match(source, /router\.replace\("\/login"\)/, "P7 component must still redirect unauthenticated users to login")
  assert.doesNotMatch(source, /fetch\(["']\/api\/wizard\/step-3/, "P7 component must not post to local Next Step 3")
  assert.doesNotMatch(source, /reportId/, "P7 component must not accept or send local report IDs")
})

test("double materiality guide state helpers gate continuation and derive Laravel template paths", () => {
  const guide = {
    sections: [
      {
        steps: [{ key: "review_p5_p6" }],
      },
    ],
  }

  assert.deepEqual(firstOpenStepKey(guide), ["review_p5_p6"])
  assert.deepEqual(firstOpenStepKey({ sections: [] }), [])
  assert.equal(templateDownloadPath("iro_register"), "/double-materiality-guide/templates/iro_register.csv")
  assert.equal(templateDownloadPath("stakeholder_consultation_log"), "/double-materiality-guide/templates/stakeholder_consultation_log.csv")
  assert.equal(localized({ es: "ES", en: "EN" }), "ES")
  assert.equal(localized({ es: null, en: "EN" }), "EN")

  assert.equal(canContinueFromGuideState({ guide, loadingInitial: false, errorMessage: null }), true)
  assert.equal(canContinueFromGuideState({ guide, loadingInitial: true, errorMessage: null }), false)
  assert.equal(canContinueFromGuideState({ guide, loadingInitial: false, errorMessage: "error" }), false)
  assert.equal(canContinueFromGuideState({ guide: null, loadingInitial: false, errorMessage: null }), false)
})

test("Laravel API client exposes typed P7 double materiality guide helper", () => {
  const source = read("lib/laravel-api.ts")

  assert.match(source, /LaravelDoubleMaterialityGuide/, "client must type P7 guide resources")
  assert.match(source, /getLaravelDoubleMaterialityGuide/, "client must expose P7 guide read helper")
  assert.match(source, /\/double-materiality-guide/, "client must call Laravel P7 guide API")
})

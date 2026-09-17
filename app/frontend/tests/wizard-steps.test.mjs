import assert from "node:assert/strict"
import test from "node:test"

import { WIZARD_STEPS, wizardStepsForView } from "../lib/wizard-steps-data.mjs"

test("wizard step titles match the canonical six-step journey", () => {
  assert.deepEqual(
    WIZARD_STEPS.map((step) => step.title),
    [
      "Encuesta inicial",
      "Revisión de temas materiales",
      "Doble materialidad",
      "Selección final de temas relevantes",
      "Datapoints (ESRS)",
      "Informe",
    ],
  )
})

test("wizardStepsForView marks previous, current, and upcoming steps", () => {
  assert.deepEqual(
    wizardStepsForView(3).map((step) => step.status),
    ["completed", "completed", "current", "upcoming", "upcoming", "upcoming"],
  )
})

test("wizard step copy does not expose internal codes or retired brand names", () => {
  for (const step of WIZARD_STEPS) {
    assert.doesNotMatch(step.title, /P\d/)
    assert.doesNotMatch(step.description, /P\d/)
    assert.doesNotMatch(step.title, /Airis/)
    assert.doesNotMatch(step.description, /Airis/)
  }
})

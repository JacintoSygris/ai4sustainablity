import assert from "node:assert/strict"
import test from "node:test"

import {
  IMPACT_LEVELS,
  CONFIDENCE_LEVELS,
  EXPOSURE_LEVELS,
  suggestTopicResult,
  resolveObservacion,
  deriveDimension,
  buildGuidedAnswer,
  validateGuidedAnswer,
} from "../lib/materiality-guided-state.mjs"

test("materiality-guided-state exports exact enum arrays for guided review", () => {
  assert.deepEqual(IMPACT_LEVELS, ["bajo", "medio", "alto", "no_lo_se"])
  assert.deepEqual(CONFIDENCE_LEVELS, ["baja", "media", "alta"])
  assert.deepEqual(EXPOSURE_LEVELS, ["normal", "fuerte", "descartada"])
})

test("suggestTopicResult implements exact 7-row precedence table (first match wins)", () => {
  // Row 1: alto anywhere → material; revisar = (confianza=baja or any no_lo_se)
  const r1a = suggestTopicResult({ impacto: "alto", financiero: "bajo", confianza: "alta", exposicion: "normal" })
  assert.deepEqual(r1a, { suggested_result: "material", revisar: false })

  const r1b = suggestTopicResult({ impacto: "bajo", financiero: "alto", confianza: "media", exposicion: "normal" })
  assert.deepEqual(r1b, { suggested_result: "material", revisar: false })

  const r1RevisarLowConf = suggestTopicResult({ impacto: "alto", financiero: "bajo", confianza: "baja", exposicion: "normal" })
  assert.deepEqual(r1RevisarLowConf, { suggested_result: "material", revisar: true })

  const r1RevisarNoSe = suggestTopicResult({ impacto: "alto", financiero: "no_lo_se", confianza: "alta", exposicion: "normal" })
  assert.deepEqual(r1RevisarNoSe, { suggested_result: "material", revisar: true })

  // Row 2: any no_lo_se (and no alto) → en_observacion
  const r2 = suggestTopicResult({ impacto: "medio", financiero: "no_lo_se", confianza: "alta", exposicion: "normal" })
  assert.deepEqual(r2, { suggested_result: "en_observacion", revisar: false })

  // Row 3: confianza=baja (and no alto) → en_observacion
  const r3 = suggestTopicResult({ impacto: "medio", financiero: "bajo", confianza: "baja", exposicion: "normal" })
  assert.deepEqual(r3, { suggested_result: "en_observacion", revisar: false })

  // Row 4: (medio or medio) and fuerte → material, revisar=false
  const r4 = suggestTopicResult({ impacto: "medio", financiero: "bajo", confianza: "alta", exposicion: "fuerte" })
  assert.deepEqual(r4, { suggested_result: "material", revisar: false })

  const r4f = suggestTopicResult({ impacto: "bajo", financiero: "medio", confianza: "media", exposicion: "fuerte" })
  assert.deepEqual(r4f, { suggested_result: "material", revisar: false })

  // Row 5: medio without fuerte → en_observacion
  const r5 = suggestTopicResult({ impacto: "medio", financiero: "bajo", confianza: "alta", exposicion: "normal" })
  assert.deepEqual(r5, { suggested_result: "en_observacion", revisar: false })

  // Row 6: bajo + bajo + (normal|descartada) + (media|alta) → no_material, revisar=false
  const r6n = suggestTopicResult({ impacto: "bajo", financiero: "bajo", confianza: "alta", exposicion: "normal" })
  assert.deepEqual(r6n, { suggested_result: "no_material", revisar: false })

  const r6d = suggestTopicResult({ impacto: "bajo", financiero: "bajo", confianza: "media", exposicion: "descartada" })
  assert.deepEqual(r6d, { suggested_result: "no_material", revisar: false })

  // Row 7 fallback (incl. bajo+bajo + fuerte) → en_observacion
  const r7 = suggestTopicResult({ impacto: "bajo", financiero: "bajo", confianza: "alta", exposicion: "fuerte" })
  assert.deepEqual(r7, { suggested_result: "en_observacion", revisar: false })

  const r7other = suggestTopicResult({ impacto: "bajo", financiero: "medio", confianza: "baja", exposicion: "descartada" })
  assert.deepEqual(r7other, { suggested_result: "en_observacion", revisar: false })
})

test("suggestTopicResult treats descartada as normal for rule evaluation (row 6 path)", () => {
  // bajo+bajo+descartada+alta must hit row 6 (no_material) not row 7
  const res = suggestTopicResult({ impacto: "bajo", financiero: "bajo", confianza: "alta", exposicion: "descartada" })
  assert.equal(res.suggested_result, "no_material")
  assert.equal(res.revisar, false)
})

test("resolveObservacion defaults to pre-resolved material+revisar and keeps revisar on override to no_material", () => {
  assert.deepEqual(resolveObservacion(), { final_result: "material", revisar: true })
  assert.deepEqual(resolveObservacion("no_material"), { final_result: "no_material", revisar: true })
  assert.deepEqual(resolveObservacion("material"), { final_result: "material", revisar: true })
})

test("deriveDimension four cases per contract (both/impact/financial/undefined)", () => {
  assert.equal(deriveDimension("alto", "medio"), "both")
  assert.equal(deriveDimension("medio", "bajo"), "impact")
  assert.equal(deriveDimension("bajo", "alto"), "financial")
  assert.equal(deriveDimension("bajo", "bajo"), undefined)
  assert.equal(deriveDimension("no_lo_se", "medio"), "financial")
  assert.equal(deriveDimension("medio", "no_lo_se"), "impact")
  assert.equal(deriveDimension(undefined, "alto"), "financial")
})

test("buildGuidedAnswer assembles contract-valid object with suggested + final + revisar + optional note", () => {
  const ans = buildGuidedAnswer(
    { impacto: "alto", financiero: "bajo", confianza: "baja", exposicion: "normal" },
    "material",
  )
  assert.deepEqual(ans, {
    impacto: "alto",
    financiero: "bajo",
    confianza: "baja",
    exposicion: "normal",
    suggested_result: "material",
    final_result: "material",
    revisar: true,
  })

  const withNote = buildGuidedAnswer(
    { impacto: "bajo", financiero: "bajo", confianza: "alta", exposicion: "descartada" },
    "no_material",
    "Razón interna",
  )
  assert.equal(withNote.note, "Razón interna")
  assert.equal(withNote.final_result, "no_material")
  assert.equal(withNote.revisar, false)
})

test("validateGuidedAnswer accepts valid and rejects bad enums / shapes", () => {
  const valid = {
    impacto: "medio",
    financiero: "alto",
    confianza: "media",
    exposicion: "fuerte",
    suggested_result: "material",
    final_result: "material",
    revisar: false,
  }
  assert.equal(validateGuidedAnswer(valid), true)

  // bad enum
  assert.equal(validateGuidedAnswer({ ...valid, impacto: "muy_alto" }), false)
  assert.equal(validateGuidedAnswer({ ...valid, confianza: "bajo" }), false)
  assert.equal(validateGuidedAnswer({ ...valid, exposicion: "fuerte", suggested_result: "observacion" }), false)
  assert.equal(validateGuidedAnswer({ ...valid, final_result: "en_observacion" }), false)
  // missing required
  assert.equal(validateGuidedAnswer({ impacto: "bajo" }), false)
  // note too long (>300)
  assert.equal(
    validateGuidedAnswer({ ...valid, note: "x".repeat(301) }),
    false,
  )
  // revisar not bool
  assert.equal(validateGuidedAnswer({ ...valid, revisar: "si" }), false)
})

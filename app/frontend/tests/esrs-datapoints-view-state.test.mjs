import assert from "node:assert/strict"
import test from "node:test"
import {
  DEFAULT_OBLIGATION_FILTER,
  TRIAGE_OPTIONS,
  applyObligationFilter,
  compactDrafts,
  createDefaultFact,
  factKindRequiresDecimals,
  factKindRequiresUnit,
  factKindUnitPlaceholder,
  groupRowsByStandard,
  honestCountsLabel,
  localStorageDraftKey,
  obligationBadge,
  phaseInBadgeLabel,
  sectionProgressLabel,
  triageSummary,
} from "../lib/esrs-datapoints-state.mjs"

test("esrs view state exports the required grouping, badge, filter and triage helpers (Plan 3 F1)", () => {
  assert.equal(typeof groupRowsByStandard, "function")
  assert.equal(typeof obligationBadge, "function")
  assert.equal(typeof applyObligationFilter, "function")
  assert.equal(DEFAULT_OBLIGATION_FILTER, "mandatory_only")
  assert.equal(typeof honestCountsLabel, "function")
  assert.deepEqual(Object.keys(TRIAGE_OPTIONS), ["have_it", "need_to_find", "not_applicable_candidate"])
  assert.equal(typeof triageSummary, "function")
  assert.equal(typeof phaseInBadgeLabel, "function")
  assert.equal(typeof sectionProgressLabel, "function")
  assert.equal(typeof localStorageDraftKey, "function")
  assert.equal(typeof createDefaultFact, "function")
})

test("groupRowsByStandard puts ESRS 2 first then alphabetical and computes counts", () => {
  const rows = [
    { datapoint: { id: "E1-1", standard: "ESRS E1", may_disclose: true } },
    { datapoint: { id: "BP-1", standard: "ESRS 2", may_disclose: false } },
    { datapoint: { id: "E1-2", standard: "ESRS E1", may_disclose: false } },
    { datapoint: { id: "S1-1", standard: "ESRS S1", may_disclose: true } },
  ]
  const groups = groupRowsByStandard(rows)
  assert.equal(groups[0].standard, "ESRS 2")
  assert.equal(groups[1].standard, "ESRS E1")
  assert.equal(groups[2].standard, "ESRS S1")
  assert.equal(groups[0].counts.total, 1)
  assert.equal(groups[0].counts.mandatory, 1)
  assert.equal(groups[0].counts.voluntary, 0)
  assert.equal(groups[1].counts.total, 2)
  assert.equal(groups[1].counts.voluntary, 1)
  assert.equal(groups[1].counts.mandatory, 1)
})

test("obligationBadge: voluntary wins over conditional; conditional only when !may and cond present; else mandatory", () => {
  assert.deepEqual(obligationBadge({ may_disclose: true }), { kind: "voluntary", label: "Voluntario (opcional)" })
  assert.deepEqual(obligationBadge({ may_disclose: true, conditional_or_alternative: "x" }), { kind: "voluntary", label: "Voluntario (opcional)" })
  assert.deepEqual(obligationBadge({ may_disclose: false, conditional_or_alternative: "alt" }), { kind: "conditional", label: "Condicional" })
  assert.deepEqual(obligationBadge({ may_disclose: false }), { kind: "mandatory", label: "Obligatorio" })
  assert.deepEqual(obligationBadge({}), { kind: "mandatory", label: "Obligatorio" })
})

test("applyObligationFilter defaults to mandatory_only and supports phase_in and all", () => {
  const rows = [
    { datapoint: { id: "m1", may_disclose: false } },
    { datapoint: { id: "v1", may_disclose: true } },
    { datapoint: { id: "p1", phase_in: { less_than_750: "x" } } },
    { datapoint: { id: "p2", phase_in: { all_undertakings: "y" } } },
  ]
  // mandatory_only keeps non-voluntary (m1 + the two phase-in rows that carry no may_disclose flag)
  assert.equal(applyObligationFilter(rows).length, 3)
  assert.equal(applyObligationFilter(rows, "all").length, 4)
  assert.equal(applyObligationFilter(rows, "phase_in").length, 2)
  assert.equal(applyObligationFilter(rows, "mandatory_only").length, 3)
})

test("honestCountsLabel derives from total - voluntary", () => {
  assert.equal(honestCountsLabel({ total_datapoint_count: 176, voluntary_datapoint_count: 30 }), "146 obligatorios + 30 voluntarios (opcionales)")
  assert.equal(honestCountsLabel({ total_datapoint_count: 10, voluntary_datapoint_count: 0 }), "10 obligatorios + 0 voluntarios (opcionales)")
})

test("TRIAGE_OPTIONS and triageSummary count correctly including untriaged", () => {
  assert.equal(TRIAGE_OPTIONS.have_it, "Lo tengo")
  assert.equal(TRIAGE_OPTIONS.need_to_find, "Tengo que buscarlo")
  assert.equal(TRIAGE_OPTIONS.not_applicable_candidate, "Creo que no aplica")
  const summary = triageSummary({
    "d1": { triage: "have_it" },
    "d2": { triage: "have_it" },
    "d3": { triage: "need_to_find" },
    "d4": { status: "draft" }, // untriaged
    "d5": { triage: "not_applicable_candidate" },
  })
  assert.deepEqual(summary, { have_it: 2, need_to_find: 1, not_applicable_candidate: 1, untriaged: 1 })
})

test("phaseInBadgeLabel returns copy only when phase_in.less_than_750 present AND lessThan750 true", () => {
  const dp = { phase_in: { less_than_750: "May phase in" } }
  assert.equal(phaseInBadgeLabel(dp, true), "Menos de 750 empleados: puedes aplazar este dato")
  assert.equal(phaseInBadgeLabel(dp, false), null)
  assert.equal(phaseInBadgeLabel({ phase_in: { all_undertakings: "x" } }, true), null)
  assert.equal(phaseInBadgeLabel({}, true), null)
})

test("sectionProgressLabel formats ESRS 2 — X de Y from group", () => {
  assert.equal(sectionProgressLabel({ standard: "ESRS 2", counts: { total: 146 } }), "ESRS 2 — 146 de 146")
  assert.equal(sectionProgressLabel({ standard: "ESRS E1", counts: { total: 30, decided: 12 } }), "ESRS E1 — 12 de 30")
})

test("localStorageDraftKey builds the documented key", () => {
  assert.equal(localStorageDraftKey(42), "p9_drafts_42")
  assert.equal(localStorageDraftKey(null), "p9_drafts_unknown")
})

test("compactDrafts now preserves rows that have ONLY triage set and emits triage in payload", () => {
  const out = compactDrafts({
    "DP-ONLY-TRIAGE": { status: "draft", value: "", evidence_reference: "", note: "", triage: "need_to_find" },
    "DP-FULL": { status: "completed", value: "42", triage: "have_it" },
  })
  assert.equal(out.length, 2)
  const only = out.find((o) => o.datapoint_id === "DP-ONLY-TRIAGE")
  assert.ok(only)
  assert.equal(only.triage, "need_to_find")
  assert.equal(only.status, "draft")
  const full = out.find((o) => o.datapoint_id === "DP-FULL")
  assert.equal(full.triage, "have_it")
})

test("structured P9 fact helpers create safe defaults and compact facts deterministically", () => {
  assert.equal(factKindRequiresDecimals("monetary"), true)
  assert.equal(factKindRequiresDecimals("narrative"), false)
  assert.equal(factKindRequiresUnit("percent"), true)
  assert.equal(factKindUnitPlaceholder("monetary"), "iso4217:EUR")
  assert.deepEqual(createDefaultFact("percent").unit, { measure: "pure" })

  const out = compactDrafts({
    "BP-1_01": {
      status: "completed",
      facts: [{
        value_kind: "monetary",
        value: " 123.40 ",
        decimals: 2,
        unit: { measure: "iso4217:EUR" },
        context: {
          period_type: "duration",
          start_date: "2025-01-01",
          end_date: "2025-12-31",
          dimensions: [
            { axis: "z:Axis", member: "z:Member" },
            { axis: "a:Axis", member: "a:Member" },
          ],
        },
        evidence_reference: " Ledger ",
      }],
    },
  })

  assert.equal(out[0].facts[0].value, "123.40")
  assert.equal(out[0].facts[0].context.dimensions[0].axis, "a:Axis")
  assert.equal(out[0].facts[0].evidence_reference, "Ledger")
})
test("obligationBadge prefers backend selection state over may_disclose", () => {
  assert.deepEqual(
    obligationBadge({
      may_disclose: false,
      selection: { default_selected: false, reason_codes: ["phase_in_less_than_750"] },
    }),
    { kind: "deferred", label: "Aplazable (phase-in)" },
  )
  assert.equal(
    obligationBadge({
      may_disclose: true,
      selection: { default_selected: false, reason_codes: ["voluntary_may_disclose"] },
    }).kind,
    "voluntary",
  )
  assert.equal(
    obligationBadge({
      may_disclose: false,
      conditional_or_alternative: "Conditional",
      selection: { default_selected: true, reason_codes: [] },
    }).kind,
    "conditional",
  )
  assert.equal(obligationBadge({ may_disclose: false }).kind, "mandatory")
})

test("applyObligationFilter mandatory_only keeps only default-selected datapoints when selection is present", () => {
  const rows = [
    { datapoint: { id: "A", may_disclose: false, selection: { default_selected: true, reason_codes: [] } } },
    { datapoint: { id: "B", may_disclose: false, selection: { default_selected: false, reason_codes: ["phase_in_all_undertakings"] } } },
    { datapoint: { id: "C", may_disclose: true, selection: { default_selected: false, reason_codes: ["voluntary_may_disclose"] } } },
    { datapoint: { id: "D", may_disclose: true } },
  ]
  const filtered = applyObligationFilter(rows, "mandatory_only")
  assert.deepEqual(filtered.map((r) => r.datapoint.id), ["A"])
})

test("honestCountsLabel uses backend required counts when present", () => {
  assert.equal(
    honestCountsLabel({
      total_datapoint_count: 10,
      voluntary_datapoint_count: 2,
      required_datapoint_count: 6,
      default_unselected_datapoint_count: 4,
    }),
    "6 requeridos + 4 no seleccionados por defecto (voluntarios o aplazables)",
  )
  assert.equal(
    honestCountsLabel({ total_datapoint_count: 10, voluntary_datapoint_count: 2 }),
    "8 obligatorios + 2 voluntarios (opcionales)",
  )
})

test("groupRowsByStandard counts mandatory by default_selected when selection is present", () => {
  const rows = [
    { datapoint: { id: "A", standard: "ESRS 2", may_disclose: false, selection: { default_selected: true, reason_codes: [] } } },
    { datapoint: { id: "B", standard: "ESRS 2", may_disclose: false, selection: { default_selected: false, reason_codes: ["phase_in_less_than_750"] } } },
    { datapoint: { id: "C", standard: "ESRS 2", may_disclose: true, selection: { default_selected: false, reason_codes: ["voluntary_may_disclose"] } } },
  ]
  const groups = groupRowsByStandard(rows)
  assert.equal(groups[0].counts.mandatory, 1)
  assert.equal(groups[0].counts.voluntary, 2)
})

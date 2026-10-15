import test from "node:test"
import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { createHash } from "node:crypto"
import { declaredShare, taxonomyPayload, taxonomyTitle, taxonomyDisclaimer, taxonomyCoverage, taxonomyClassification, objectiveLabels } from "../lib/eu-taxonomy-state.mjs"
import { ui } from "../lib/i18n/messages.mjs"

test("declared percentages preserve unknown and zero and reject invalid ranges", () => {
  assert.equal(declaredShare(""), null)
  assert.equal(declaredShare("0"), 0)
  assert.equal(declaredShare("12.5"), 12.5)
  for (const value of ["=1+1", "NaN", "Infinity", "-1", "100.1"]) assert.throws(() => declaredShare(value))
})

test("only explicitly confirmed activities are submitted with optimistic concurrency witnesses", () => {
  const screen = { source_hash: "source", revision: 3, activities: [{ id: "water" }, { id: "waste" }] }
  const edits = { water: { confirmed: true, turnover_share: "12.5", capex_share: "", opex_share: "0" }, waste: { confirmed: false, turnover_share: "99" } }
  assert.deepEqual(taxonomyPayload(screen, edits), { source_hash: "source", revision: 3, activities: [{ activity_id: "water", turnover_share: 12.5, capex_share: null, opex_share: 0 }] })
  assert.deepEqual(taxonomyPayload(screen, {}).activities, [])
})

test("all taxonomy notices and objective labels have English presentation", () => {
  for (const text of [taxonomyTitle, taxonomyDisclaimer, taxonomyCoverage, taxonomyClassification, ...Object.values(objectiveLabels)]) {
    assert.notEqual(ui("en", text), text)
    assert.equal(ui("es", text), text)
  }
  assert.match(ui("en", taxonomyDisclaimer), /eligibility is not EU Taxonomy alignment/)
})

test("catalogue checksum and annex counts agree and every NACE code occurs in both official excerpts", () => {
  const path = new URL("../../web/data/eu_taxonomy_activities_v1.json", import.meta.url)
  const bytes = readFileSync(path)
  const expected = readFileSync(new URL(`${path}.sha256`), "utf8").trim().split(" ")[0]
  assert.equal(createHash("sha256").update(bytes).digest("hex"), expected)
  const catalogue = JSON.parse(bytes)
  assert.equal(catalogue.complete, false)
  assert.equal(new Set(catalogue.activities.map((row) => row.id)).size, catalogue.activities.length)
  for (const coverage of catalogue.coverage) {
    assert.equal(catalogue.activities.filter((row) => row.source.celex === coverage.celex && row.source.annex === coverage.annex).length, coverage.included_count)
    assert.ok(coverage.included_count < coverage.source_heading_count)
  }
  for (const row of catalogue.activities) {
    assert.ok(row.name.es && row.name.en)
    for (const code of row.nace_codes) {
      assert.ok(row.nace_source_text.en.includes(code))
      assert.ok(row.nace_source_text.es.includes(code))
    }
  }
})

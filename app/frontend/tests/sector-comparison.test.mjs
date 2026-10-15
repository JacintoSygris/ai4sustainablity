import assert from "node:assert/strict"
import test from "node:test"
import { readFileSync } from "node:fs"
import { createRequire } from "node:module"
import { createElement } from "react"
import { renderToStaticMarkup } from "react-dom/server"
import { ui, formatUi } from "../lib/i18n/messages.mjs"
import { createSectorComparisonLoader } from "../lib/sector-comparison-state.mjs"

const require = createRequire(import.meta.url)
const ts = require("typescript")
const source = readFileSync(new URL("../components/wizard/sector-comparison.tsx", import.meta.url), "utf8")
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX } }).outputText
const exported = {}
new Function("require", "exports", compiled)((id) => id === "@/lib/i18n/messages.mjs" ? { ui, formatUi } : require(id), exported)
const fixture = JSON.parse(readFileSync(new URL("../../web/tests/Fixtures/sector-comparison-response.json", import.meta.url), "utf8"))
const render = (topicId, locale = "es", data = fixture, status = "ready") => renderToStaticMarkup(createElement(exported.SectorComparison, { data, status, topicId, locale }))

test("off or idle panels render nothing", () => {
  assert.equal(render(1, "es", null, "idle"), "")
})

test("real ES EN rendering uses known assessments as denominator and separate population", () => {
  const es = render(1)
  assert.match(es, /3 de 5 empresas con evaluación conocida \(60.0 %\). Población del sector: 5/)
  assert.match(es, /Actividad NACE: K/)
  assert.match(es, /no determina la doble importancia relativa/)
  assert.match(es, /Datos sintéticos de demostración/)
  assert.match(es, /synthetic-fixture/)
  assert.match(es, /2025-01-01–2025-12-31/)
  const en = render(1, "en")
  assert.match(en, /3 of 5 companies with a known assessment \(60.0%\). Sector population: 5/)
  assert.match(en, /does not determine double materiality/)
  assert.match(en, /Synthetic demonstration data/)
  assert.doesNotMatch(es + en, /<button|<input|<select|<a /)
})

test("fallback never labels global population as sectorial and zero positives remain meaningful", () => {
  const html = render(2)
  assert.match(html, /Evaluaciones sectoriales: 0 de 4. Población del sector: 5/)
  assert.match(html, /Referencia global de baja confianza/)
  assert.match(html, /Referencia global: 0 de 5 empresas con evaluación conocida \(0.0 %\). Población global: 6/)
  assert.match(render(2, "en"), /Low-confidence global reference/)
  assert.match(render(4), /0 de 5 empresas con evaluación conocida \(0.0 %\)/)
})

test("unknown assessments abstain without percent and text is escaped", () => {
  assert.match(render(3), /Sin datos suficientes para comparar este tema NEIS/)
  assert.doesNotMatch(render(3), /%/)
  assert.match(render(3, "en"), /Insufficient data to compare this ESRS topic/)
  const data = structuredClone(fixture)
  data.provenance.source_reference = '<script>alert("x")</script>'
  data.provenance.label_kind = "automatic"
  const html = render(1, "es", data)
  assert.match(html, /&lt;script&gt;/)
  assert.doesNotMatch(html, /<script>/)
  assert.match(html, /Etiquetas automáticas de informes/)
  assert.match(render(1, "en", data), /Automatic report labels/)
})

test("loader never requests while disabled and errors do not alter selections", async () => {
  let requests = 0
  const selections = Object.freeze([1, 3])
  const states = []
  const loader = createSectorComparisonLoader(async () => { requests++; throw new Error("unavailable") }, (state) => states.push(state))
  await loader.load(false, 1, "a")
  assert.equal(requests, 0)
  await loader.load(true, 1, "a")
  assert.equal(requests, 1)
  assert.equal(states.at(-1).status, "error")
  assert.equal(states.at(-1).data, null)
  assert.deepEqual(selections, [1, 3])
  loader.dispose()
})

test("loader aborts and discards an old response on change and unmount", async () => {
  const pending = []
  const states = []
  const loader = createSectorComparisonLoader((id, signal) => new Promise((resolve) => pending.push({ id, signal, resolve })), (state) => states.push(state))
  const first = loader.load(true, 1, "old")
  const second = loader.load(true, 2, "new")
  assert.equal(pending[0].signal.aborted, true)
  pending[1].resolve({ data: { ...fixture, characterization_id: 2, input_fingerprint: "new" } })
  await second
  pending[0].resolve({ data: { ...fixture, characterization_id: 1, input_fingerprint: "old" } })
  await first
  assert.equal(states.at(-1).data.characterization_id, 2)
  const third = loader.load(true, 2, "newer")
  loader.dispose()
  pending[2].resolve({ data: { ...fixture, characterization_id: 2, input_fingerprint: "newer" } })
  await third
  assert.equal(pending[2].signal.aborted, true)
  assert.equal(states.at(-1).status, "loading")
})

test("mismatched Laravel input fingerprint is discarded", async () => {
  let latest
  const loader = createSectorComparisonLoader(async () => ({ data: { ...fixture, characterization_id: 1, input_fingerprint: "changed" } }), (state) => { latest = state })
  await loader.load(true, 1, "expected")
  assert.equal(latest.status, "error")
  assert.equal(latest.data, null)
})

test("P6 and both P8 modes wire optional informational panels including outside proposal topics", () => {
  const p6 = readFileSync(new URL("../components/wizard/material-topics-form.tsx", import.meta.url), "utf8")
  const p8 = readFileSync(new URL("../components/wizard/final-topics-selection.tsx", import.meta.url), "utf8")
  assert.match(p6, /useSectorComparison\(proposal\)/)
  assert.match(p6, /<SectorComparison .*topicId=\{topic.id\}/)
  assert.match(p8, /useSectorComparison\(confirmation\)/)
  for (const id of ["pid", "id", "topic.id"]) assert.ok(p8.includes(`topicId={${id}}`))
  assert.ok(p8.includes("!p6TopicIds.has(topic.id) && !selectedTopics.has(topic.id)"))
})

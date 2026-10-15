import assert from "node:assert/strict"
import test from "node:test"
import { readFileSync } from "node:fs"
import { createRequire } from "node:module"
import { createElement } from "react"
import { renderToStaticMarkup } from "react-dom/server"
import { ui } from "../lib/i18n/messages.mjs"

const require = createRequire(import.meta.url)
const ts = require("typescript")
const source = readFileSync(new URL("../components/wizard/concept-relations.tsx", import.meta.url), "utf8")
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX } }).outputText
const exported = {}
new Function("require", "exports", compiled)((id) => id === "@/lib/i18n/messages.mjs" ? { ui } : require(id), exported)
const render = (props) => renderToStaticMarkup(createElement(exported.ConceptRelations, props))

test("optional hints render nothing when disabled or empty", () => {
  assert.equal(render({ kind: "topics", locale: "es" }), "")
  assert.equal(render({ kind: "datapoints", locale: "es", items: [] }), "")
})

test("topic hints explain silver-label evidence in Spanish and English without decision controls", () => {
  const props = { kind: "topics", items: [{ id: 1, esrs_code: "E1", label: "Energía" }] }
  const es = render({ ...props, locale: "es" })
  assert.match(es, /Temas que suelen reportarse juntos/)
  assert.match(es, /no determinan la doble importancia relativa/)
  assert.match(es, /E1 · Energía/)
  const en = render({ ...props, locale: "en" })
  assert.match(en, /Topics often reported together/)
  assert.match(en, /do not determine double materiality/)
  assert.doesNotMatch(es + en, /<button|<input|<a /)
})

test("datapoint hints escape labels and preserve canonical IDs", () => {
  const props = { kind: "datapoints", items: [{ id: "E1-1_01", label: '<script>alert("x")</script>' }] }
  const es = render({ ...props, locale: "es" })
  assert.match(es, /Conceptos relacionados/)
  assert.match(es, /no modifica las obligaciones de información/)
  assert.match(es, /E1-1_01/)
  assert.match(es, /&lt;script&gt;/)
  assert.doesNotMatch(es, /<script>/)
  assert.match(render({ ...props, locale: "en" }), /Related concepts/)
})

import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import test from "node:test"
import { ui } from "../lib/i18n/messages.mjs"

const source = readFileSync(new URL("../components/wizard/topic-signal-assistant.tsx", import.meta.url), "utf8")

// Source regressions, like the existing a11y suite. Native radios supply Tab,
// arrow-key and Space behaviour; this does not claim a browser/AT audit.
test("each guided signal has a named fieldset and labelled native radio state", () => {
  const fields = [...source.matchAll(/<fieldset>([\s\S]*?)<\/fieldset>/g)].map(match => match[1])
  assert.equal(fields.length, 4)
  assert.match(source, /const assistantId = useId\(\)/)
  const keys = ["impacto", "financiero", "confianza", "exposicion"]
  for (const [index, field] of fields.entries()) {
    const question = field.match(/<legend[^>]*>\{tr\("([^"]+)"\)\}<\/legend>/)?.[1]
    assert.ok(question, keys[index])
    assert.notEqual(ui("en", question), question, "questions retain English copy")
    assert.match(field, /<label[^>]*>[\s\S]*?<input[\s\S]*?type="radio"[\s\S]*?\{tr\([A-Z_]+\[v\]\)\}[\s\S]*?<\/label>/)
    assert.ok(field.includes('name={`${assistantId}-' + keys[index] + '`}'))
    assert.ok(field.includes(`onChange={() => setSig("${keys[index]}", v)}`))
    assert.ok(field.includes(`checked={${index === 3 ? "currentExp" : "signals." + keys[index]} === v}`))
    assert.match(field, /focus-visible:/)
    assert.doesNotMatch(field, /<Button|tabIndex=|onKeyDown=/)
  }
})

test("suggestion changes are announced and the optional note has an associated label", () => {
  assert.match(source, /role="status" aria-live="polite" aria-atomic="true"/)
  assert.match(source, /<label htmlFor=\{`\$\{assistantId\}-note`\}[^>]*>\{tr\("Nota opcional \(máx 300\)"\)\}/)
  assert.match(source, /<Textarea\s+id=\{`\$\{assistantId\}-note`\}/)
  assert.notEqual(ui("en", "Nota opcional (máx 300)"), "Nota opcional (máx 300)")
})

// Execute the component and click its actual button handler, using the same
// TSX/VM approach as consent-consumers. Hook storage replaces the renderer only.
test("explicit confirmation records unchanged defaults without recording on mount", async () => {
  const { default: ts } = await import("typescript")
  const { default: vm } = await import("node:vm")
  const { createRequire } = await import("node:module")
  const require = createRequire(import.meta.url)
  const guided = await import("../lib/materiality-guided-state.mjs")
  const titles = await import("../lib/materiality-confirmation-state.mjs")
  const translations = await import("../lib/i18n/messages.mjs")
  const output = ts.transpileModule(source, { compilerOptions: {
    jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022,
  } }).outputText
  for (const locale of ["es", "en"]) {
    for (const exposicionDefault of ["normal", "fuerte"]) {
      const exports = {}
      const modules = {
        react: { useId: () => "synthetic-assistant", useMemo: fn => fn(),
          useState: initial => [typeof initial === "function" ? initial() : initial, () => {}] },
        "@/components/locale-provider": { useLocale: () => ({ locale }) },
        "@/components/ui/button": { Button: "button" },
        "@/components/ui/badge": { Badge: "span" },
        "@/components/ui/textarea": { Textarea: "textarea" },
        "@/lib/materiality-guided-state.mjs": guided,
        "@/lib/materiality-confirmation-state.mjs": titles,
        "@/lib/i18n/messages.mjs": translations,
      }
      vm.runInNewContext(`(function(require,exports){${output}\n})`)(
        spec => modules[spec] ?? require(spec), exports)
      const results = []
      const tree = exports.TopicSignalAssistant({ topic: { id: 1, esrs_code: "E1" },
        exposicionDefault, onResult: answer => results.push(answer) })
      assert.equal(results.length, 0, "mount must not record a user answer")
      const elements = []
      function visit(node) {
        if (Array.isArray(node)) { node.forEach(visit); return }
        if (!node?.props) return
        elements.push(node)
        visit(node.props.children)
      }
      visit(tree)
      const selected = elements.filter(node => node.type === "input" && node.props.checked)
      assert.deepEqual(selected.map(node => node.props.value), ["bajo", "bajo", "media", exposicionDefault])
      const confirm = elements.find(node => node.type === "button")
      assert.ok(confirm)
      assert.equal(confirm.props.type, "button", "do not submit the surrounding wizard form")
      assert.equal(confirm.props.children, ui(locale, "Confirmar respuestas actuales"))
      assert.notEqual(ui("en", "Confirmar respuestas actuales"), "Confirmar respuestas actuales")
      confirm.props.onClick()
      assert.equal(results.length, 1)
      assert.deepEqual(results[0], guided.buildGuidedAnswerForUserEdit({
        impacto: "bajo", financiero: "bajo", confianza: "media", exposicion: exposicionDefault,
      }, "", true))
    }
  }
})

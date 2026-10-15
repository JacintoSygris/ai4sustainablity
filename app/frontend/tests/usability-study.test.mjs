import { test } from "node:test"
import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { createStudyTracker, studyStep, subscribeStudySignals, completeStudyTask, studyTaskError } from "../lib/usability-study.mjs"
import { SUS_ITEMS, STUDY_CONSENT } from "../lib/usability-questionnaire.mjs"
import { ui, hasEnglishCopy } from "../lib/i18n/messages.mjs"

function fixture(overrides = {}) {
  const events = [], failures = [], completed = []
  let id = 0
  const tracker = createStudyTracker({ send: async (event, keepalive) => events.push({ ...event, keepalive }),
    uuid: () => `test-only-${++id}`, onComplete: (step) => completed.push(step), onFailure: () => failures.push(true), ...overrides })
  return { tracker, events, failures, completed }
}

test("does not send events without explicit enrolment", async () => {
  const { tracker, events } = fixture()
  tracker.enter("/wizard/step-1"); tracker.signal({ type: "complete", step: "P5" }); tracker.abandon()
  await tracker.flush(); assert.deepEqual(events, [])
})
test("maps the existing six wizard pages to canonical P5–P10 codes", () => {
  assert.deepEqual([1,2,3,4,5,6].map((step) => studyStep(`/wizard/step-${step}`)), ["P5","P6","P7","P8","P9","P10"])
  assert.equal(studyStep("/dashboard"), null); assert.equal(studyStep("/wizard/step-7"), null)
})
test("records start before completion, counts errors without text, and ignores duplicate completion", async () => {
  const { tracker, events, completed } = fixture()
  tracker.setParticipant("synthetic-test-only"); tracker.enter("/wizard/step-1")
  tracker.signal({ type: "error", step: "P5" }); tracker.signal({ type: "error", step: "P6" })
  tracker.signal({ type: "complete", step: "P5" }); tracker.signal({ type: "complete", step: "P5" })
  tracker.enter("/wizard/step-2"); await tracker.flush()
  assert.deepEqual(events.map((event) => event.event), ["start", "complete", "start"])
  assert.equal(events[1].error_count, 1); assert.deepEqual(completed, ["P5"])
  assert.deepEqual(Object.keys(events[1]).sort(), ["error_count","event","keepalive","step","study_participant_id","task_id"])
})
test("navigation and page exit abandon only unfinished tasks", async () => {
  const { tracker, events } = fixture()
  tracker.setParticipant("synthetic-test-only"); tracker.enter("/wizard/step-5")
  tracker.enter("/dashboard"); await tracker.flush()
  tracker.enter("/wizard/step-6"); tracker.abandon(true); tracker.abandon(true); await tracker.flush()
  assert.deepEqual(events.map((event) => event.event), ["start", "abandon", "start", "abandon"])
  assert.equal(events[3].keepalive, true)
})
test("withdrawal invalidates queued work, including after re-enrolment", async () => {
  const { tracker, events } = fixture()
  tracker.setParticipant("old-test-only"); tracker.enter("/wizard/step-1")
  tracker.setParticipant(null); tracker.setParticipant("new-test-only"); tracker.enter("/wizard/step-1")
  await tracker.flush(); assert.equal(events.length, 1); assert.equal(events[0].study_participant_id, "new-test-only")
})
test("failed starts cannot manufacture successful terminal records", async () => {
  const { tracker, events, failures, completed } = fixture({ send: async () => { throw new Error("test-only") } })
  tracker.setParticipant("synthetic-test-only"); tracker.enter("/wizard/step-6"); tracker.signal({ type: "complete", step: "P10" })
  await tracker.flush(); assert.equal(failures.length, 1); assert.deepEqual(completed, []); assert.deepEqual(events, [])
})
test("signals are inert before the flag-gated kit subscribes and after cleanup", () => {
  const signals = []; completeStudyTask("P5")
  const unsubscribe = subscribeStudySignals((signal) => signals.push(signal))
  completeStudyTask("P5"); studyTaskError("P6"); unsubscribe(); completeStudyTask("P10")
  assert.deepEqual(signals, [{ type: "complete", step: "P5" }, { type: "error", step: "P6" }])
})
test("published SUS wording and consent have English catalogue entries", () => {
  assert.equal(SUS_ITEMS.length, 10)
  for (const message of [...SUS_ITEMS, ...STUDY_CONSENT]) {
    assert.equal(hasEnglishCopy(message), true); assert.notEqual(ui("en", message), message); assert.equal(ui("es", message), message)
  }
})
test("the kit mounts only under the Laravel flag, requires explicit consent and uses no optional storage", () => {
  const layout = readFileSync(new URL("../app/(dashboard)/layout.tsx", import.meta.url), "utf8")
  const kit = readFileSync(new URL("../components/usability/study-kit.tsx", import.meta.url), "utf8")
  assert.match(layout, /session\.usability_study_enabled === true/)
  assert.match(kit, /type="checkbox" required checked=\{consent\}/)
  assert.match(kit, /if \(!consent \|\| !study\) return/)
  assert.match(kit, /method: "DELETE"/)
  assert.doesNotMatch(kit, /localStorage|sessionStorage|document\.cookie|dangerouslySetInnerHTML/)
})

test("missing secure UUID support does not break the underlying wizard", async () => {
  const { tracker, failures } = fixture({ uuid: () => { throw new Error("test-only") } })
  tracker.setParticipant("synthetic-test-only")
  assert.doesNotThrow(() => tracker.enter("/wizard/step-1"))
  await tracker.flush(); assert.equal(failures.length, 1)
})

test("an in-flight old enrolment cannot reopen SUS after withdrawal", async () => {
  let release
  const pending = new Promise((resolve) => { release = resolve })
  const events = [], completed = []
  const { tracker } = fixture({ send: async (event) => {
    events.push(event)
    if (event.event === "complete") await pending
  }, onComplete: (step) => completed.push(step) })
  tracker.setParticipant("old-test-only"); tracker.enter("/wizard/step-6")
  await tracker.flush()
  tracker.signal({ type: "complete", step: "P10" })
  await Promise.resolve(); tracker.setParticipant(null); release()
  await tracker.flush(); assert.deepEqual(completed, [])
  assert.equal(events.length, 2)
})

test("the real error hook does not count translation or a repeated notice as another error", async () => {
  const ts = await import("typescript")
  const vm = await import("node:vm")
  const source = readFileSync(new URL("../lib/use-study-error.ts", import.meta.url), "utf8")
  const output = ts.default.transpileModule(source, { compilerOptions: { module: ts.default.ModuleKind.CommonJS } }).outputText
  const signals = []
  let reference, locale = "es"
  const exports = {}
  const require = (name) => {
    if (name === "react") return {
      useRef: (value) => reference ??= { current: value },
      useEffect: (effect) => effect(),
    }
    if (name.includes("locale-provider")) return { useLocale: () => ({ locale }) }
    if (name.includes("usability-study")) return { STUDY_STEPS: ["P5","P6","P7","P8","P9","P10"], studyTaskError: (step) => signals.push(step) }
    throw new Error(`Unexpected module: ${name}`)
  }
  vm.runInNewContext(`(function(require,exports){${output}\n})`)(require, exports)
  exports.useStudyError(1, null)
  exports.useStudyError(1, "test error in Spanish")
  exports.useStudyError(1, "test error in Spanish")
  locale = "en"; exports.useStudyError(1, "test error in English")
  exports.useStudyError(1, null); exports.useStudyError(1, "another test error")
  assert.deepEqual(signals, ["P5", "P5"])
})

// Exercise the actual TSX and effect cleanup order with a minimal hook runtime.
// This is a Node regression test, not a real browser qualification.
async function mountKit({ server, send } = {}) {
  const ts = (await import('typescript')).default
  const vm = await import('node:vm')
  const source = readFileSync(new URL('../components/usability/study-kit.tsx', import.meta.url), 'utf8')
  const output = ts.transpileModule(source, { compilerOptions: {
    module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX,
  } }).outputText
  server ??= { consent_version: 'test-only', study_participant_id: 'synthetic-test-only', consented: true,
    questionnaire_submitted: false, p10_completed: false, questionnaire: null }
  const hooks = [], events = [], listeners = new Map(), effects = []
  let cursor = 0, dirty = true, tree, path = '/wizard/step-6', tracker, mounted = true, id = 0
  function state(value) {
    const index = cursor++
    hooks[index] ??= { value: typeof value === 'function' ? value() : value }
    return [hooks[index].value, (next) => {
      assert.equal(mounted, true, 'component received a state update after unmount')
      hooks[index].value = typeof next === 'function' ? next(hooks[index].value) : next
      dirty = true
    }]
  }
  const require = (name) => {
    if (name === 'react') return {
      useState: state,
      useRef: (value) => { const index = cursor++; return hooks[index] ??= { current: value } },
      useEffect: (effect, deps) => {
        const index = cursor++, old = hooks[index]
        if (!old || deps.some((value, i) => !Object.is(value, old.deps[i]))) {
          effects.push(() => { old?.cleanup?.(); hooks[index] = { deps, cleanup: effect() } })
        }
      },
    }
    if (name === 'react/jsx-runtime') return { jsx: (type, props) => ({ type, props }), jsxs: (type, props) => ({ type, props }), Fragment: 'Fragment' }
    if (name === 'next/navigation') return { usePathname: () => path }
    if (name === 'next/link') return { default: 'Link' }
    if (name.endsWith('/button')) return { Button: 'Button' }
    if (name.endsWith('/locale-provider')) return { useLocale: () => ({ locale: 'es' }) }
    if (name.endsWith('/messages.mjs')) return { ui }
    if (name.endsWith('/usability-questionnaire.mjs')) return { SUS_ITEMS, STUDY_CONSENT }
    if (name.endsWith('/usability-study.mjs')) return { studyStep, STUDY_STEPS: ['P5','P6','P7','P8','P9','P10'], subscribeStudySignals,
      createStudyTracker: (options) => tracker = createStudyTracker(options) }
    if (name.endsWith('/laravel-api')) return { laravelApi: async (url, options = {}) => {
      if (url.endsWith('/events')) {
        events.push({ ...options.body, keepalive: options.keepalive })
        await send?.(options.body)
        if (options.body.step === 'P10' && options.body.event === 'complete') server.p10_completed = true
        return
      }
      if (url.endsWith('/questionnaire')) {
        server.questionnaire = { answers: [...options.body.answers], comments: options.body.comments, locale: 'es' }
        server.questionnaire_submitted = true
        return
      }
      assert.equal(url, '/usability-study')
      return { data: structuredClone(server) }
    } }
    throw new Error(`Unexpected module: ${name}`)
  }
  const exports = {}
  vm.runInNewContext(`(function(require,exports){${output}\n})`, {
    crypto: { randomUUID: () => `test-only-${++id}` },
    window: { location: { pathname: path }, addEventListener: (key, cb) => listeners.set(key, cb), removeEventListener: (key) => listeners.delete(key) },
  })(require, exports)
  const render = () => { cursor = 0; dirty = false; tree = exports.StudyKit({ children: null }); effects.splice(0).forEach((effect) => effect()) }
  async function settle() {
    for (let i = 0; i < 20; i++) {
      if (dirty) render()
      await Promise.resolve()
      await tracker?.flush()
      await new Promise((resolve) => setImmediate(resolve))
      if (!dirty) return
    }
    assert.fail('component did not settle')
  }
  function walk(node) {
    if (Array.isArray(node)) return node.flatMap((child) => walk(child))
    if (!node || typeof node !== 'object') return []
    return [node, ...walk(node.props?.children)]
  }
  const nodes = () => walk(tree)
  function button(label) {
    const node = nodes().find((node) => node.type === 'Button' && node.props.children === label)
    assert.ok(node, `Missing button: ${label}`)
    return node
  }
  function input(name, value) { return nodes().find((node) => node.type === 'input' && node.props.name === name && Number(node.props.value) === value) }
  render()
  return { settle, events, server, button, input, render, tracker: () => tracker,
    navigate: async (next) => { path = next; dirty = true; await settle() }, nodes,
    submit: async () => { nodes().find((node) => node.type === 'form').props.onSubmit({ preventDefault() {} }); await settle() },
    unmount: () => { hooks.forEach((hook) => hook?.cleanup?.()); mounted = false },
  }
}

test('actual layout unmount drains start then keepalive abandonment without revoking consent', async () => {
  let release
  const pending = new Promise((resolve) => { release = resolve })
  const kit = await mountKit({ send: async (event) => { if (event.event === 'start') await pending } })
  await Promise.resolve(); kit.render(); await Promise.resolve()
  kit.tracker().signal({ type: 'error', step: 'P10' })
  kit.unmount(); release(); await kit.tracker().flush()
  assert.deepEqual(kit.events.map((event) => event.event), ['start', 'abandon'])
  assert.equal(kit.events[1].keepalive, true)
  assert.equal(kit.events[1].error_count, 1)
})

test('actual P10 button completes each new visit regardless of historical completion', async () => {
  const kit = await mountKit()
  await kit.settle()
  const finish = 'He terminado de revisar el informe y sus descargas'
  kit.button(finish).props.onClick(); await kit.settle()
  kit.button('Cerrar').props.onClick(); await kit.settle()
  assert.equal(kit.nodes().some((node) => node.type === 'Button' && node.props.children === finish), false)
  await kit.navigate('/dashboard'); await kit.navigate('/wizard/step-6')
  kit.button(finish).props.onClick(); await kit.settle()
  kit.unmount(); await kit.tracker().flush()
  assert.deepEqual(kit.events.map((event) => event.event), ['start', 'complete', 'start', 'complete'])
  assert.notEqual(kit.events[0].task_id, kit.events[2].task_id)
})

test('actual SUS save, reopen, answer-only edit and remount preserve the saved comment', async () => {
  const kit = await mountKit()
  await kit.settle()
  kit.button('Cuestionario de usabilidad SUS').props.onClick(); await kit.settle()
  for (let i = 0; i < 10; i++) kit.input(`sus-${i}`, 3).props.onChange()
  kit.nodes().find((node) => node.type === 'textarea').props.onChange({ target: { value: 'synthetic-test-only comment' } })
  await kit.settle(); await kit.submit()
  kit.button('Cuestionario de usabilidad SUS').props.onClick(); await kit.settle()
  assert.equal(kit.nodes().find((node) => node.type === 'textarea').props.value, 'synthetic-test-only comment')
  kit.input('sus-0', 4).props.onChange(); await kit.settle(); await kit.submit()
  assert.equal(kit.server.questionnaire.answers[0], 4)
  assert.equal(kit.server.questionnaire.comments, 'synthetic-test-only comment')
  kit.unmount(); await kit.tracker().flush()
  const restored = await mountKit({ server: kit.server })
  await restored.settle(); restored.button('Cuestionario de usabilidad SUS').props.onClick(); await restored.settle()
  assert.equal(restored.input('sus-0', 4).props.checked, true)
  assert.equal(restored.nodes().find((node) => node.type === 'textarea').props.value, 'synthetic-test-only comment')
  restored.nodes().find((node) => node.type === 'textarea').props.onChange({ target: { value: '' } })
  await restored.settle(); await restored.submit()
  assert.equal(restored.server.questionnaire.comments, '')
  restored.unmount(); await restored.tracker().flush()
})

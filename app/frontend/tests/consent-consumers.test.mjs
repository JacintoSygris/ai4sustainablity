import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, existsSync } from 'node:fs'
import { createRequire } from 'node:module'
import vm from 'node:vm'
import ts from 'typescript'
import React from 'react'
import { harness } from './consent-core.test.mjs'
import * as core from '../public/consent/core.mjs'
import { mountConsent } from '../public/consent/ui.mjs'
import { fakeDocument } from './consent-ui.test.mjs'
import { connectRealtime } from '../public/consent/realtime.mjs'

const require = createRequire(import.meta.url)
const pure = {}
for (const name of ['esrs-datapoints-state', 'materiality-confirmation-state', 'materiality-confirmation-draft', 'materiality-guided-state']) pure[`@/lib/${name}.mjs`] = await import(`../lib/${name}.mjs`)
const frontend = new URL('../', import.meta.url)
class ApiError extends Error { constructor(status) { super('synthetic transport failure'); this.status = status } }

// Execute actual TSX, hooks and pure business helpers. Only network/navigation are replaced.
// The dispatcher supplies deterministic render/effect scheduling without a browser dependency.
function consumer(path, exportName, handlers, h, transport = {}) {
  let cursor = 0, pending = [], captured = {}, tree, rerender = false
  const slots = [], effects = [], timers = []
  const dispatcher = {
    useState(initial) { const i = cursor++; if (!(i in slots)) slots[i] = typeof initial === 'function' ? initial() : initial
      return [slots[i], value => { slots[i] = typeof value === 'function' ? value(slots[i]) : value; rerender = true }] },
    useReducer(reducer, initial) { const [value, set] = this.useState(initial); return [value, action => set(v => reducer(v, action))] },
    useRef(initial) { const i = cursor++; return slots[i] ??= { current: initial } },
    useMemo(fn) { cursor++; return fn() }, useCallback(fn) { cursor++; return fn },
    useEffect(fn, deps) { const i = cursor++, old = effects[i]; if (!old || deps.some((d, n) => d !== old.deps[n])) { pending.push(() => { old?.off?.(); effects[i] = { deps, off: fn() } }) } },
    useContext(context) { return context._currentValue }, useId() { return `id-${cursor++}` },
  }
  const win = { [Symbol.for('airis.consent.2026-09-26.1')]: h.api, localStorage: h.env.storage(), addEventListener() {}, removeEventListener() {}, matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }) }
  const document = { cookie: '' }
  const cache = new Map(), router = { push() {}, refresh() {}, replace() {} }
  const context = vm.createContext({ window: win, document, console, URLSearchParams, Set, Map, Date, crypto: { randomUUID: () => 'synthetic-tab' },
    setTimeout(fn) { timers.push(fn); return timers.length }, clearTimeout() {}, __capture(value) { captured = value } })
  function load(spec) {
    if (spec === 'react') return React
    if (spec === 'next/navigation') return { useRouter: () => router }
    if (spec === '@/lib/laravel-api') return { LaravelApiError: ApiError, laravelApiUrl: p => p, ...transport }
    if (pure[spec]) return pure[spec]
    if (spec.includes('public/consent/core.mjs')) return { ...core, getBrowserConsent: () => h.api }
    if (spec === './wizard-steps-data.mjs') return require('../lib/wizard-steps-data.mjs')
    if (!spec.startsWith('@/') && !spec.startsWith('../public/')) return require(spec)
    const relative = spec.replace(/^@\//, '')
    const file = ['','.ts','.tsx'].map(ext => new URL(relative + ext, frontend)).find(url => existsSync(url))
    if (!file) throw Error(`Missing actual module ${spec}`)
    if (cache.has(file.href)) return cache.get(file.href)
    let source = readFileSync(file, 'utf8')
    if (relative === path) {
      const ast = ts.createSourceFile('component.tsx', source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX)
      const component = ast.statements.find(n => ts.isFunctionDeclaration(n) && n.name?.text === exportName)
      const ret = component.body.statements.filter(ts.isReturnStatement).at(-1)
      source = source.slice(0, ret.getStart(ast)) + `globalThis.__capture({${handlers.join(',')}});\n` + source.slice(ret.getStart(ast))
    }
    const output = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true } }).outputText
    const exports = {}; cache.set(file.href, exports)
    vm.runInContext(`(function(require,exports,module){${output}\n})`, context)(load, exports, { exports })
    return exports
  }
  const component = load(`@/${path}`)[exportName]
  function render(props = {}) {
    cursor = 0; rerender = false
    const internal = React.__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE
    const previous = internal.H; internal.H = dispatcher
    try { tree = component(props) } finally { internal.H = previous }
    const run = pending; pending = []; run.forEach(fn => fn())
    return tree
  }
  return { render, get handlers() { return captured }, document,
    async settle() { for (let i = 0; i < 12; i++) { await Promise.resolve(); if (rerender) render() } },
    flushTimers() { timers.splice(0).forEach(fn => fn()) },
    dispose() { effects.forEach(e => e?.off?.()) },
  }
}
const all = { preferences: true, recovery: true, realtime: true }

test('actual P8 handlers keep server save and forbid queued local writes after withdrawal, including 409', async () => {
  const h = harness(); let saves = 0, rejectSave, conflict = false
  const confirmation = { characterization_id: 7, p6_topic_ids: [], confirmed_topic_ids: [], confirmation: { revision: 1, change_reasons: {}, guided_answers: {} }, adm: {}, is_stale: false }
  const c = consumer('components/wizard/final-topics-selection.tsx', 'FinalTopicsSelection', ['handleSave', 'chooseMode', 'setChangeNotes'], h, {
    getLaravelSession: async () => ({ data: { csrf_token: 'csrf' } }), getLaravelMaterialityConfirmation: async () => ({ data: confirmation }), getLaravelEsrsTopics: async () => ({ data: [] }),
    updateLaravelMaterialityConfirmation: async () => { saves++; if (conflict) return new Promise((_, reject) => { rejectSave = reject }); return { data: confirmation } },
    previewLaravelMaterialityConfirmation: async () => ({ data: {} }),
  })
  c.render(); await c.settle(); c.handlers.chooseMode('direct'); await c.settle(); await c.handlers.handleSave()
  assert.equal(saves, 1); assert.equal(h.log.some(([op, k]) => op === 'get' && k.startsWith('p8_')), false)
  assert.equal(h.data.has('p8_guided_draft_7'), false)
  h.api.setChoice(all, 'accept'); await c.settle(); c.handlers.chooseMode('direct'); c.handlers.setChangeNotes({ 1: 'in memory' }); await c.settle()
  assert.equal(h.data.has('p8_guided_draft_7'), true)
  const delayed = c.handlers.handleSave; h.api.reset(); await delayed(); await c.settle()
  assert.equal(saves, 2); assert.equal(h.data.has('p8_guided_draft_7'), false)
  h.api.setChoice(all, 'accept'); await c.settle(); conflict = true
  const request = c.handlers.handleSave(); h.api.reset(); rejectSave(new ApiError(409)); await request; await c.settle()
  assert.equal(saves, 3); assert.equal(h.data.has('p8_guided_draft_7_conflict'), false); c.dispose()
})

test('actual P9 edits and server save work with recovery OFF and queued updater cannot recreate draft', async () => {
  const h = harness(); let saves = 0
  const c = consumer('components/wizard/esrs-datapoints-form.tsx', 'EsrsDatapointsForm', ['updateDraft', 'handleSave', 'dismissIntro', 'checkRecovery'], h, {
    getLaravelSession: async () => ({ data: { csrf_token: 'csrf' } }), getLaravelEsrsDatapoints: async () => ({ data: { characterization_id: 9, blocks: {}, readiness: {}, summary: { total_datapoint_count: 0 }, activated_esrs_standards: [] } }),
    getLaravelEsrsDatapointResponses: async () => ({ data: { characterization_id: 9, responses: {}, revision: 1 } }),
    updateLaravelEsrsDatapointResponses: async () => { saves++; return { data: { revision: saves + 1, responses: {} } } },
  })
  c.render(); await c.settle(); c.handlers.updateDraft('E1-1', { note: 'memory' }); await c.settle()
  c.flushTimers(); await c.settle(); assert.equal(saves, 1, 'server autosave runs while local recovery is OFF')
  await c.handlers.handleSave()
  assert.equal(saves, 2); assert.equal(h.data.has('p9_drafts_9'), false)
  c.flushTimers(); await c.settle()
  assert.equal(h.log.some(([op, k]) => op === 'get' && k.startsWith('p9_')), false)
  c.handlers.dismissIntro(); assert.equal(h.data.has('p9_intro_dismissed'), false)
  h.api.setChoice(all, 'accept'); await c.settle(); c.handlers.updateDraft('E1-1', { note: 'local' }); await c.settle()
  assert.equal(h.data.has('p9_drafts_9'), true)
  const queued = c.handlers.updateDraft; h.api.reset(); h.api.setChoice(all, 'accept'); queued('E1-1', { note: 'late' })
  assert.equal(h.data.has('p9_drafts_9'), false)
  c.dispose()
})

test('actual expectations and sidebar preserve in-memory operation without optional storage', async () => {
  const h = harness()
  const c = consumer('components/wizard/wizard-expectations.tsx', 'WizardExpectations', ['dismiss'], h)
  c.render(); c.handlers.dismiss(); await c.settle(); assert.equal(h.data.has('wizard_expectations_dismissed'), false)
  c.dispose()
  const sidebar = consumer('components/ui/sidebar.tsx', 'SidebarProvider', ['setOpen'], h)
  sidebar.render(); sidebar.handlers.setOpen(false); assert.equal(sidebar.document.cookie, '')
  h.api.setChoice(all, 'accept'); sidebar.handlers.setOpen(true); assert.match(sidebar.document.cookie, /sidebar_state=true/)
  sidebar.dispose()
})


test('R1: actual realtime lifecycle reconnects after an external consent change without losing current authority', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  let created = 0, stopped = 0
  const off = connectRealtime({ consent: h.api, available: () => true,
    create: () => { created++; return { disconnect() { stopped++ } } }, changed() {} })
  const incoming = JSON.stringify(h.record({ purposes: { ...all, preferences: false }, action: 'save' }))
  h.data.set(core.CONSENT_KEY, incoming); h.event('storage', { newValue: incoming })
  assert.equal(created, 2); assert.equal(stopped, 1)
  assert.equal(h.api.hasConsent('realtime'), true)
  const withdrawal = JSON.stringify(h.record({ purposes: core.NONE, action: 'withdraw' }))
  h.data.set(core.CONSENT_KEY, withdrawal); h.event('storage', { newValue: withdrawal })
  assert.equal(stopped, 2); assert.equal(h.api.hasConsent('realtime'), false); off()
})

test('R2: actual P9 write failure reaches shared UI while memory and server save remain usable', async () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  const doc = fakeDocument(), host = doc.createElement('div'), off = mountConsent(host, h.api)
  const saved = []; let persisted = {}
  const c = consumer('components/wizard/esrs-datapoints-form.tsx', 'EsrsDatapointsForm', ['updateDraft', 'handleSave', 'drafts'], h, {
    getLaravelSession: async () => ({ data: { csrf_token: 'csrf' } }),
    getLaravelEsrsDatapoints: async () => ({ data: { characterization_id: 9, blocks: {}, readiness: {}, summary: { total_datapoint_count: 0 }, activated_esrs_standards: [] } }),
    getLaravelEsrsDatapointResponses: async () => ({ data: { characterization_id: 9, responses: persisted, revision: 1 } }),
    updateLaravelEsrsDatapointResponses: async (...args) => { saved.push(args); persisted = Object.fromEntries(args[0].responses.map(row => [row.datapoint_id, row])); return { data: { revision: 2, responses: persisted } } },
  })
  c.render(); await c.settle()
  h.env.storage().setItem = () => { throw Error('quota') }
  c.handlers.updateDraft('E1-1', { note: 'synthetic in-memory edit' }); await c.settle()
  assert.equal(c.handlers.drafts['E1-1'].note, 'synthetic in-memory edit')
  const notice = host.children[0].children.find(x => x.className === 'airis-consent-notice')
  const alerts = node => node.children.flatMap(x => [x, ...alerts(x)]).filter(x => x.getAttribute('role') === 'alert')
  assert.equal(notice.hidden, false); assert.ok(alerts(notice).some(x => !x.hidden))
  await c.handlers.handleSave(); await c.settle()
  assert.equal(saved.length, 1); assert.match(JSON.stringify(saved), /synthetic in-memory edit/)
  assert.equal(c.handlers.drafts['E1-1'].note, 'synthetic in-memory edit')
  assert.equal(h.data.has('p9_drafts_9'), false)
  c.dispose(); off()
})

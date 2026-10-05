import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, existsSync } from 'node:fs'
import { createRequire } from 'node:module'
import vm from 'node:vm'
import ts from 'typescript'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { harness } from './consent-core.test.mjs'
import * as core from '../public/consent/core.mjs'
import { mountConsent } from '../public/consent/ui.mjs'
import { fakeDocument } from './consent-ui.test.mjs'
import { connectRealtime } from '../public/consent/realtime.mjs'

const require = createRequire(import.meta.url)
const pure = {}
for (const name of ['esrs-datapoints-state', 'materiality-confirmation-state', 'materiality-confirmation-draft', 'materiality-guided-state', 'double-materiality-guide-state', 'materiality-proposal-review', 'p6-document-evidence']) pure[`@/lib/${name}.mjs`] = await import(`../lib/${name}.mjs`)
const frontend = new URL('../', import.meta.url)
class ApiError extends Error { constructor(status) { super('synthetic transport failure'); this.status = status } }

// Execute actual TSX, hooks and pure business helpers. Only network/navigation are replaced.
// The dispatcher supplies deterministic render/effect scheduling without a browser dependency.
function consumer(path, exportName, handlers, h, transport = {}) {
  let cursor = 0, pending = [], captured = {}, tree, rerender = false
  const slots = [], effects = [], timers = new Map(); let timerSequence = 0
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
  const document = { cookie: '', documentElement: { lang: 'es' } }
  const obsoleteReads = []
  const cache = new Map(), router = { push() {}, refresh() {}, replace() {} }
  const context = vm.createContext({ window: win, document, console, URLSearchParams, Set, Map, Date, crypto: { randomUUID: () => 'synthetic-tab' },
    setTimeout(fn) { const id = ++timerSequence; timers.set(id, fn); return id }, clearTimeout(id) { timers.delete(id) }, __capture(value) { captured = value } })
  function load(spec) {
    if (spec === 'react') return React
    if (spec === 'next/navigation') return { useRouter: () => router }
    if (spec === '@/lib/laravel-api') return { LaravelApiError: ApiError, laravelApiUrl: p => p, ...transport,
      ...Object.fromEntries(['getLaravelEsrsDatapoints', 'getLaravelEsrsDatapointResponses'].map(name => [name, () => {
        obsoleteReads.push(name); throw Error(`Obsolete independent P9 GET: ${name}`)
      }])) }
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
    async settle() { for (let i = 0; i < 12; i++) { await Promise.resolve(); if (rerender) render() }
      assert.deepEqual(obsoleteReads, [], 'P9 must load one coherent workspace, never independent GETs') },
    flushTimers() { const queued = [...timers.values()]; timers.clear(); queued.forEach(fn => fn()) },
    dispose() { effects.forEach(e => e?.off?.()) },
  }
}
const all = { preferences: true, recovery: true, realtime: true }

// Synthetic server publication, validated by the real loader before it becomes editable.
function p9Workspace(rows = [{ id: 'E1-1', standard: 'E1', dr: 'E1-1', name: 'Plan', selection: { default_selected: true } }], responses = {}, revision = 1) {
  const authority = 'a'.repeat(64)
  return {
    snapshot_version: 'p9-workspace-v1',
    data: { characterization_id: 9, mapping_snapshot_digest: 'b'.repeat(64), learning_authority_digest: authority,
      blocks: { all: { key: 'all', datapoints: rows } }, readiness: {},
      summary: { total_datapoint_count: rows.length }, activated_esrs_standards: [...new Set(rows.map(row => row.standard))] },
    response_state: { characterization_id: 9, revision, source_digest: 'c'.repeat(64), learning_authority_digest: authority,
      responses, learning_feedback: { schema_version: 'datapoint-feedback-v1', authority_digest: authority,
        reviewed_datapoint_ids: [], decisions: [] } },
  }
}

function p9Transport(snapshot, onSave = () => {}) {
  let workspace = structuredClone(snapshot)
  return {
    getLaravelSession: async () => ({ data: { csrf_token: 'csrf' } }),
    getLaravelEsrsDatapointWorkspace: async () => structuredClone(workspace),
    updateLaravelEsrsDatapointResponses: async payload => {
      assert.equal(payload.expected_revision, workspace.response_state.revision)
      assert.equal(payload.learning_feedback.authority_digest, workspace.data.learning_authority_digest)
      const known = new Set(Object.values(workspace.data.blocks).flatMap(block => block.datapoints.map(row => row.id)))
      assert.ok(payload.responses.every(row => known.has(row.datapoint_id)))
      onSave(payload)
      workspace.response_state = { ...workspace.response_state, revision: payload.expected_revision + 1,
        responses: Object.fromEntries(payload.responses.map(row => [row.datapoint_id, row])),
        learning_feedback: structuredClone(payload.learning_feedback) }
      return { data: structuredClone(workspace.response_state) }
    },
  }
}

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
  const c = consumer('components/wizard/esrs-datapoints-form.tsx', 'EsrsDatapointsForm', ['updateDraft', 'handleSave', 'dismissIntro', 'checkRecovery'], h,
    p9Transport(p9Workspace(), () => { saves++ }))
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
  const saved = []
  const c = consumer('components/wizard/esrs-datapoints-form.tsx', 'EsrsDatapointsForm', ['updateDraft', 'handleSave', 'drafts'], h,
    p9Transport(p9Workspace(), payload => saved.push(payload)))
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


function elements(tree, predicate) {
  return React.Children.toArray(tree).flatMap(node => {
    if (!React.isValidElement(node)) return []
    return [...(predicate(node) ? [node] : []), ...elements(node.props.children, predicate)]
  })
}

const proposalTopic = { id: 1, esrs_code: 'E1', theme: { es: 'Cambio climático' }, subtheme: { es: 'Adaptación al cambio climático' } }
const completedProposal = {
  proposal_topics: [proposalTopic], proposal_topic_ids: [1], source: 'ai_prediction', status: 'completed',
  ai: { summary: 'Resumen técnico no visible en la interfaz.', review_required_prediction_keys: [] },
  review: { status: 'draft', topic_actions: {}, action_reasons: {}, action_notes: {} },
}

test('material topic UI starts collapsed, translates summary and keeps three independent row actions', async () => {
  const c = consumer('components/wizard/material-topics-form.tsx', 'MaterialTopicsForm', ['expandedTopics', 'TopicReviewCard'], harness(), {
    getLaravelSession: async () => ({ data: { csrf_token: 'csrf' } }),
    getLaravelCharacterization: async () => ({ data: { status: 'completed', form_data: {} } }),
    getLaravelMaterialityProposal: async () => ({ data: completedProposal }),
  })
  c.render(); await c.settle()
  let tree = c.render(), html = renderToStaticMarkup(tree)
  assert.match(html, /La IA ha propuesto 1 temas ESRS candidatos/)
  assert.doesNotMatch(html, /Resumen técnico no visible|Nota de revisión opcional|Sin revisar/)
  assert.equal(c.handlers.expandedTopics.length, 0)
  const button = label => elements(tree, el => el.props.children === label && typeof el.props.onClick === 'function')[0]
  button('Expandir todo').props.onClick(); await c.settle()
  tree = c.render(); html = renderToStaticMarkup(tree)
  assert.equal(c.handlers.expandedTopics.length, 1)
  assert.match(html, /Nota de revisión opcional/)
  button('Colapsar todo').props.onClick(); await c.settle()
  tree = c.render()
  const cardElement = elements(tree, el => el.props.topic?.id === 1)[0]
  cardElement.props.onExpandedChange(true); await c.settle()
  assert.equal(c.handlers.expandedTopics.length, 1)
  let selected
  const card = c.handlers.TopicReviewCard({ ...cardElement.props, expanded: false, onActionChange: value => { selected = value } })
  const group = elements(card, el => el.props.role === 'group')[0]
  assert.match(group.props.className, /flex-nowrap/)
  const actions = elements(group, el => typeof el.props.onClick === 'function')
  assert.equal(actions.length, 3)
  for (const [index, value] of ['accepted', 'unsure', 'rejected'].entries()) {
    actions[index].props.onClick(); assert.equal(selected, value)
  }
  c.dispose()
})

test('pending prediction shows honest wait feedback and polling is cancelled on unmount', async () => {
  let reads = 0
  const c = consumer('components/wizard/material-topics-form.tsx', 'MaterialTopicsForm', [], harness(), {
    getLaravelSession: async () => ({ data: { csrf_token: 'csrf' } }),
    getLaravelCharacterization: async () => { reads++; return { data: { status: 'processing', form_data: {} } } },
    getLaravelMaterialityProposal: async () => ({ data: null }),
  })
  c.render(); await c.settle()
  const html = renderToStaticMarkup(c.render())
  assert.match(html, /animate-spin/); assert.match(html, /role="progressbar"/); assert.match(html, /cada 10 segundos/); assert.match(html, /barra siga activa/); assert.match(html, /Actualizar/)
  assert.doesNotMatch(html, /aria-valuenow|[0-9]+%/)
  c.flushTimers(); await c.settle(); assert.equal(reads, 2)
  c.dispose(); c.flushTimers(); await c.settle(); assert.equal(reads, 2)
})

test('guide renders collapsed numbered sections and persists only user checklist changes', async () => {
  const checks = { identified_stakeholders: false, assessed_impacts: false, assessed_financial_effects: false, reached_conclusions: false }
  let saved
  const c = consumer('components/wizard/double-materiality-guide.tsx', 'DoubleMaterialityGuide', ['openSteps', 'toggleStep'], harness(), {
    getLaravelSession: async () => ({ data: { csrf_token: 'csrf' } }),
    getLaravelDoubleMaterialityGuide: async () => ({ data: { warning: { es: 'Guía' }, handoff: {}, templates: [], sections: [
      { key: 'prepare_scope', title: { es: 'Preparar alcance' }, steps: [{ key: 'review_p5_p6', title: { es: 'Título remoto no usado' }, checks: ['Texto remoto no usado.'] }] },
    ] } }),
    getLaravelDoubleMaterialityGuideState: async () => ({ data: { checklist: checks } }),
    updateLaravelDoubleMaterialityGuideState: async payload => { saved = payload; return { data: payload } },
  })
  c.render(); await c.settle()
  let tree = c.render(), html = renderToStaticMarkup(tree)
  assert.equal(c.handlers.openSteps.length, 0)
  assert.doesNotMatch(html, /checked=""|Título remoto no usado|Texto remoto no usado/)
  c.handlers.toggleStep('prepare_scope'); await c.settle()
  html = renderToStaticMarkup(c.render())
  assert.match(html, /Revisar la caracterización inicial y la propuesta de temas materiales/)
  assert.match(html, /Confirma el perímetro/)
  assert.doesNotMatch(html, /\bP(?:5|6|8|9|10)\b|Título remoto no usado|Texto remoto no usado/)
  tree = c.render()
  const inputs = elements(tree, el => el.type === 'input' && el.props.type === 'checkbox')
  assert.equal(inputs.length, 4); assert.ok(inputs.every(el => el.props.checked === false))
  await inputs[0].props.onChange(); await c.settle()
  assert.equal(saved.checklist.identified_stakeholders, true)
  assert.equal(Object.values(saved.checklist).filter(Boolean).length, 1)
  c.dispose()
})

test('datapoint matrix bulk scope includes filtered rows, preserves answers and shares one debounce with individual edits', async () => {
  const rows = [
    { id: 'A', standard: 'ESRS 2', dr: 'BP-1', name: 'Alcance', selection: { default_selected: true } },
    { id: 'B', standard: 'ESRS 2', dr: 'BP-1', name: 'Detalle', selection: { default_selected: false } },
    { id: 'C', standard: 'ESRS 2', dr: 'BP-2', name: 'Horizonte', selection: { default_selected: true } },
    { id: 'D', standard: 'E1', dr: 'E1-1', name: 'Plan', selection: { default_selected: true } },
  ]
  const existing = { status: 'completed', value: '10', note: 'Mantener', evidence_reference: 'registro' }
  const saves = []
  const h = harness(); h.api.setChoice(all, 'accept')
  const c = consumer('components/wizard/esrs-datapoints-form.tsx', 'EsrsDatapointsForm', ['drafts', 'updateDraft', 'expandedDatapoints', 'TriageResponseControl'], h,
    p9Transport(p9Workspace(rows, { A: existing }, 7), payload => saves.push(payload)))
  c.render(); await c.settle()
  let tree = c.render()
  const groups = elements(tree, el => el.props.label && typeof el.props.onChange === 'function' && typeof el.props.count === 'number')
  assert.equal(groups.find(el => el.props.label === 'ESRS 2').props.count, 3)
  const requirement = groups.find(el => el.props.label === 'ESRS 2 · BP-1')
  assert.equal(requirement.props.count, 2)
  requirement.props.onChange('have_it'); await c.settle()
  assert.equal(c.handlers.drafts.A.triage, 'have_it'); assert.equal(c.handlers.drafts.B.triage, 'have_it')
  assert.equal(c.handlers.drafts.C, undefined); assert.equal(c.handlers.drafts.D, undefined)
  assert.equal(c.handlers.drafts.A.value, existing.value); assert.equal(c.handlers.drafts.A.status, existing.status)
  assert.equal(c.handlers.drafts.A.note, existing.note); assert.equal(c.handlers.drafts.A.evidence_reference, existing.evidence_reference)
  groups.find(el => el.props.label === 'ESRS 2').props.onChange('need_to_find'); await c.settle()
  c.handlers.updateDraft('A', { triage: 'not_applicable_candidate', note: 'Nota editada' }); await c.settle()
  assert.equal(c.handlers.drafts.C.triage, 'need_to_find'); assert.equal(c.handlers.drafts.D, undefined)
  c.flushTimers(); await c.settle()
  assert.equal(saves.length, 1); assert.equal(saves[0].expected_revision, 7)
  assert.equal(saves[0].responses.find(row => row.datapoint_id === 'A').note, 'Nota editada')
  assert.equal(saves[0].responses.find(row => row.datapoint_id === 'B').triage, 'need_to_find')
  assert.deepEqual(saves[0].learning_feedback.reviewed_datapoint_ids, [])
  assert.deepEqual(saves[0].learning_feedback.decisions, [], 'triage does not invent human learning decisions')
  const html = renderToStaticMarkup(c.render())
  assert.match(html, /role="radiogroup"/); assert.match(html, /Creo que no aplica/)
  assert.match(html, /Guardado automáticamente/); assert.doesNotMatch(html, /Guardado automático pendiente/)
  const radio = elements(c.handlers.TriageResponseControl({ datapointId: 'A', value: 'have_it', onChange() {} }), el => el.type === 'input')
  assert.equal(radio.length, 3); assert.equal(radio.filter(el => el.props.checked).length, 1)
  c.dispose()
})

test('full P9 consumer refuses edits and autosave for invalid or mismatched workspace', async () => {
  for (const mode of ['version', 'characterization', 'authority']) {
    const snapshot = p9Workspace()
    if (mode === 'version') snapshot.snapshot_version = 'unsupported'
    if (mode === 'characterization') snapshot.response_state.characterization_id = 10
    if (mode === 'authority') snapshot.response_state.learning_authority_digest = 'd'.repeat(64)
    const h = harness(); h.api.setChoice(all, 'accept')
    let saves = 0
    const transport = p9Transport(snapshot)
    // Count every request at the boundary, even if an incoherent payload would be rejected.
    transport.updateLaravelEsrsDatapointResponses = async () => { saves++; return { data: snapshot.response_state } }
    const c = consumer('components/wizard/esrs-datapoints-form.tsx', 'EsrsDatapointsForm', ['drafts', 'updateDraft', 'handleSave', 'corpus'], h,
      transport)
    c.render(); await c.settle()
    assert.match(renderToStaticMarkup(c.render()), /No se han podido cargar/)
    assert.equal(c.handlers.corpus, null)
    c.handlers.updateDraft('E1-1', { note: 'must remain blocked' }); await c.settle()
    c.flushTimers(); await c.settle()
    await c.handlers.handleSave(); await c.settle()
    assert.equal(Object.keys(c.handlers.drafts).length, 0)
    assert.equal(saves, 0, `${mode}: no transport save without coherent authority`)
    assert.equal(h.data.has('p9_drafts_9'), false)
    c.dispose()
  }
})

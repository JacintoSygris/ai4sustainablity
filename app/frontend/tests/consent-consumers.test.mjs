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
import { wizardStepsForView } from '../lib/wizard-steps-data.mjs'

const pure = {}
for (const name of ['esrs-datapoints-state', 'materiality-confirmation-state', 'materiality-confirmation-draft', 'materiality-guided-state', 'double-materiality-guide-state', 'materiality-proposal-review', 'p6-document-evidence', 'learning-case-state']) pure[`@/lib/${name}.mjs`] = await import(`../lib/${name}.mjs`)
const frontend = new URL('../', import.meta.url)
class ApiError extends Error { constructor(status) { super('synthetic transport failure'); this.status = status } }

// Execute actual TSX, hooks and pure business helpers. Only network/navigation are replaced.
// The dispatcher supplies deterministic render/effect scheduling without a browser dependency.
function consumer(path, exportName, handlers, h, transport = {}) {
  let cursor = 0, pending = [], captured = {}, tree, rerender = false, selectedLocale = "es"
  const slots = [], effects = [], timers = new Map(); let timerSequence = 0, refreshes = 0
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
  const document = { cookie: '', documentElement: { lang: 'es' }, title: '', querySelector: () => ({ setAttribute() {} }) }
  const obsoleteReads = []
  const pushes = []
  const cache = new Map(), router = { push(path) { pushes.push(path) }, refresh() { refreshes++ }, replace() {} }
  const context = vm.createContext({ window: win, document, console, URLSearchParams, Headers, AbortController, process: { env: {} }, fetch: transport.fetch, Set, Map, Date, crypto: { randomUUID: () => 'synthetic-tab' },
    setTimeout(fn) { const id = ++timerSequence; timers.set(id, fn); return id }, clearTimeout(id) { timers.delete(id) }, __capture(value) { captured = value } })
  function load(spec, importer = import.meta.url) {
    if (spec === '@/components/locale-provider' && path !== 'components/locale-provider') return { useLocale: () => ({ locale: selectedLocale, changing: false, ...transport.localeContext }) }
    if (spec === 'react') return React
    if (spec === '@/lib/laravel-server') return { getLaravelServerLocale: async () => selectedLocale, getLaravelServerSession: async () => null, getLaravelServerCharacterization: async () => null, getLaravelServerReportReadiness: async () => null, ...transport }
    if (spec === 'next/navigation') return { useRouter: () => router }
    if (spec === '@/lib/laravel-api' && !transport.fetch) return { LaravelApiError: ApiError, laravelApiUrl: p => p, ...transport,
      ...Object.fromEntries(['getLaravelEsrsDatapoints', 'getLaravelEsrsDatapointResponses'].map(name => [name, () => {
        obsoleteReads.push(name); throw Error(`Obsolete independent P9 GET: ${name}`)
      }])) }
    if (pure[spec]) return pure[spec]
    if (spec.includes('public/consent/core.mjs')) return { ...core, getBrowserConsent: () => h.api }
    if (!spec.startsWith('@/') && !spec.startsWith('../public/')) return createRequire(importer)(spec)
    const relative = spec.replace(/^@\//, '')
    const file = ['','.ts','.tsx'].map(ext => new URL(relative + ext, frontend)).find(url => existsSync(url))
    if (!file) throw Error(`Missing actual module ${spec}`)
    if (cache.has(file.href)) return cache.get(file.href)
    let source = readFileSync(file, 'utf8')
    if (relative === path) {
      const ast = ts.createSourceFile('component.tsx', source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX)
      const component = ast.statements.find(n => ts.isFunctionDeclaration(n) && (n.name?.text === exportName || exportName === 'default' && n.modifiers?.some(m => m.kind === ts.SyntaxKind.DefaultKeyword)))
      const ret = component.body.statements.filter(ts.isReturnStatement).at(-1)
      source = source.slice(0, ret.getStart(ast)) + `globalThis.__capture({${handlers.join(',')}});\n` + source.slice(ret.getStart(ast))
    }
    const output = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true } }).outputText
    const exports = {}; cache.set(file.href, exports)
    vm.runInContext(`(function(require,exports,module){${output}\n})`, context)(dependency => load(dependency, file), exports, { exports })
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
  return { render, setLocale(value, props = {}) { selectedLocale = value; render(props) }, get locale() { return selectedLocale }, get refreshes() { return refreshes }, get pushes() { return pushes }, get handlers() { return captured }, document,
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
    data: { locale: "es", characterization_id: 9, mapping_snapshot_digest: 'b'.repeat(64), learning_authority_digest: authority,
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
  assert.match(html, /La IA ha propuesto 1 temas NEIS candidatos/)
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
    getLaravelDoubleMaterialityGuide: async () => ({ data: { warning: { es: 'Guía' }, next_step: {}, templates: [], sections: [
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
  for (const row of rows) row.display = { locale: 'es', name: row.name }
  const existing = { status: 'completed', value: '10', note: 'Mantener', evidence_reference: 'registro' }
  const saves = []
  const h = harness(); h.api.setChoice(all, 'accept')
  const c = consumer('components/wizard/esrs-datapoints-form.tsx', 'EsrsDatapointsForm', ['drafts', 'updateDraft', 'expandedDatapoints', 'TriageResponseControl'], h,
    p9Transport(p9Workspace(rows, { A: existing }, 7), payload => saves.push(payload)))
  c.render(); await c.settle()
  let tree = c.render()
  const groups = elements(tree, el => el.props.label && typeof el.props.onChange === 'function' && typeof el.props.count === 'number')
  assert.equal(groups.find(el => el.props.label === 'NEIS 2').props.count, 3)
  const requirement = groups.find(el => el.props.label === 'NEIS 2 · BP-1')
  assert.equal(requirement.props.count, 2)
  requirement.props.onChange('have_it'); await c.settle()
  assert.equal(c.handlers.drafts.A.triage, 'have_it'); assert.equal(c.handlers.drafts.B.triage, 'have_it')
  assert.equal(c.handlers.drafts.C, undefined); assert.equal(c.handlers.drafts.D, undefined)
  assert.equal(c.handlers.drafts.A.value, existing.value); assert.equal(c.handlers.drafts.A.status, existing.status)
  assert.equal(c.handlers.drafts.A.note, existing.note); assert.equal(c.handlers.drafts.A.evidence_reference, existing.evidence_reference)
  groups.find(el => el.props.label === 'NEIS 2').props.onChange('need_to_find'); await c.settle()
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

test('locale provider persists through the server and switches HTML language and metadata in both directions', async () => {
  const changes = []
  const c = consumer('components/locale-provider', 'LocaleProvider', ['locale', 'changeLocale'], harness(), {
    getLaravelLocale: async () => ({ data: { locale: 'es', csrf_token: 'locale-csrf' } }),
    updateLaravelLocale: async (locale, csrf) => { changes.push({ locale, csrf }); return { data: { locale } } },
  })
  c.render(); assert.equal(c.handlers.locale, 'es'); assert.equal(c.document.documentElement.lang, 'es')
  await c.handlers.changeLocale('en'); await c.settle()
  assert.equal(c.handlers.locale, 'en'); assert.equal(c.document.documentElement.lang, 'en')
  assert.match(c.document.title, /Assisted ESRS/)
  await c.handlers.changeLocale('es'); await c.settle()
  assert.equal(c.handlers.locale, 'es'); assert.equal(c.document.documentElement.lang, 'es')
  assert.match(c.document.title, /Preparación NEIS/)
  assert.deepEqual(changes.map(x => x.locale), ['en', 'es'])
  assert.ok(changes.every(x => x.csrf === 'locale-csrf')); c.dispose()
})

test('changing disclosure locale refreshes only presentation and retains unsaved text and response revisions', async () => {
  let reads = 0, failLocaleRead = false
  const saves = []
  const c = consumer('components/wizard/esrs-datapoints-form.tsx', 'EsrsDatapointsForm', ['drafts', 'updateDraft', 'retryLocaleLoad'], harness(), {
    getLaravelSession: async () => ({ data: { csrf_token: 'csrf' } }),
    getLaravelEsrsDatapointWorkspace: async () => {
      reads++
      if (failLocaleRead) throw new ApiError(503)
      const snapshot = p9Workspace([{ id: 'BP-1_01', standard: 'ESRS 2', dr: 'BP-1', name: 'Basis for preparation', display: { locale: c.locale, name: c.locale === 'es' ? 'Bases de elaboración' : 'Basis for preparation' } }], {}, 7)
      snapshot.data.locale = c.locale
      return snapshot
    },
    updateLaravelEsrsDatapointResponses: async payload => { saves.push(payload); return { data: { revision: 8 } } },
  })
  c.render(); await c.settle()
  c.handlers.updateDraft('BP-1_01', { value: 'Texto libre unchanged', note: '=1+1' }); await c.settle()
  failLocaleRead = true; c.setLocale('en'); await c.settle()
  assert.match(renderToStaticMarkup(c.render()), /Retry/)
  assert.equal(c.handlers.drafts['BP-1_01'].value, 'Texto libre unchanged')
  failLocaleRead = false; c.handlers.retryLocaleLoad(); await c.settle()
  const html = renderToStaticMarkup(c.render())
  assert.match(html, /ESRS disclosure data/); assert.match(html, /Basis for preparation/)
  assert.doesNotMatch(html, /Bases de elaboración|fases sugeridas/)
  assert.equal(c.handlers.drafts['BP-1_01'].value, 'Texto libre unchanged')
  assert.equal(c.handlers.drafts['BP-1_01'].note, '=1+1')
  assert.equal(reads, 3)
  c.flushTimers(); await c.settle()
  assert.equal(saves.length, 1); assert.equal(saves[0].expected_revision, 7)
  assert.equal(saves[0].responses[0].note, '=1+1')
  c.setLocale('es'); await c.settle()
  assert.match(renderToStaticMarkup(c.render()), /Bases de elaboración/)
  c.dispose()
})

test('locale provider reconciles an SSR fallback with the existing browser preference', async () => {
  const c = consumer('components/locale-provider', 'LocaleProvider', ['locale', 'changeLocale'], harness(), {
    getLaravelLocale: async () => ({ data: { locale: 'en', csrf_token: 'csrf' } }),
  })
  c.render(); assert.equal(c.handlers.locale, 'es'); await c.settle()
  assert.equal(c.handlers.locale, 'en'); assert.equal(c.document.documentElement.lang, 'en')
  c.dispose()
})

test('a late initial locale lookup cannot overwrite an explicit language change', async () => {
  let releaseInitial, reads = 0
  const c = consumer('components/locale-provider', 'LocaleProvider', ['locale', 'changeLocale'], harness(), {
    getLaravelLocale: () => ++reads === 1 ? new Promise(resolve => { releaseInitial = resolve }) : Promise.resolve({ data: { locale: 'es', csrf_token: 'csrf' } }),
    updateLaravelLocale: async locale => ({ data: { locale } }),
  })
  c.render(); await c.handlers.changeLocale('en'); await c.settle()
  releaseInitial({ data: { locale: 'es' } }); await c.settle()
  assert.equal(c.handlers.locale, 'en'); c.dispose()
})

test('the guide sidebar and academy advice switch without changing navigation targets', () => {
  const sidebarProps = { steps: wizardStepsForView(3), currentStep: 3, viewingStep: 3 }
  const sidebar = consumer('components/wizard/wizard-sidebar', 'WizardSidebar', [], harness())
  assert.match(renderToStaticMarkup(sidebar.render(sidebarProps)), /Doble importancia relativa/)
  sidebar.setLocale('en', sidebarProps)
  const html = renderToStaticMarkup(sidebar.render(sidebarProps))
  assert.match(html, /Double materiality/); assert.match(html, /Back to dashboard/)
  assert.match(html, /href="\/wizard\/step-3"/); assert.doesNotMatch(html, /Doble importancia relativa/)
  sidebar.dispose()
  const advice = 'La doble importancia relativa evalúa cada asunto desde el impacto y desde el efecto financiero; el paso 3 te guía para hacer el análisis fuera de la aplicación y el paso 4 registra tu confirmación final.'
  const academyProps = { title: 'Consejos prácticos', tips: [advice] }
  const academy = consumer('components/wizard/academy-tips', 'AcademyTips', [], harness())
  academy.render(academyProps); academy.setLocale('en', academyProps)
  const tips = renderToStaticMarkup(academy.render(academyProps))
  assert.match(tips, /Practical tips/); assert.match(tips, /Double materiality assesses/)
  assert.doesNotMatch(tips, /Consejos prácticos/); academy.dispose()
})

test('a successful preference write refreshes server components only after persistence; failure rolls back', async () => {
  let resolveWrite, rejectWrite
  const c = consumer('components/locale-provider', 'LocaleProvider', ['locale', 'changing', 'changeLocale'], harness(), {
    getLaravelLocale: async () => ({ data: { locale: 'es', csrf_token: 'csrf' } }),
    updateLaravelLocale: () => new Promise((resolve, reject) => { resolveWrite = resolve; rejectWrite = reject }),
  })
  c.render(); await c.settle()
  const first = c.handlers.changeLocale('en'); await c.settle()
  assert.equal(c.handlers.locale, 'es'); assert.equal(c.document.documentElement.lang, 'es'); assert.equal(c.handlers.changing, true); assert.equal(c.refreshes, 0)
  resolveWrite({ data: { locale: 'en' } }); await first; await c.settle()
  assert.equal(c.refreshes, 1); assert.equal(c.handlers.changing, false)
  const failed = c.handlers.changeLocale('es'); const rejection = assert.rejects(failed, /unavailable/)
  await c.settle(); assert.equal(c.handlers.locale, 'en')
  rejectWrite(Error('unavailable')); await rejection; await c.settle()
  assert.equal(c.handlers.locale, 'en'); assert.equal(c.document.documentElement.lang, 'en')
  assert.equal(c.refreshes, 1)
  await assert.rejects(c.handlers.changeLocale('fr'), /Unsupported locale/)
  c.dispose()
})

test('language selection exposes an accessible failure without navigating', async () => {
  const c = consumer('components/language-selector', 'LanguageSelector', [], harness(), {
    localeContext: { changeLocale: async () => { throw Error('unavailable') } },
  })
  const selector = elements(c.render(), e => e.type === 'select')[0]
  await selector.props.onChange({ target: { value: 'en' } }); await c.settle()
  assert.match(renderToStaticMarkup(c.render()), /role="alert"/)
  assert.equal(c.refreshes, 0); c.dispose()
})

test('held locale persistence keeps Spanish and disables every server-localized download until success', async () => {
  let resolveWrite, rejectWrite
  const provider = consumer('components/locale-provider', 'LocaleProvider', ['locale', 'changing', 'changeLocale'], harness(), {
    fetch: (url, request) => {
      assert.equal(url, '/api/locale')
      if (request.method === 'GET') return Promise.resolve(Response.json({ data: { locale: 'es', csrf_token: 'csrf' } }))
      assert.equal(request.method, 'PUT'); assert.deepEqual(JSON.parse(request.body), { locale: 'en' })
      assert.equal(request.headers.get('X-CSRF-TOKEN'), 'csrf'); assert.equal(request.credentials, 'include')
      return new Promise((resolve, reject) => { resolveWrite = data => resolve(Response.json(data)); rejectWrite = reject })
    },
  })
  provider.render(); await provider.settle()
  const localeContext = { get locale() { return provider.handlers.locale }, get changing() { return provider.handlers.changing } }
  const shared = { localeContext, getLaravelSession: async () => ({ data: { csrf_token: 'csrf' } }) }
  const p7 = consumer('components/wizard/double-materiality-guide', 'DoubleMaterialityGuide', ['updateActaField', 'actaDraft'], harness(), {
    ...shared,
    getLaravelDoubleMaterialityGuide: async () => ({ data: { warning: { es: 'Aviso', en: 'Notice' }, next_step: { note: { es: 'Nota', en: 'Note' } }, sections: [], templates: [{ key: 'iro_register', title: { es: 'Registro', en: 'Register' } }] } }),
    getLaravelDoubleMaterialityGuideState: async () => ({ data: { checklist: {}, acta: null } }),
  })
  const confirmation = { characterization_id: 7, is_confirmed: true, p6_topic_ids: [1], confirmed_topic_ids: [1], confirmation: { revision: 4, change_reasons: {}, guided_answers: {} }, adm: {}, is_stale: false }
  const p8 = consumer('components/wizard/final-topics-selection', 'FinalTopicsSelection', ['chooseMode', 'setChangeNotes', 'changeNotes', 'confirmation'], harness(), {
    ...shared,
    getLaravelMaterialityConfirmation: async () => ({ data: confirmation }),
    getLaravelEsrsTopics: async () => ({ data: [] }),
    previewLaravelMaterialityConfirmation: async () => ({ data: {} }),
  })
  const p9 = consumer('components/wizard/esrs-datapoints-form', 'EsrsDatapointsForm', ['updateDraft', 'drafts', 'saveQueueRef'], harness(), {
    ...shared,
    getLaravelEsrsDatapointWorkspace: async () => {
      const snapshot = p9Workspace([], {}, 7)
      snapshot.data.locale = provider.handlers.locale
      return snapshot
    },
  })
  const downloads = Object.fromEntries([
    ['report_package_html', '/api/report/package'], ['report_draft_json', '/api/report/draft'],
    ['p9_datapoints_csv', '/api/esrs-datapoints/export.localized.csv'], ['p9_responses_csv', '/api/esrs-datapoints/responses/export.localized.csv'],
    ['xhtml_candidate', '/api/report/xhtml-ixbrl-candidate'],
  ].map(([key, endpoint]) => [key, { status: 'ready', endpoint, content_type: 'application/octet-stream' }]))
  const p10 = consumer('components/wizard/report-draft-panel', 'ReportDraftPanel', ['updateFactForm', 'factForm'], harness(), {
    ...shared,
    getLaravelReportingFacts: async () => ({ data: null }),
    getLaravelReportTaxonomyStatus: async () => ({ data: null }), getLaravelReportSnapshots: async () => ({ data: [{ id: 1, stale_state: 'fresh', is_approved: true }] }),
    getLaravelReportReadiness: async () => ({ data: { locale: provider.handlers.locale, status: 'ready', sections: {}, downloads } }),
    getLaravelReportDraft: async () => ({ data: { locale: provider.handlers.locale, company: {}, materiality: { confirmed_topics: [] }, datapoints: { blocks: [] }, exports: {} } }),
  })
  const consumers = [p7, p8, p9, p10]
  for (const c of consumers) { c.render(); await c.settle() }
  p8.handlers.chooseMode('direct'); p8.handlers.setChangeNotes({ 1: 'Texto libre unchanged' })
  p7.handlers.updateActaField('participants', 'Texto libre unchanged')
  p9.handlers.updateDraft('BP-1_01', { value: 'Texto libre unchanged', evidence_reference: '=1+1' })
  p10.handlers.updateFactForm('value', '9007199254740993'); p10.handlers.updateFactForm('evidenceRef', '=1+1')
  for (const c of consumers) await c.settle()
  const revisionOwner = p9.handlers.saveQueueRef.current
  const urls = [
    ['/double-materiality-guide/templates/iro_register.csv'],
    ['/materiality-confirmation/decision-sheet'],
    ['/esrs-datapoints/export.localized.csv', '/esrs-datapoints/responses/export.localized.csv'],
    ['/report/package', '/report/draft', '/report/html', '/guided-report/docx', '/guided-report/evidence-bundle', '/report/xhtml-ixbrl-candidate', '/esrs-datapoints/export.localized.csv', '/esrs-datapoints/responses/export.localized.csv'],
  ]
  function assertDownloads(enabled) {
    consumers.forEach((c, index) => {
      const tree = c.render(), html = renderToStaticMarkup(tree)
      if (c === p8) {
        const decisionControl = elements(tree, node => typeof node.props.onClick === 'function' && /hoja de decisión|decision sheet/.test(renderToStaticMarkup(node)))
        assert.equal(decisionControl.length, 0, 'P8 must not keep a direct window.open click handler')
      }
      const controls = elements(tree, node => urls[index].includes(node.props.href))
      assert.ok(controls.length >= urls[index].length, 'each real component exposes its download controls')
      for (const url of urls[index]) assert.equal(html.includes(`href="${url}"`), enabled, url)
      for (const control of controls) {
        const markup = renderToStaticMarkup(control)
        if (!enabled) { assert.match(markup, /^<button\b/); assert.match(markup, /disabled=""/); assert.doesNotMatch(markup, /href=|<a\b/) }
      }
    })
  }
  assertDownloads(true)
  // The first es -> en request fails. No optimistic English or SSR refresh occurs.
  const failed = provider.handlers.changeLocale('en'), rejection = assert.rejects(failed, /unavailable/)
  await provider.settle()
  assert.equal(provider.handlers.locale, 'es'); assert.equal(provider.document.documentElement.lang, 'es')
  assert.equal(provider.handlers.changing, true); assert.equal(provider.refreshes, 0)
  assertDownloads(false)
  rejectWrite(Error('unavailable')); await rejection; await provider.settle()
  assert.equal(provider.handlers.locale, 'es'); assert.equal(provider.refreshes, 0)
  assertDownloads(true)
  assert.match(renderToStaticMarkup(p8.render()), /Descargar hoja de decisión/)
  const success = provider.handlers.changeLocale('en'); await provider.settle()
  assertDownloads(false); assert.equal(provider.handlers.locale, 'es'); assert.equal(provider.refreshes, 0)
  resolveWrite({ data: { locale: 'en' } }); await success; await provider.settle()
  for (const c of consumers) { c.render(); await c.settle() }
  assert.equal(provider.handlers.locale, 'en'); assert.equal(provider.document.documentElement.lang, 'en')
  assert.equal(provider.handlers.changing, false); assert.equal(provider.refreshes, 1)
  assertDownloads(true)
  assert.match(renderToStaticMarkup(p7.render()), /Download CSV/)
  assert.match(renderToStaticMarkup(p8.render()), /Download decision sheet/)
  assert.equal(p8.handlers.changeNotes[1], 'Texto libre unchanged')
  assert.equal(p8.handlers.confirmation.confirmation.revision, 4)
  assert.match(renderToStaticMarkup(p9.render()), /Responses CSV/)
  assert.match(renderToStaticMarkup(p10.render()), /Factual HTML/)
  assert.equal(p7.handlers.actaDraft.participants, 'Texto libre unchanged')
  assert.equal(p9.handlers.drafts['BP-1_01'].value, 'Texto libre unchanged')
  assert.equal(p9.handlers.drafts['BP-1_01'].evidence_reference, '=1+1')
  assert.equal(p9.handlers.saveQueueRef.current, revisionOwner)
  assert.equal(p10.handlers.factForm.value, '9007199254740993'); assert.equal(p10.handlers.factForm.evidenceRef, '=1+1')
  consumers.forEach(c => c.dispose()); provider.dispose()
})

test('actual fact submit preserves numeric lexemes in the outgoing JSON payload in both locales', async () => {
  const saved = []
  const c = consumer('components/wizard/report-draft-panel', 'ReportDraftPanel', ['updateFactForm', 'handleSaveFact'], harness(), {
    fetch: async (url, request) => {
      if (request.method === 'PUT') {
        assert.equal(url, '/api/report/facts'); saved.push(JSON.parse(request.body))
      }
      return Response.json({ data: url === '/api/report/snapshots' ? [] : null })
    },
  })
  c.render(); await c.settle()
  for (const locale of ['es', 'en']) {
    c.setLocale(locale); await c.settle()
    for (const [valueType, value, decimals] of [['integer', '9007199254740993', '0'], ['number', '9007199254740993.125', '3'], ['monetary', '+0009007199254740993.10', '2']]) {
      for (const [key, field] of Object.entries({ datapointId: 'BP-1_01', valueType, value, decimals, unit: 'pure' })) c.handlers.updateFactForm(key, field)
      await c.settle(); await c.handlers.handleSaveFact({ preventDefault() {} }); await c.settle()
      assert.equal(saved.at(-1).facts[0].value, value)
      assert.equal(saved.at(-1).facts[0].value_type, valueType)
    }
  }
  assert.equal(saved.length, 6)
  c.dispose()
})

test('expectations and topic assistant render both languages and retain notes and signal codes', async () => {
  const c = consumer('components/wizard/wizard-expectations', 'WizardExpectations', [], harness())
  assert.match(renderToStaticMarkup(c.render()), /Qué vas a hacer aquí/)
  c.setLocale('en'); const html = renderToStaticMarkup(c.render())
  assert.match(html, /What you will do here/); assert.match(html, /Step 1/); assert.doesNotMatch(html, /Qué necesitas|Paso 1/); c.dispose()
  const results = []
  const props = { topic: { id: 1, esrs_code: 'E1', theme: { es: 'Cambio climático', en: 'Climate change' } }, exposicionDefault: 'fuerte', initialAnswer: { note: 'Texto libre unchanged' }, onResult: v => results.push(v) }
  const assistant = consumer('components/wizard/topic-signal-assistant', 'TopicSignalAssistant', ['setUserNote', 'setSig', 'note'], harness())
  assistant.render(props); assistant.handlers.setUserNote('=1+1'); assistant.render(props)
  assistant.setLocale('en', props)
  const english = renderToStaticMarkup(assistant.render(props))
  assert.match(english, /Climate change/); assert.doesNotMatch(english, /¿Cuánto|Cambio climático|Sugerencia:/)
  assert.equal(assistant.handlers.note, '=1+1'); assistant.handlers.setSig('impacto', 'alto')
  assert.equal(results.at(-1).impacto, 'alto'); assert.equal(results.at(-1).note, '=1+1'); assistant.dispose()
})

test('P6 and P8 render actual catalog topics in both languages without translating group keys or notes', async () => {
  const topic = { ...proposalTopic, theme: { es: 'Cambio climático', en: 'Climate change' }, subtheme: { es: 'Adaptación al cambio climático', en: 'Climate change adaptation' } }
  const p6 = consumer('components/wizard/material-topics-form', 'MaterialTopicsForm', [], harness(), {
    getLaravelSession: async () => ({ data: {} }), getLaravelCharacterization: async () => ({ data: { status: 'completed', form_data: {} } }),
    getLaravelMaterialityProposal: async () => ({ data: { ...completedProposal, proposal_topics: [topic] } }),
  })
  p6.render(); await p6.settle(); assert.match(renderToStaticMarkup(p6.render()), /Cambio climático/)
  p6.setLocale('en'); assert.match(renderToStaticMarkup(p6.render()), /Climate change/)
  assert.doesNotMatch(renderToStaticMarkup(p6.render()), /La IA ha propuesto|Temas relevantes propuestos/); p6.dispose()
  const confirmation = { characterization_id: 7, p6_topic_ids: [1], confirmed_topic_ids: [1], confirmation: { revision: 4, change_reasons: {}, guided_answers: {} }, adm: {}, is_stale: false }
  const p8 = consumer('components/wizard/final-topics-selection', 'FinalTopicsSelection', ['chooseMode', 'setChangeNotes', 'changeNotes', 'groupedCatalog'], harness(), {
    getLaravelSession: async () => ({ data: { csrf_token: 'csrf' } }), getLaravelMaterialityConfirmation: async () => ({ data: confirmation }),
    getLaravelEsrsTopics: async () => ({ data: [topic, { ...topic, id: 2, esrs_code: 'G1' }] }), previewLaravelMaterialityConfirmation: async () => ({ data: {} }),
  })
  p8.render(); await p8.settle(); p8.handlers.chooseMode('direct'); p8.handlers.setChangeNotes({ 1: 'Texto libre unchanged' }); await p8.settle()
  assert.match(renderToStaticMarkup(p8.render()), /Selección final de temas/)
  p8.setLocale('en'); await p8.settle()
  assert.equal(p8.handlers.groupedCatalog.Ambiente.length, 1); assert.equal(p8.handlers.groupedCatalog.Gobernanza.length, 1)
  const english = renderToStaticMarkup(p8.render()); assert.match(english, /Climate change/); assert.doesNotMatch(english, /Selección final de temas/)
  assert.equal(p8.handlers.changeNotes[1], 'Texto libre unchanged'); p8.dispose()
})

test('auth system notices change language while entered name and passwords remain intact', async () => {
  const c = consumer('components/auth/register-form', 'RegisterForm', ['setError', 'setName', 'name'], harness(), { getLaravelRegisterConfig: async () => ({ data: { registration_enabled: true } }) })
  c.render(); c.handlers.setName('Texto libre unchanged')
  c.handlers.setError({ source: 'Las contraseñas no coinciden', values: [] }); await c.settle()
  assert.match(renderToStaticMarkup(c.render()), /Las contraseñas no coinciden/)
  c.setLocale('en'); const html = renderToStaticMarkup(c.render())
  assert.match(html, /Passwords do not match/); assert.doesNotMatch(html, /Las contraseñas no coinciden/)
  assert.equal(c.handlers.name, 'Texto libre unchanged'); c.dispose()
})

test('forgot password, help and dashboard produce Spanish and English render trees', async () => {
  const forgot = consumer('app/(auth)/forgot-password/page', 'default', [], harness())
  assert.match(renderToStaticMarkup(forgot.render()), /contraseña/i)
  forgot.setLocale('en'); assert.match(renderToStaticMarkup(forgot.render()), /password/i)
  assert.doesNotMatch(renderToStaticMarkup(forgot.render()), /contraseña/i); forgot.dispose()
  for (const [path, spanish, english] of [
    ['app/help/page', /Ayuda/, /Help/], ['app/(dashboard)/dashboard/page', /Te damos la bienvenida/, /Welcome/],
  ]) {
    const c = consumer(path, 'default', [], harness())
    assert.match(renderToStaticMarkup(await c.render()), spanish)
    c.setLocale('en'); const html = renderToStaticMarkup(await c.render())
    assert.match(html, english); assert.doesNotMatch(html, spanish); c.dispose()
  }
})

test('report presentation has one fetch owner and late old-language replies cannot replace the new display or draft', async () => {
  let oldReadiness, oldDraft, reads = 0, draftsRead = 0, factsRead = 0
  const c = consumer('components/wizard/report-draft-panel', 'ReportDraftPanel', ['readiness', 'draft', 'factForm', 'updateFactForm'], harness(), {
    getLaravelSession: async () => ({ data: {} }),
    getLaravelReportingFacts: async () => { factsRead++; return { data: null } },
    getLaravelReportTaxonomyStatus: async () => ({ data: null }), getLaravelReportSnapshots: async () => ({ data: [] }),
    getLaravelReportReadiness: () => ++reads === 1 ? new Promise(resolve => { oldReadiness = resolve }) : Promise.resolve({ data: null }),
    getLaravelReportDraft: () => ++draftsRead === 1 ? new Promise(resolve => { oldDraft = resolve }) : Promise.resolve({ data: null }),
  })
  c.render(); await c.settle()
  c.handlers.updateFactForm('value', 'Texto libre unchanged'); c.handlers.updateFactForm('evidenceRef', '=1+1'); await c.settle()
  c.setLocale('en'); await c.settle()
  oldReadiness({ data: { locale: 'es', sections: {}, downloads: {} } })
  oldDraft({ data: { locale: 'es', materiality: { confirmed_topics: [] } } })
  await c.settle()
  assert.equal(c.handlers.readiness, null); assert.equal(c.handlers.draft, null)
  assert.equal(c.handlers.factForm.value, 'Texto libre unchanged'); assert.equal(c.handlers.factForm.evidenceRef, '=1+1')
  assert.equal(factsRead, 1); assert.equal(reads, 2); assert.equal(draftsRead, 2)
  assert.match(renderToStaticMarkup(c.render()), /report/i); c.dispose()
})

test('verify-email language changes retain the sending guard and user email verbatim', async () => {
  const props = { email: 'Texto libre unchanged' }
  const c = consumer('components/auth/verify-email-notice', 'VerifyEmailNotice', ['status', 'setStatus'], harness())
  c.render(props); c.handlers.setStatus('sending'); c.render(props)
  c.setLocale('en', props)
  assert.equal(c.handlers.status, 'sending')
  const html = renderToStaticMarkup(c.render(props))
  assert.match(html, /disabled/); assert.match(html, /Sending/); assert.match(html, /Texto libre unchanged/)
  c.handlers.setStatus('sent'); c.render(props)
  assert.match(renderToStaticMarkup(c.render(props)), /verification email/i)
  c.dispose()
})


test('localized P9 continues only after its current edit succeeds, preserving failed and older edits', async () => {
  let release, rejectSave
  const snapshot = p9Workspace()
  const c = consumer('components/wizard/esrs-datapoints-form', 'EsrsDatapointsForm', ['updateDraft', 'handleSave', 'drafts'], harness(), {
    getLaravelSession: async () => ({ data: { csrf_token: 'csrf' } }),
    getLaravelEsrsDatapointWorkspace: async () => ({ ...structuredClone(snapshot), data: { ...snapshot.data, locale: c.locale } }),
    updateLaravelEsrsDatapointResponses: () => new Promise((resolve, reject) => { release = resolve; rejectSave = reject }),
  })
  c.render(); await c.settle(); c.setLocale('en'); await c.settle()
  c.handlers.updateDraft('E1-1', { value: 'First edit' }); await c.settle()
  const oldSave = c.handlers.handleSave(true); await c.settle()
  c.handlers.updateDraft('E1-1', { value: 'Latest edit unchanged' }); await c.settle()
  release({ data: { revision: 2 } }); await oldSave; await c.settle()
  assert.deepEqual(c.pushes, [])
  const failed = c.handlers.handleSave(true); await c.settle()
  rejectSave(new ApiError(503)); await failed; await c.settle()
  assert.deepEqual(c.pushes, [])
  assert.equal(c.handlers.drafts['E1-1'].value, 'Latest edit unchanged')
  const latest = c.handlers.handleSave(true); await c.settle()
  release({ data: { revision: 3 } }); await latest; await c.settle()
  assert.deepEqual(c.pushes, ['/wizard/step-6'])
  c.dispose()
})

test('a delayed locale publication cannot replace a reloaded atomic P9 workspace', async () => {
  let reads = 0, oldLocale
  const c = consumer('components/wizard/esrs-datapoints-form', 'EsrsDatapointsForm', ['reload', 'corpus'], harness(), {
    getLaravelSession: async () => ({ data: { csrf_token: 'csrf' } }),
    getLaravelEsrsDatapointWorkspace: () => {
      if (++reads === 2) return new Promise(resolve => { oldLocale = resolve })
      const snapshot = p9Workspace()
      snapshot.data.locale = c.locale
      return Promise.resolve(snapshot)
    },
  })
  c.render(); await c.settle(); c.setLocale('en'); await c.settle()
  c.handlers.reload(); await c.settle()
  const stale = p9Workspace()
  stale.data.characterization_id = 99
  oldLocale(stale); await c.settle()
  assert.equal(c.handlers.corpus.characterization_id, 9)
  assert.equal(c.handlers.corpus.locale, 'en')
  c.dispose()
})

test('learning panel localizes synthetic-only copy without granting review or mutation authority', async () => {
  for (const [status, spanish, english] of [
    ['disabled', 'Deshabilitado', 'Disabled'], ['blocked', 'Bloqueado', 'Blocked'],
    ['ready', 'Disponible para revisión', 'Available for review'], ['closed', 'Cerrado', 'Closed'],
    ['stale', 'Fuentes modificadas', 'Sources changed'], ['withdrawn', 'Retirado', 'Withdrawn'],
  ]) {
    let writes = 0, sessions = 0, reads = 0
    const c = consumer('components/wizard/learning-case-panel', 'LearningCasePanel', ['patch', 'perform', 'ui'], harness(), {
      getLaravelLearningCaseDraft: async () => { reads++; return { data: { status, can_close: false, can_withdraw: false, source_token: null, expected_revisions: null, expected_authorization_generation: null, draft: null } } },
      getLaravelSession: async () => { sessions++; return { data: { csrf_token: 'synthetic' } } },
      saveLaravelLearningCaseDraft: async () => { writes++ }, closeLaravelLearningCase: async () => { writes++ }, withdrawLaravelLearningCase: async () => { writes++ },
    })
    c.render(); await c.settle()
    const initial = JSON.stringify(c.handlers.ui)
    for (const locale of ['es', 'en', 'es']) {
      c.setLocale(locale); await c.settle()
      const html = renderToStaticMarkup(c.render())
      assert.ok(html.includes(locale === 'es' ? spanish : english))
      assert.match(html, locale === 'es' ? /Solo mecanismo local sintético.*no es consentimiento jurídico aprobado/ : /Local synthetic mechanism only.*not approved legal consent/)
      assert.match(html, locale === 'es' ? /no concede derechos reales y no acredita mejora predictiva/ : /grants no real rights and does not establish predictive improvement/)
      assert.match(html, /<fieldset disabled=""/)
      for (const label of locale === 'es' ? ['Guardar revisión', 'Cerrar caso', 'Retirar caso'] : ['Save review', 'Close case', 'Withdraw case']) {
        assert.ok(html.match(/<button\b[^>]*disabled=""[^>]*>.*?<\/button>/g)?.some(button => button.includes(label)), label)
      }
      c.handlers.patch('reviewed_universe', true); c.handlers.patch('final_for_period_scope', true)
      await c.handlers.perform('save'); await c.handlers.perform('close'); await c.settle()
      assert.equal(JSON.stringify(c.handlers.ui), initial)
      assert.equal(writes, 0); assert.equal(sessions, 0)
    }
    assert.equal(reads, 1, 'a language choice must not refresh learning authority')
    c.dispose()
  }
})

test('learning panel retains and translates authored read errors when language changes', async () => {
  const c = consumer('components/wizard/learning-case-panel', 'LearningCasePanel', [], harness(), {
    getLaravelLearningCaseDraft: async () => { throw new ApiError(503) },
  })
  c.render(); await c.settle()
  assert.match(renderToStaticMarkup(c.render()), /No se pudo leer el estado\. Reintente la lectura\./)
  c.setLocale('en'); await c.settle()
  const english = renderToStaticMarkup(c.render())
  assert.match(english, /Reading status…/)
  assert.match(english, /Could not read the status\. Try reading it again\./)
  assert.doesNotMatch(english, /No se pudo leer|Leyendo estado/)
  c.dispose()
})

test('P5 hides stale option labels and opaque LEI errors during held and failed EN to ES refresh without losing draft', async () => {
  let held = false, rejectOptions
  const options = locale => ({ locale, levels: { core: { required_fields: [], draft_clearable_fields: [],
    company_profile: { headquarters_countries: { Spain: locale === 'es' ? 'España' : 'Spain' }, reporting_scopes: { individual: locale === 'es' ? 'Individual' : 'Individual entity' }, reporting_currencies: { EUR: 'EUR' }, product_service_types: { physical_product_manufacturing: locale === 'es' ? 'Fabricación de productos físicos' : 'Physical product manufacturing' } },
    operations: { regions: { eu: locale === 'es' ? 'Unión Europea' : 'European Union' }, value_chain: { upstream: locale === 'es' ? 'Actividades anteriores' : 'Upstream activities' }, employee_count_ranges: { '50_249': '50-249' }, revenue_ranges: { '2m_to_10m': 'EUR 2M-10M' } },
  } } })
  const characterization = { revision: 9, nace_code: 'A1.1', form_data: { company_profile: { company_name: 'Texto libre unchanged', headquarters_country: 'Spain', reporting_year: 2025, reporting_scope: 'individual', num_subsidiaries_countries: 0, stock_listed: false, reporting_currency: 'EUR', product_service_type: 'physical_product_manufacturing', entity_identifier: 'USER-LEI' }, operations: { regions: ['eu'], value_chain: ['upstream'], employee_count_range: '50_249', revenue_range: '2m_to_10m' }, notes: '=1+1' } }
  const c = consumer('components/wizard/initial-survey-form', 'InitialSurveyForm', ['formData', 'fieldErrors', 'setIsReadOnly', 'handleSubmit'], harness(), {
    getLaravelSession: async () => ({ data: { csrf_token: 'synthetic' } }), getLaravelCharacterization: async () => ({ data: characterization }),
    getLaravelCharacterizationOptions: () => held ? new Promise((_, reject) => { rejectOptions = reject }) : Promise.resolve({ data: options(c.locale) }),
    saveLaravelCharacterizationDraft: async () => { const error = new ApiError(422); error.payload = { errors: { 'form_data.company_profile.entity_identifier': ['The entity identifier must be a valid LEI.'] } }; throw error },
  })
  c.setLocale('en'); await c.settle()
  assert.match(renderToStaticMarkup(c.render()), /Physical product manufacturing/)
  await c.handlers.handleSubmit({ preventDefault() {} }); await c.settle()
  c.handlers.setIsReadOnly(false); await c.settle()
  assert.match(renderToStaticMarkup(c.render()), /The entity identifier must be a valid LEI/)
  c.handlers.setIsReadOnly(true); await c.settle()
  const original = JSON.stringify(c.handlers.formData)
  held = true; c.setLocale('es'); await c.settle()
  for (const phase of ['held', 'failed']) {
    if (phase === 'failed') { rejectOptions(new ApiError(503)); await c.settle() }
    const html = renderToStaticMarkup(c.render())
    assert.doesNotMatch(html, /Physical product manufacturing|Individual entity|Upstream activities|The entity identifier|>Spain<|>individual<|physical_product_manufacturing/)
    assert.match(html, phase === 'held' ? /Actualizando las etiquetas/ : /No se pudieron actualizar las etiquetas/)
    assert.equal(JSON.stringify(c.handlers.formData), original)
    assert.equal(characterization.revision, 9)
    assert.deepEqual(Object.keys(c.handlers.fieldErrors), [])
    c.handlers.setIsReadOnly(false); await c.settle()
    assert.doesNotMatch(renderToStaticMarkup(c.render()), /The entity identifier must be a valid LEI/)
    assert.equal(JSON.stringify(c.handlers.formData), original)
    c.handlers.setIsReadOnly(true); await c.settle()
  }
  c.dispose()
})

test('P10 gates held failed and invalid-locale presentation while retaining facts snapshots and editor input', async () => {
  for (const outcome of ['failed', 'invalid']) {
    let held = false, completeDraft
    const c = consumer('components/wizard/report-draft-panel', 'ReportDraftPanel', ['draft', 'storedDraft', 'storedReadiness', 'facts', 'snapshots', 'factForm', 'updateFactForm'], harness(), {
      getLaravelSession: async () => ({ data: {} }), getLaravelReportingFacts: async () => ({ data: { revision: 7, facts: [] } }), getLaravelReportTaxonomyStatus: async () => ({ data: null }), getLaravelReportSnapshots: async () => ({ data: [{ id: 8, revision: 3 }] }),
      getLaravelReportReadiness: async () => ({ data: { locale: c.locale, status: 'ready', sections: {}, downloads: {} } }),
      getLaravelReportDraft: () => held ? new Promise((resolve, reject) => { completeDraft = outcome === 'failed' ? () => reject(new ApiError(503)) : () => resolve({ data: draft('fr') }) }) : Promise.resolve({ data: draft(c.locale) }),
    })
    function draft(locale) { return { locale, company: {}, materiality: { confirmed_topics: [] }, datapoints: { blocks: [{ key: 'topical', title: 'Topical English title', summary: {} }] }, exports: {}, limitations: [] } }
    c.setLocale('en'); await c.settle()
    assert.match(renderToStaticMarkup(c.render()), /Topical English title/)
    c.handlers.updateFactForm('value', '9007199254740993.125'); c.handlers.updateFactForm('evidenceRef', '=1+1'); await c.settle()
    const preserved = JSON.stringify([c.handlers.factForm, c.handlers.facts, c.handlers.snapshots, c.handlers.storedDraft, c.handlers.storedReadiness])
    held = true; c.setLocale('es'); await c.settle()
    for (const phase of ['held', 'finished']) {
      if (phase === 'finished') { completeDraft(); await c.settle() }
      const html = renderToStaticMarkup(c.render())
      assert.doesNotMatch(html, /Topical English title/)
      assert.doesNotMatch(html, /href="\/report\/draft"/)
      assert.match(html, phase === 'held' ? /Actualizando las etiquetas/ : /No se pudieron actualizar las etiquetas/)
      assert.equal(JSON.stringify([c.handlers.factForm, c.handlers.facts, c.handlers.snapshots, c.handlers.storedDraft, c.handlers.storedReadiness]), preserved)
    }
    c.dispose()
  }
})

test('real display helpers localize booleans and unavailable enums without changing fact lexemes', () => {
  const c = consumer('components/wizard/report-draft-panel', 'ReportDraftPanel', ['safeFactValue', 'applicabilityLabel'], harness(), {
    getLaravelSession: async () => ({ data: {} }), getLaravelReportingFacts: async () => ({ data: null }), getLaravelReportTaxonomyStatus: async () => ({ data: null }), getLaravelReportSnapshots: async () => ({ data: [] }), getLaravelReportReadiness: async () => ({ data: null }), getLaravelReportDraft: async () => ({ data: null }),
  })
  c.render()
  for (const [locale, yes, no, unavailable] of [['es', 'Sí', 'No', 'No disponible'], ['en', 'Yes', 'No', 'Unavailable']]) {
    for (const [value, expected] of [[true, yes], [false, no], ['9007199254740993.125', '9007199254740993.125'], ['true', 'true']]) {
      const fact = { nil: false, value }; const bytes = JSON.stringify(fact)
      assert.equal(c.handlers.safeFactValue(fact, locale), expected)
      assert.equal(JSON.stringify(fact), bytes)
    }
    assert.equal(c.handlers.applicabilityLabel('future_unknown_code', locale), unavailable)
  }
  c.dispose()
})

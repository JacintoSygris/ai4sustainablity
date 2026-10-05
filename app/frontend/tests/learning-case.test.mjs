import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { learningCaseConflict } from '../lib/learning-case-state.mjs'
import * as state from '../lib/learning-case-state.mjs'
import vm from 'node:vm'
import { createRequire } from 'node:module'
const ts = createRequire(import.meta.url)('typescript')

// Executes the actual panel with a local hook/render circuit, not a browser.
function panelHarness(snapshot) {
  const hooks = [], effects = [], sent = []
  let cursor = 0, tree, current = snapshot, uuid = 0
  const jsx = (type, props) => ({ type, props })
  const react = {
    useState(initial) { const i = cursor++; if (!(i in hooks)) hooks[i] = initial; return [hooks[i], v => { hooks[i] = typeof v === 'function' ? v(hooks[i]) : v }] },
    useRef(initial) { const i = cursor++; if (!(i in hooks)) hooks[i] = { current: initial }; return hooks[i] },
    useEffect(effect) { const i = cursor++; if (!(i in hooks)) { hooks[i] = true; effects.push(effect) } },
  }
  class ApiError extends Error { constructor() { super('synthetic conflict'); this.status = 409 } }
  const api = { LaravelApiError: ApiError,
    getLaravelSession: async () => ({ data: { csrf_token: 'synthetic' } }),
    getLaravelLearningCaseDraft: async () => { sent.push({ method: 'GET' }); return { data: current } },
  }
  for (const [name, method] of [['saveLaravelLearningCaseDraft','PUT'],['closeLaravelLearningCase','POST close'],['withdrawLaravelLearningCase','POST withdraw']]) {
    api[name] = async command => { sent.push({ method, command: JSON.parse(JSON.stringify(command)) }); if (h.conflict) { h.conflict = false; throw new ApiError() } }
  }
  const context = vm.createContext({ exports: {}, crypto: { randomUUID: () => `synthetic-key-${++uuid}` }, require(name) {
    if (name === 'react') return react
    if (name === 'react/jsx-runtime') return { jsx, jsxs: jsx }
    if (name === '@/lib/learning-case-state.mjs') return state
    if (name === '@/lib/laravel-api') return api
    if (name === '@/components/ui/button') return { Button: 'Button' }
    if (name === '@/components/ui/card') return { Card: 'Card', CardContent: 'CardContent' }
    throw new Error(`Unexpected dependency ${name}`)
  } })
  const source = readFileSync(new URL('../components/wizard/learning-case-panel.tsx', import.meta.url), 'utf8')
  vm.runInContext(ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX } }).outputText, context)
  function nodes(node) { return !node || typeof node !== 'object' ? [] : [node, ...[node.props?.children].flat(Infinity).flatMap(nodes)] }
  const h = { sent, conflict: false,
    render() { cursor = 0; tree = context.exports.LearningCasePanel(); return tree },
    button(label) { h.render(); return nodes(tree).find(n => n.type === 'Button' && n.props.children === label) },
    input(index) { h.render(); return nodes(tree).filter(n => n.type === 'input')[index] },
    ui() { return hooks[1] },
    async flush() { await new Promise(resolve => setImmediate(resolve)); h.render() },
    async mount() { h.render(); for (const effect of effects) effect(); await h.flush() },
    async reload(next) { current = next; h.button('Releer estado').props.onClick(); await h.flush() },
    check(index, checked = true) { h.input(index).props.onChange({ target: { checked } }); h.render() },
    async force(label) { h.button(label).props.onClick(); await h.flush() },
  }
  return h
}
function source(token = 'F1', draft = null) {
  return { status: 'ready', can_close: true, can_withdraw: false, expected_revisions: { synthetic: token }, expected_authorization_generation: 1, source_token: token, draft }
}
function reviewed(token = 'F1') { return { reviewed_universe: true, final_for_period_scope: true, idempotency_key: 'saved-key', source_token: token } }
function mutations(h) { return h.sent.filter(x => x.method !== 'GET') }
async function assertBlocked(h) {
  assert.equal(h.button('Cerrar caso').props.disabled, true)
  assert.equal(h.button('Guardar revisión').props.disabled, true)
  const before = mutations(h).length
  // Bypass button disabled to exercise the real internal perform guard.
  await h.force('Cerrar caso'); await h.force('Guardar revisión')
  assert.equal(mutations(h).length, before)
}

test('manual F1 to F2 reload preserves intentions but blocks close and save until explicit recheck', async () => {
  const h = panelHarness(source()); await h.mount(); h.check(0); h.check(1)
  const key = h.ui().draft.idempotency_key
  await h.reload(source('F2'))
  await h.force('Guardar revisión'); await h.force('Cerrar caso')
  assert.equal(mutations(h).length, 0, 'stale manual review must not dispatch PUT or POST')
  assert.equal(h.ui().draft.reviewed_universe, true); assert.equal(h.ui().draft.final_for_period_scope, true)
  assert.equal(h.ui().draft.source_token, 'F1'); assert.equal(h.ui().draft.idempotency_key, key)
  assert.equal(h.ui().requiresReview, true); assert.equal(mutations(h).length, 0)
  await assertBlocked(h); h.check(0)
  assert.notEqual(h.ui().draft.idempotency_key, key); assert.equal(h.ui().draft.source_token, 'F2')
  await h.force('Guardar revisión'); assert.equal(mutations(h)[0].command.source_token, 'F2')
  await h.force('Cerrar caso'); assert.equal(mutations(h)[1].method, 'POST close')
})
test('cold hydration of F1 draft against F2 cannot save true declarations as F2', async () => {
  const h = panelHarness(source('F2', reviewed())); await h.mount()
  await h.force('Guardar revisión')
  assert.equal(mutations(h).length, 0, 'cold F1 declarations must not be saved with F2 token')
  assert.equal(h.ui().draft.idempotency_key, 'saved-key'); assert.equal(h.ui().draft.source_token, 'F1')
  assert.equal(h.ui().draft.final_for_period_scope, true); assert.equal(h.ui().requiresReview, true)
  assert.equal(mutations(h).length, 0); await assertBlocked(h)
  await h.reload(source('F2', reviewed())); await assertBlocked(h)
  h.check(0); await h.force('Guardar revisión')
  assert.equal(mutations(h)[0].command.reviewed_universe, true)
  assert.equal(mutations(h)[0].command.source_token, 'F2')
  assert.notEqual(mutations(h)[0].command.idempotency_key, 'saved-key')
})
test('same-token cold hydration and manual reload preserve valid review and key without mutations', async () => {
  const h = panelHarness(source('F1', reviewed())); await h.mount(); await h.reload(source('F1'))
  assert.equal(h.ui().requiresReview, false); assert.equal(h.ui().draft.idempotency_key, 'saved-key')
  assert.equal(h.button('Cerrar caso').props.disabled, false); assert.equal(mutations(h).length, 0)
  await h.force('Cerrar caso'); assert.equal(mutations(h)[0].command.idempotency_key, 'saved-key')
})
test('missing or lost source token never hydrates or reloads as reviewed', async () => {
  for (const [token, draft] of [['F2', reviewed(null)], [null, reviewed()], ['F2', { ...reviewed(), source_token: undefined }]]) {
    const h = panelHarness(source(token, draft)); await h.mount()
    assert.equal(h.ui().requiresReview, true); assert.equal(h.ui().draft.final_for_period_scope, true)
    assert.equal(mutations(h).length, 0); await assertBlocked(h)
  }
  const h = panelHarness(source('F1', reviewed())); await h.mount(); await h.reload(source(null))
  assert.equal(h.ui().requiresReview, true); await assertBlocked(h)
})
test('actual 409 performs only GET with no replay or stale save, even if token stays equal', async () => {
  const h = panelHarness(source('F1', reviewed())); await h.mount(); h.conflict = true
  await h.force('Guardar revisión')
  assert.deepEqual(h.sent.map(x => x.method), ['GET','PUT','GET'])
  assert.equal(h.ui().draft.idempotency_key, 'saved-key'); assert.equal(h.ui().requiresReview, true)
  await assertBlocked(h); h.check(0)
  assert.notEqual(h.ui().draft.idempotency_key, 'saved-key'); await h.force('Cerrar caso')
  assert.equal(mutations(h).length, 2)
})
test('actual action guard and buttons refuse incomplete declarations or non-closeable state', async () => {
  const h = panelHarness(source()); await h.mount(); h.check(0)
  assert.equal(h.button('Cerrar caso').props.disabled, true)
  await h.force('Cerrar caso'); assert.equal(mutations(h).length, 0)
  h.check(1); await h.reload({ ...source('F1'), can_close: false, status: 'blocked' })
  await assertBlocked(h); assert.equal(mutations(h).length, 0)
})
test('409 retains explicit draft and requests read only, never replay', () => {
  const draft = { reviewed_universe: true, final_for_period_scope: true, idempotency_key: 'synthetic-key' }
  const next = learningCaseConflict({ draft, needsReload: false, requiresReview: false })
  assert.deepEqual(next.draft, draft)
  assert.equal(next.needsReload, true)
  assert.equal(next.requiresReview, true)
  assert.equal(next.replay, false)
})
test('Step5 mounts accessible synthetic-only panel and CSRF helpers without auto mutation', () => {
  const panel = readFileSync(new URL('../components/wizard/learning-case-panel.tsx', import.meta.url), 'utf8')
  const page = readFileSync(new URL('../app/(dashboard)/wizard/step-5/page.tsx', import.meta.url), 'utf8')
  assert.match(page, /<LearningCasePanel/)
  assert.match(page, /<EsrsDatapointsForm/)
  assert.match(panel, /getLaravelSession/)
  assert.match(panel, /role="status"/)
  assert.match(panel, /consentimiento jurídico aprobado/)
  assert.doesNotMatch(panel, /setInterval|autoClose|autoRevoke/)
})

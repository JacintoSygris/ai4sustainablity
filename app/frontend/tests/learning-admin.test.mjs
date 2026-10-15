import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import vm from 'node:vm'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import ts from 'typescript'
import { canLearningAction, learningCheckLabels, learningStatusLabels, learningLoopLabels, learningFallbackLabels } from '../lib/learning-admin.mjs'
import { learningEnglish } from '../lib/i18n/learning-messages.mjs'
import { learningPrivacy, learningInvitation } from '../lib/learning-copy.mjs'
import { ui, hasEnglishCopy } from '../lib/i18n/messages.mjs'

function ready() {
  return { checks: Object.fromEntries(Object.keys(learningCheckLabels).map(k => [k, true])),
    engine_enabled: true, bandit_mode: 'active', consented_users: 0, pending_events: 0, processed_events: 0,
    wp13: { active: null, candidates: [] }, wp23: { active: null, candidate: null }, runs: [], actions: [] }
}

test('every missing prerequisite blocks training and promotion, while rollback remains available when disabled', () => {
  for (const key of Object.keys(learningCheckLabels)) {
    const state = ready(); state.checks[key] = false
    assert.equal(canLearningAction(state, 'wp13', 'train'), false, key)
  }
  const state = ready(); state.engine_enabled = false; state.bandit_mode = 'off'
  assert.equal(canLearningAction(state, 'wp13', 'rollback'), true)
  assert.equal(canLearningAction(state, 'wp23', 'process'), false)
  assert.equal(canLearningAction(null, 'wp13', 'train'), false)
})

test('candidate promotion requires eligibility and a passed supervised-engine gate', () => {
  assert.equal(canLearningAction(ready(), 'wp13', 'promote', { eligible: true, gate: { passed: false } }), false)
  assert.equal(canLearningAction(ready(), 'wp13', 'promote', { eligible: false, gate: { passed: true } }), false)
  assert.equal(canLearningAction(ready(), 'wp13', 'promote', { eligible: true, gate: { passed: true } }), true)
  assert.equal(canLearningAction(ready(), 'wp23', 'promote', { eligible: true }), true)
  assert.equal(canLearningAction(ready(), 'wp23', 'promote'), false)
})

test('panel, consent and privacy have Spanish and English system copy', () => {
  for (const text of [...Object.keys(learningEnglish), ...learningPrivacy, learningInvitation,
    ...Object.values(learningCheckLabels), ...Object.values(learningStatusLabels), ...Object.values(learningLoopLabels)]) {
    assert.equal(hasEnglishCopy(text), true, text)
    assert.equal(ui('es', text), text)
    assert.notEqual(ui('en', text), text)
  }
})

function renderPanel(state, locale = 'es') {
  const source = readFileSync(new URL('../components/learning/admin-panel.tsx', import.meta.url), 'utf8')
  const result = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } })
  const cjsModule = { exports: {} }; const realRequire = createRequire(import.meta.url)
  let cursor = 0
  vm.runInNewContext(result.outputText, { exports: cjsModule.exports, module: cjsModule, require: name => {
    if (name === 'react') return { ...React, useEffect: () => {}, useState: initial => [cursor++ === 0 ? state : initial, () => {}] }
    if (name === '@/components/locale-provider') return { useLocale: () => ({ locale }) }
    if (name === '@/lib/i18n/messages.mjs') return { ui }
    if (name === '@/lib/laravel-api') return { laravelApi: () => { throw new Error('unexpected render side effect') } }
    if (name === '@/lib/learning-admin.mjs') return { canLearningAction, learningCheckLabels, learningStatusLabels, learningLoopLabels, learningFallbackLabels }
    return realRequire(name)
  } })
  return renderToStaticMarkup(React.createElement(cjsModule.exports.LearningAdminPanel))
}

test('real panel renders missing prerequisites and safely escapes audit reasons', () => {
  const state = ready(); state.checks.python = false
  state.actions = [{ id: 'synthetic', loop: 'wp23', action: 'process', status: 'failed_closed', admin_id: 1,
    reason: '<img src=x onerror=alert(1)>', before_sha256: 'a'.repeat(64), after_sha256: null, created_at: 'synthetic', error_code: 'synthetic' }]
  const html = renderPanel(state)
  assert.match(html, /Entorno de aprendizaje: Pendiente o no disponible/)
  assert.match(html, /&lt;img/); assert.doesNotMatch(html, /<img src=x/)
  assert.match(html, /role="status"/)
  assert.match(html, /disabled="">Entrenar ahora/)
  assert.match(html, /Motivo de la acción/)
  assert.match(html, /<h2[^>]*>Motor de aprendizaje supervisado · /)
  assert.match(html, /<h2[^>]*>Ajuste por retroalimentación · /)
  assert.match(html, /synthetic · Ajuste por retroalimentación · Procesar eventos/)
  assert.doesNotMatch(html, /WP\d|\bwp\d\d\b/i, 'visible panel copy must use plain names, not internal loop codes')
})

test('real panel exposes candidate comparison and active version hashes', () => {
  const state = ready()
  state.wp13.candidates = [{ id: 'synthetic-model', sha256: 'a'.repeat(64), eligible: true,
    gate: { passed: true, sets: [{ name: 'synthetic-only', served_recall: .7, candidate_recall: .8, lost_true_positives: 0 }] } }]
  state.wp23.candidate = { id: 'synthetic-state', sha256: 'b'.repeat(64), events_applied: 3, eligible: true }
  const html = renderPanel(state)
  assert.match(html, /Sensibilidad en la evaluación histórica/); assert.match(html, /synthetic-only/)
  assert.match(html, /Eventos aplicados: 3/); assert.match(html, new RegExp('b'.repeat(64)))
})

test('actions use fresh Laravel session CSRF and no client supplied user identity', () => {
  const source = readFileSync(new URL('../components/learning/admin-panel.tsx', import.meta.url), 'utf8')
  assert.match(source, /await getLaravelSession\(\)/)
  assert.match(source, /csrfToken: session.data.csrf_token/)
  assert.doesNotMatch(source, /body:.*admin_id|dangerouslySetInnerHTML|localStorage/)
  const page = readFileSync(new URL('../app/(dashboard)/admin/learning/page.tsx', import.meta.url), 'utf8')
  assert.match(page, /session\?\.learning_admin !== true/)
})


test('panel separates selected and effective models, historical metrics and OFF base reset', () => {
  const state = ready()
  state.engine_enabled = false
  state.wp13 = { selected: 'synthetic-stale', effective: null, fallback_reason: 'selected_incompatible_or_unauthorized',
    serving: { serving_enabled: false, baseline: { serving_identity_sha256: 'b'.repeat(64), runtime_config: {} } },
    candidates: [{ id: 'synthetic-stale', eligible: false, comparator: { baseline_identity: 'a'.repeat(64), effective: null, effective_known: true } }] }
  const html = renderPanel(state)
  assert.match(html, /Modelo seleccionado.*synthetic-stale/)
  assert.match(html, /Modelo efectivo según configuración local.*Modelo base/)
  assert.match(html, /incompatible o sin autorización/)
  assert.match(html, /Restablecer modelo base/)
  assert.match(html, /Comparador histórico.*Modelo base/)
  assert.equal(canLearningAction(state, 'wp13', 'promote', state.wp13.candidates[0]), false)
  state.engine_enabled = true
  assert.equal(canLearningAction(state, 'wp13', 'promote', { id: 'synthetic-stale', eligible: true, gate: { passed: true } }), false)
  state.wp23.active = { id: 'synthetic-bandit' }
  assert.equal(canLearningAction(state, 'wp23', 'promote', { id: 'synthetic-bandit', eligible: true }), false)
})


test('real ES/EN panel renders gate metrics support, intervals, criterion and unavailable distinctly from zero', () => {
  const state = ready(); state.gate_metrics_enabled = true
  const metric = value => ({ value, ci: { lower: value, upper: value, valid_replicates: value === null ? 0 : 2000 } })
  const section = { positive_support: 30, negative_support: 40, unknown_support: 10,
    predictors: { candidate: { tp: 0, fp: 0, fn: 30, tn: 40, precision: metric(null), recall: metric(0), f1: metric(0) } },
    paired_delta: { precision: metric(null) } }
  state.wp13.candidates = [{ id: 'synthetic', eligible: false, gate: { schema: 'learning-gate-metrics-v1', passed: false,
    protocol_sha256: 'a'.repeat(64), sets: [], primary: { threshold: .01, passed: false }, policy: { confidence: .95, replicates: 2000 },
    failure_reasons: ['insufficient_evidence'], report: { heldout: { unit: 'keys', companies: 30, micro: section, per_topic: { '<script>': section } } } } }]
  const es = renderPanel(state); const en = renderPanel(state, 'en')
  assert.match(es, /Empresas.*30/); assert.match(es, /30 \/ 40 \/ 10/)
  assert.match(es, /No disponible \[No disponible, No disponible\]/); assert.match(es, /0 \[0, 0\]/)
  assert.match(es, /estrictamente mayor que.*0.01/); assert.match(es, /Datos insuficientes/)
  assert.match(en, /Company-level confidence interval/); assert.match(en, /Unavailable/); assert.match(en, /Insufficient evidence/)
  assert.match(es, /&lt;script&gt;/); assert.doesNotMatch(es, /<script>/)
  assert.match(es, /disabled="">Promover/)
  state.gate_metrics_enabled = false
  assert.doesNotMatch(renderPanel(state), /Intervalo de confianza por empresa/)
})

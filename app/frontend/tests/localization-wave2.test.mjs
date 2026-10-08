import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
const read = p => readFileSync(new URL(p, import.meta.url), 'utf8')

test('locale persistence refreshes server components without navigation or remount keys', () => {
  const provider = read('../components/locale-provider.tsx')
  assert.match(provider, /router\.refresh\(\)/)
  assert.doesNotMatch(provider, /location\.(reload|assign)|key=\{locale\}/)
  assert.match(provider, /setLocale\(next\)/)
  assert.match(provider, /setLocale\(previous\)/)
})
test('expectations and topic signals choose the current locale', () => {
  const expectations = read('../components/wizard/wizard-expectations.tsx')
  assert.doesNotMatch(expectations, /copy\.es\./)
  assert.match(expectations, /copy\[locale\]/)
  const assistant = read('../components/wizard/topic-signal-assistant.tsx')
  assert.match(assistant, /topicTitle\(topic, locale\)/)
  assert.match(assistant, /useLocale\(\)/)
})
test('active server pages resolve locale and client auth uses locale context', () => {
  for (const file of ['app/(dashboard)/dashboard/page.tsx', 'app/help/page.tsx']) {
    assert.match(read(`../${file}`), /getLaravelServerLocale/)
  }
  assert.match(read('../app/(auth)/forgot-password/page.tsx'), /useLocale/)
})

// Product-copy inventory: active route/component source only. No tests, archives or corpora.
import { readdirSync } from 'node:fs'
import ts from 'typescript'
import { hasEnglishCopy, formatUi, ui } from '../lib/i18n/messages.mjs'
const walk = url => readdirSync(url, { withFileTypes: true }).flatMap(entry => {
  const child = new URL(`${entry.name}${entry.isDirectory() ? '/' : ''}`, url)
  return entry.isDirectory() ? walk(child) : [child]
})
const productionTsx = ['../app/', '../components/'].flatMap(dir => walk(new URL(dir, import.meta.url))).filter(p => p.pathname.endsWith('.tsx'))

test('every authored translation call in active pages and components has reviewed English copy', () => {
  const missing = []
  for (const file of productionTsx) {
    const source = ts.createSourceFile(file.pathname, readFileSync(file, 'utf8'), ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX)
    const visit = node => {
      if (ts.isCallExpression(node)) {
        const fn = node.expression.getText(source)
        const key = ['tr', 'systemCopy'].includes(fn) ? node.arguments[0] : ['ui', 'formatUi'].includes(fn) ? node.arguments[1] : null
        if (key && ts.isStringLiteral(key) && !hasEnglishCopy(key.text)) missing.push(`${file.pathname}: ${key.text}`)
      }
      ts.forEachChild(node, visit)
    }
    visit(source)
  }
  assert.deepEqual(missing, [])
  assert.equal(formatUi('en', 'Motivo: {0}', ['Texto libre unchanged']), 'Reason: Texto libre unchanged')
})

test('active JSX cannot silently reintroduce uncatalogued literal copy or human attributes', () => {
  const identifiers = new Set(['Airis', 'Airis Academy', '©Sygris', 'Cookies', 'ESRS', 'Google', 'Microsoft', 'Español', 'English', 'Juan Hernández', '••••••••••••••', 'dpo@sygris.com', 'dpo@cbiconsulting.es', 'www.aepd.es', 'XSRF-TOKEN', 'remember_web_…', 'airis-consent-v1', 'airis-cookie-consent', 'wizard_expectations_dismissed', 'p9_intro_dismissed', 'sidebar_state', 'p8_guided_draft_&lt;id&gt;', 'p8_guided_draft_&lt;id&gt;_conflict', 'p9_drafts_&lt;id&gt;', 'pusherTransportTLS', 'pusherTransportNonTLS'])
  const offenders = []
  for (const file of productionTsx) {
    const source = ts.createSourceFile(file.pathname, readFileSync(file, 'utf8'), ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX)
    const visit = node => {
      let value
      if (ts.isJsxText(node)) value = node.text.trim()
      if (ts.isJsxAttribute(node) && /^(aria-label|title|placeholder|alt)$/.test(node.name.text) && node.initializer && ts.isStringLiteral(node.initializer)) value = node.initializer.text
      if (value && /[A-Za-záéíóúñ]/.test(value) && !identifiers.has(value)) offenders.push(`${file.pathname}: ${value}`)
      ts.forEachChild(node, visit)
    }
    visit(source)
  }
  assert.deepEqual(offenders, [])
})

test('learning panel status and stored system notices have both catalogue projections', () => {
  const panel = read('../components/wizard/learning-case-panel.tsx')
  const statuses = panel.match(/const statusCopy = \{([^\n]+)\}/)[1]
  const notices = [...panel.matchAll(/setMessage\(([^\n]*)/g)].map(match => match[1]).join('\n')
  const keys = [...(statuses + '\n' + notices).matchAll(/"([^"]*)"/g)].map(match => match[1]).filter(key => key && key !== 'withdraw')
  assert.ok(keys.includes('Deshabilitado'))
  assert.ok(keys.includes('Retirada registrada.'))
  assert.ok(keys.includes('Conflicto: lectura pendiente. Sus entradas se conservan.'))
  for (const key of keys) {
    assert.ok(hasEnglishCopy(key), key)
    assert.equal(formatUi('es', key), key)
    assert.notEqual(formatUi('en', key), key)
  }
})

test('report display copy and controlled country/dimension labels have explicit English entries', () => {
  const english = JSON.parse(read('../../web/lang/en.json'))
  const files = ['HtmlReportRenderer', 'DocxRenderer', 'XhtmlIxbrlCandidateRenderer', 'ReportDisplayProjection']
  for (const file of files) {
    for (const match of read(`../../web/app/Services/Report/${file}.php`).matchAll(/->text\('([^']+)'\)/g)) {
      assert.equal(typeof english[match[1]], 'string', `${file}: ${match[1]}`)
    }
  }
  const presentation = read('../../web/app/Services/Report/ReportVisiblePresentation.php')
  for (const part of [presentation.split('private const DIMENSION_MEMBERS_ES = [')[1].split('];')[0], presentation.split('private static function countryLabelsByCode(): array')[1]]) {
    for (const match of part.matchAll(/=> '([^']+)'/g)) assert.equal(typeof english[match[1]], 'string', match[1])
  }
})

test('localized report output receives application locale while immutable builders stay outside translation', () => {
  const controller = read('../../web/app/Http/Controllers/Api/GuidedReportController.php')
  for (const renderer of ['docx', 'html']) assert.ok(controller.includes(`$this->${renderer}->render($ir, app()->getLocale())`))
  assert.match(controller, /xhtmlIxbrl->render\(\$ir, \$profile, \$manifest, app\(\)->getLocale\(\)\)/)
  for (const file of ['ReportSnapshotBuilder', 'ReportClaimBuilder']) assert.doesNotMatch(read(`../../web/app/Services/Report/${file}.php`), /ReportDisplayProjection|translateSystem/)
  assert.doesNotMatch(read('../components/wizard/report-draft-panel.tsx').split('async function loadP10()')[1].split('loadP10()')[0], /setDraft\(|setReadiness\(/)
})

test('guide API checks are explicitly translated and code values in CSV have code or reference headers', () => {
  const es = JSON.parse(read('../../web/lang/es.json'))
  const guide = read('../../web/app/Support/DoubleMaterialityGuide.php')
  const checks = [...guide.matchAll(/^                                '([^']+)',$/gm)].map(x => x[1])
  assert.ok(checks.length >= 16)
  for (const check of checks) assert.ok(es[check], check)
  const headers = JSON.parse(read('../../web/data/esrs_datapoint_labels_es_v1.json')).system.headers
  for (const key of ['block_key', 'disclosure_requirement_key', 'standard', 'dr', 'selection_reasons', 'response_status', 'applicability_mapping_basis']) {
    assert.match(headers[key].es, /Código/i); assert.match(headers[key].en, /code/i)
  }
  assert.match(headers.appendix_b.es, /Referencias/); assert.match(headers.appendix_b.en, /references/)
})


test('standard names use NEIS only in Spanish presentation and retain ESRS in English', () => {
  assert.equal(ui('es', 'ESRS 2'), 'NEIS 2')
  assert.equal(ui('en', 'ESRS 2'), 'ESRS 2')
  assert.equal(ui('en', 'NEIS 2'), 'ESRS 2')
  assert.equal(ui('en', 'NEIS'), 'ESRS')
  assert.equal(formatUi('es', 'Motivo: {0}', ['Texto libre ESRS 2']), 'Motivo: Texto libre ESRS 2')
})

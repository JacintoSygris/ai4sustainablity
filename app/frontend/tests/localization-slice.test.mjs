import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { normalizeLocale, localizedText } from '../lib/i18n/locale.mjs'
import { localized, templateDownloadPath, guideChecks, guideTitle } from '../lib/double-materiality-guide-state.mjs'
import { obligationBadge, responseLabel, datapointDisplayName } from '../lib/esrs-datapoints-state.mjs'
import { ui } from '../lib/i18n/messages.mjs'
import { WIZARD_STEPS } from '../lib/wizard-steps-data.mjs'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
test('full Spanish catalogue retains semantic quantities populations modalities and all disclosure headings', () => {
  const source = JSON.parse(read('../../web/data/esrs_datapoints_ig3.json'))
  const catalog = JSON.parse(read('../../web/data/esrs_datapoint_labels_es_v1.json'))
  const expected = { 'SBM-2_11': /Probable/, 'E3-4_01': /^Consumo total de agua$/, 'S1-8_01': /Porcentaje.*empleados.*convenios/, 'S1-14_01': /Porcentaje.*(personal propio|plantilla propia)/, 'S1-15_02': /Porcentaje.*con derecho.*(utilizado|disfrutado)/, 'S1-15_03': /Porcentaje.*con derecho.*género/, 'S1-13_03': /(Media|Promedio).*horas.*formación.*género/, 'S1-13_04': /(Media|Promedio).*horas.*formación.*empleado/, 'S1-17_02': /Número.*incidentes.*discriminación/, 'E1-9_24': /Número.*derechos de emisión/, 'E1-9_25': /Número.*derechos de emisión/ }
  for (const [id, pattern] of Object.entries(expected)) assert.match(catalog.labels[id], pattern, id)
  for (const id of ['S1-9_03', 'S1-9_04', 'S1-9_05']) assert.match(catalog.labels[id], /número.*porcentaje|porcentaje.*número/i, id)
  const drs = [...new Set(source.datapoints.map(row => row.dr).filter(Boolean))]
  assert.equal(drs.length, 99)
  assert.deepEqual(Object.keys(catalog.disclosure_requirements).sort(), drs.sort())
  for (const id of drs) assert.match(catalog.disclosure_requirements[id].es, /[áéíóúñA-Za-z]/)
  assert.match(catalog.qualifications['E1.SBM-3_04'].es, /fecha.*(cómo|método).*análisis|análisis.*fecha/)
})

test('active Spanish hero uses a first-party Spanish illustration with an honest visible caption', () => {
  const hero = read('../components/landing/hero-section.tsx')
  assert.match(hero, /locale === "es" \? "\/dashboard-sostenibilidad-es.svg"/)
  const svg = read('../public/dashboard-sostenibilidad-es.svg')
  assert.match(svg, /Ilustración de la interfaz; no representa datos de una empresa/)
  assert.doesNotMatch(svg, /Dashboard|ESG Report|Key Performance|Revenue|Carbon Footprint|Target|Progress/)
  assert.match(svg, /Panel de sostenibilidad/)
})
test('Spanish fallback is deterministic and language selection is explicit', () => {
  for (const value of [undefined, null, '', 'fr', 'EN', 'en-US', ['en']]) assert.equal(normalizeLocale(value), 'es')
  assert.equal(normalizeLocale('en'), 'en')
  assert.equal(localizedText({es:'Agua',en:'Water'}, 'en'), 'Water')
  assert.equal(localizedText({en:'Water'}, 'es'), '')
})
test('guide uses active locale and download links inherit server preference', () => {
  assert.equal(localized({es:'Agua',en:'Water'}, 'en'), 'Water')
  assert.equal(templateDownloadPath('iro_register'), '/double-materiality-guide/templates/iro_register.csv')
  assert.match(guideTitle({key:'review_p5_p6'}, 'en'), /Review/)
  assert.match(guideChecks({key:'define_boundaries'}, 'en')[0], /operations/)
  assert.doesNotMatch(guideChecks({key:'review_p5_p6'}, 'en').join(' '), /\bP[5-9]\b/)
})
test('all canonical datapoints have meaningful versioned Spanish labels', () => {
  const canonical = JSON.parse(read('../../web/data/esrs_datapoints_ig3.json'))
  const catalogue = JSON.parse(read('../../web/data/esrs_datapoint_labels_es_v1.json'))
  assert.equal(canonical.datapoints.length, 1184)
  assert.equal(catalogue.version, 1)
  assert.equal(catalogue.canonical_sha256, createHash('sha256').update(read('../../web/data/esrs_datapoints_ig3.json')).digest('hex'))
  assert.deepEqual(Object.keys(catalogue.labels).sort(), canonical.datapoints.map(d => d.id).sort())
  for (const d of canonical.datapoints) {
    const label = catalogue.labels[d.id]
    assert.ok(typeof label === 'string' && label.length > 8, d.id)
    assert.notEqual(label, d.name, d.id)
    assert.doesNotMatch(label, /^(Dato|Punto de dato|Información) (ESRS|normativ[oa])\b/, d.id)
  }
})
test('P9 display never falls back to canonical English in Spanish', () => {
  assert.equal(datapointDisplayName({name:'Raw English',display:{name:'Etiqueta española',locale:'es'}}, 'es'), 'Etiqueta española')
  assert.equal(datapointDisplayName({name:'Raw English'}, 'es'), '')
  assert.equal(datapointDisplayName({name:'Raw English'}, 'en'), 'Raw English')
  assert.equal(obligationBadge({}, 'en').label, 'Mandatory')
  assert.equal(responseLabel('completed', 'en'), 'Completed')
  const form = read('../components/wizard/esrs-datapoints-form.tsx')
  assert.doesNotMatch(form, /\{datapoint\.name\}/)
  assert.match(form, /datapointDisplayName\(datapoint, locale\)/)
})

test('sidecar covers every current system metadata value and localized export column', () => {
  const canonical = JSON.parse(read('../../web/data/esrs_datapoints_ig3.json'))
  const catalogue = JSON.parse(read('../../web/data/esrs_datapoint_labels_es_v1.json'))
  for (const d of canonical.datapoints) for (const key of ['data_type', 'conditional_or_alternative', 'phase_in_less_than_750', 'phase_in_all', 'inclusion_type']) {
    if (d[key]) assert.ok(catalogue.system.strings[d[key]], `${d.id}: ${key}=${d[key]}`)
  }
  for (const column of Object.values(catalogue.system.headers)) {
    assert.ok(column.es); assert.ok(column.en)
  }
})

test('the shared navigation and guide advice on both localized steps have English copy', () => {
  for (const step of WIZARD_STEPS) for (const copy of [step.title, step.description]) {
    assert.notEqual(ui('en', copy), copy)
    assert.equal(ui('es', copy), copy)
  }
  for (const copy of ['Volver a inicio', 'Academia', 'Consejos prácticos', '¿Necesitas ayuda?', 'Abrir menú de usuario', 'Cerrar sesión']) {
    assert.notEqual(ui('en', copy), copy)
  }
})

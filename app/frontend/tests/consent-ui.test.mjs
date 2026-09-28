import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createConsent } from '../public/consent/core.mjs'
import { mountConsent } from '../public/consent/ui.mjs'
import { harness } from './consent-core.test.mjs'

export function fakeDocument() {
  const doc = { activeElement: null, documentElement: { lang: 'es' } }
  class Element {
    constructor(tag) { this.tagName = tag; this.children = []; this.listeners = {}; this.attributes = {}; this.hidden = false; this.checked = false; this.isConnected = true; this.ownerDocument = doc }
    append(...items) { this.children.push(...items); for (const x of items) if (typeof x === 'object') x.parentNode = this }
    setAttribute(k, v) { this.attributes[k] = v }
    getAttribute(k) { return this.attributes[k] }
    addEventListener(k, fn) { (this.listeners[k] ??= new Set()).add(fn) }
    removeEventListener(k, fn) { this.listeners[k]?.delete(fn) }
    dispatch(k, event = {}) { for (const fn of this.listeners[k] ?? []) fn({ preventDefault() {}, ...event }) }
    click() { this.dispatch('click') }
    focus() { doc.activeElement = this }
    showModal() { this.open = true }
    close() { this.open = false; this.dispatch('close') }
    remove() { this.isConnected = false; if (this.parentNode) this.parentNode.children = this.parentNode.children.filter(x => x !== this) }
    querySelectorAll() { return descendants(this).filter(x => ['button', 'input', 'a'].includes(x.tagName) && !x.hidden) }
  }
  doc.createElement = tag => new Element(tag)
  doc.body = new Element('body')
  return doc
}
function descendants(node) { return node.children.flatMap(x => typeof x === 'object' ? [x, ...descendants(x)] : []) }
function named(host, text) { return descendants(host).find(x => x.textContent === text) }
test('shared UI offers equally prominent choices, granular save, withdrawal and keyboard focus', () => {
  const h = harness(), doc = fakeDocument(), host = doc.createElement('div'); doc.body.append(host)
  const dispose = mountConsent(host, h.api)
  assert.equal(named(host, 'Aceptar opcionales').className, named(host, 'Rechazar opcionales').className)
  named(host, 'Configurar').focus(); named(host, 'Configurar').click()
  const dialog = descendants(host).find(x => x.tagName === 'dialog')
  assert.equal(dialog.open, true)
  const inputs = descendants(dialog).filter(x => x.tagName === 'input')
  assert.deepEqual(inputs.map(x => x.checked), [false, false, false])
  inputs[1].checked = true
  named(host, 'Guardar selección').click()
  assert.equal(h.api.hasConsent('recovery'), true); assert.equal(h.api.hasConsent('preferences'), false)
  named(host, 'Retirar opcionales').click(); assert.equal(h.api.hasConsent('recovery'), false)
  named(host, 'Configurar consentimiento').focus(); named(host, 'Configurar consentimiento').click()
  const controls = dialog.querySelectorAll(); controls.at(-1).focus()
  let trapped = false; dialog.dispatch('keydown', { key: 'Tab', preventDefault() { trapped = true } })
  assert.equal(trapped, true); assert.equal(doc.activeElement, controls[0])
  dialog.dispatch('keydown', { key: 'Tab', shiftKey: true })
  assert.equal(doc.activeElement, controls.at(-1))
  dialog.dispatch('keydown', { key: 'Escape' }); assert.equal(dialog.open, false)
  assert.equal(doc.activeElement, named(host, 'Configurar consentimiento'))
  dispose(); assert.equal(host.children.length, 0)
})

test('the same UI respects the existing English document locale', () => {
  const h = harness(), doc = fakeDocument(), host = doc.createElement('div')
  doc.documentElement.lang = 'en-GB'
  const off = mountConsent(host, h.api)
  named(host, 'Reject optional').click()
  assert.equal(h.api.getState().action, 'reject')
  assert.equal(h.api.hasConsent('recovery'), false)
  off()
})
test('closing never accepts, persistence failure leaves notice/error visible, mount is idempotent', () => {
  const h = harness(), doc = fakeDocument(), host = doc.createElement('div')
  const off = mountConsent(host, h.api), again = mountConsent(host, h.api)
  assert.equal(off, again)
  named(host, 'Configurar').click(); named(host, 'Cerrar').click(); assert.equal(h.api.getState(), null)
  h.deny(true); named(host, 'Aceptar opcionales').click()
  assert.equal(h.api.getState(), null)
  const error = descendants(host).find(x => x.getAttribute('role') === 'alert')
  assert.equal(error.hidden, false); off()
})

test('D3: notice decisions stay outside the scrollable explanation in both locales', () => {
  for (const lang of ['es', 'en']) {
    const h = harness(), doc = fakeDocument(), host = doc.createElement('div')
    doc.documentElement.lang = lang
    const off = mountConsent(host, h.api)
    const notice = descendants(host).find(x => x.className === 'airis-consent-notice')
    const content = notice.children.find(x => x.className === 'airis-consent-content')
    assert.ok(content, 'the explanation needs its own scroll region')
    const actions = notice.children.find(x => x.className === 'airis-consent-actions')
    assert.equal(actions.children.length, 3)
    assert.ok(!descendants(content).includes(actions), 'decisions must never scroll with the explanation')
    const text = descendants(content).map(x => x.textContent ?? '').join(' ')
    assert.match(text, lang === 'es' ? /borradores/ : /drafts/)
    assert.match(text, /Pusher/)
    assert.match(text, lang === 'es' ? /avisos/ : /notices/)
    assert.match(text, lang === 'es' ? /rechazar/ : /reject/)
    const dialog = descendants(host).find(x => x.tagName === 'dialog')
    assert.ok(descendants(dialog).some(x => /180/.test(x.textContent ?? '')))
    off()
  }
})

const css = readFileSync(new URL('../public/consent/consent.css', import.meta.url), 'utf8')
function rulesFor(selector) {
  return [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)]
    .filter(([, selectors]) => selectors.split(',').some(s => s.trim() === selector))
    .map(([, , declarations]) => declarations).join('\n')
}
test('D3: constrained notice reserves space for equally sized decisions, only content scrolls', () => {
  const notice = rulesFor('.airis-consent-notice')
  assert.match(notice, /display:\s*flex/)
  assert.match(notice, /flex-direction:\s*column/)
  assert.match(notice, /overflow:\s*hidden/)
  assert.match(notice, /box-sizing:\s*border-box/)
  assert.match(notice, /max-height:\s*calc\(100dvh - 1rem\)/)
  const content = rulesFor('.airis-consent-content')
  assert.match(content, /overflow:\s*auto/)
  assert.match(content, /min-height:\s*0/)
  assert.match(rulesFor('.airis-consent-notice > .airis-consent-actions'), /flex-shrink:\s*0/)
  assert.match(rulesFor('.airis-consent-actions > button'), /flex:\s*1 1 0/)
})
test('D4: persistent controls stay in document flow, including mobile overrides', () => {
  const controls = rulesFor('.airis-consent-controls')
  assert.match(controls, /position:\s*static/)
  assert.doesNotMatch(controls, /position:\s*(fixed|absolute|sticky)|z-index:|bottom:|right:/)
})

for (const lang of ['es', 'en']) {
  for (const [operation, purpose, key] of [
    ['read', 'recovery', 'p9_drafts_1'], ['write', 'recovery', 'p9_drafts_1'],
    ['read', 'preferences', 'wizard_expectations_dismissed'], ['write', 'preferences', 'wizard_expectations_dismissed'],
    ['consent-read', 'recovery', 'p9_drafts_1'],
  ]) {
    for (const mounted of [true, false]) {
      test(`R2: real engine ${purpose} ${operation} failure, ${lang}, UI ${mounted ? 'mounted' : 'mounted after failure'}`, () => {
        const h = harness({ other_app: 'keep' }), doc = fakeDocument(), host = doc.createElement('div')
        doc.documentElement.lang = lang
        h.api.setChoice({ preferences: true, recovery: true, realtime: true }, 'accept')
        let off = mounted ? mountConsent(host, h.api) : null
        const storage = h.env.storage(), originalGet = storage.getItem, originalSet = storage.setItem
        if (operation === 'write') storage.setItem = () => { throw Error('raw synthetic exception') }
        else storage.getItem = storageKey => {
          if (storageKey === (operation === 'read' ? key : 'airis-consent-v1')) throw Error('raw synthetic exception')
          return originalGet(storageKey)
        }
        if (operation === 'write') assert.equal(h.api.write(purpose, key, 'private draft'), false)
        else assert.equal(h.api.read(purpose, key), null)
        if (!mounted) off = mountConsent(host, h.api)
        const alerts = () => descendants(host).filter(x => x.getAttribute('role') === 'alert')
        const assertFailure = () => {
          assert.equal(h.api.getState(), null)
          assert.ok(alerts().every(x => !x.hidden), 'both alerts expose the engine failure')
          assert.equal(descendants(host).find(x => x.className === 'airis-consent-notice').hidden, false)
          for (const alert of alerts()) {
            assert.match(alert.textContent, /local/i)
            assert.match(alert.textContent, lang === 'es' ? /memoria/ : /memory/)
            assert.doesNotMatch(alert.textContent, /raw synthetic exception|private draft/)
          }
        }
        assertFailure()
        named(host, lang === 'es' ? 'Configurar' : 'Configure').click()
        named(host, lang === 'es' ? 'Cerrar' : 'Close').click(); assertFailure()
        off(); off = mountConsent(host, h.api); assertFailure()
        h.api.setChoice({ recovery: true }, 'save'); assertFailure()
        storage.getItem = originalGet; storage.setItem = originalSet
        const nextPage = createConsent(h.env)
        assert.equal(nextPage.hasConsent(purpose), false); nextPage.destroy()
        h.event('focus'); assertFailure() // successful passive access never resurrects the old choice
        named(host, lang === 'es' ? 'Rechazar opcionales' : 'Reject optional').click()
        assert.ok(alerts().every(x => x.hidden)); assert.equal(h.api.hasConsent('recovery'), false)
        assert.equal(h.data.get('other_app'), 'keep'); off()
      })
    }
  }
}

test('R2: local failure alerts are outside both scrollable explanations', () => {
  const h = harness(), doc = fakeDocument(), host = doc.createElement('div')
  const off = mountConsent(host, h.api)
  for (const container of descendants(host).filter(x => ['airis-consent-notice', 'airis-consent-dialog'].includes(x.className))) {
    assert.ok(container.children.some(x => x.getAttribute('role') === 'alert'), 'alert must remain outside scrolling content')
  }
  off()
})


test('R2: failed dialog submission stays open and error survives close/reopen until valid recovery', () => {
  const h = harness(), doc = fakeDocument(), host = doc.createElement('div')
  const off = mountConsent(host, h.api)
  named(host, 'Configurar').click()
  const dialog = descendants(host).find(x => x.tagName === 'dialog')
  const alerts = descendants(host).filter(x => x.getAttribute('role') === 'alert')
  h.deny(true)
  named(host, 'Guardar selección').click()
  assert.equal(dialog.open, true); assert.equal(h.api.getState(), null)
  assert.ok(alerts.every(x => !x.hidden))
  named(host, 'Cerrar').click(); named(host, 'Configurar').click()
  assert.ok(alerts.every(x => !x.hidden)); assert.equal(h.api.hasConsent('preferences'), false)
  h.deny(false)
  named(host, 'Guardar selección').click()
  assert.equal(dialog.open, false); assert.ok(alerts.every(x => x.hidden))
  off()
})

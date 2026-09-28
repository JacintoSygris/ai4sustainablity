import test from 'node:test'
import assert from 'node:assert/strict'
import { mountSecurityCheck } from '../public/consent/security.mjs'
import { fakeDocument } from './consent-ui.test.mjs'

test('security widget requires available registration, configuration and explicit click', async () => {
  for (const enabled of [false, true]) {
    const document = fakeDocument(), host = document.createElement('div')
    document.head = document.createElement('head'); let renders = 0, removed = 0
    const win = { document, turnstile: { render() { renders++; return 'widget' }, remove() { removed++ } } }
    const off = mountSecurityCheck(host, { enabled, siteKey: 'test-key', action: 'register' }, win)
    assert.equal(document.head.children.length, 0); assert.equal(renders, 0)
    if (enabled) { host.children.find(x => x.tagName === 'button').click(); await Promise.resolve(); await Promise.resolve(); assert.equal(renders, 1) }
    else assert.equal(host.children.length, 0)
    off(); assert.equal(removed, enabled ? 1 : 0)
  }
})
test('late script completion after unmount cannot render a widget', async () => {
  const document = fakeDocument(), host = document.createElement('div'); document.head = document.createElement('head')
  let renders = 0; const win = { document }
  const off = mountSecurityCheck(host, { enabled: true, siteKey: 'test-key', action: 'register' }, win)
  host.children.find(x => x.tagName === 'button').click()
  assert.equal(document.head.children.length, 1)
  const script = document.head.children[0]; off()
  win.turnstile = { render() { renders++ } }; script.onload(); await Promise.resolve(); await Promise.resolve()
  assert.equal(renders, 0)
})

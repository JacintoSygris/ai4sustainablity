import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import { connectRealtime } from '../public/consent/realtime.mjs'
import { harness } from './consent-core.test.mjs'

test('actual bootstrap and app subscribe after opt-in, reject guests and stop before SDK purge on tab change/expiry', () => {
  for (const mode of ['withdraw', 'expiry', 'guest', 'no-key', 'irrelevant']) {
    const h = harness(), events = new Map(), callbacks = [], order = [], stores = {}
    let created = 0, listens = 0
    const window = {
      App: { userId: mode === 'guest' ? null : 7, characterization: mode === 'irrelevant' ? null : { status: 'draft' } },
      addEventListener(name, fn) { events.set(name, fn) }, dispatchEvent(event) { events.get(event.type)?.() },
    }
    class Echo {
      constructor() { assert.equal(h.api.hasConsent('realtime'), true); created++; h.data.set('pusherTransportTLS', 'sdk') }
      private(name) { assert.equal(name, 'characterizations.7'); return { listen(event, callback) { listens++; callbacks.push(callback) } } }
      leave() { order.push('leave') }
      disconnect() { order.push('disconnect'); assert.equal(h.data.get('pusherTransportTLS'), 'sdk') }
    }
    const Alpine = { store(name, value) { if (value) stores[name] = value; return stores[name] }, data() {}, start() {} }
    const context = vm.createContext({ window, document: { head: { querySelector: () => ({ getAttribute: () => 'csrf' }) } },
      Event, Echo, Pusher: class {}, Alpine, axios: { defaults: { headers: { common: {} } } }, getBrowserConsent: () => h.api, connectRealtime,
      buildEnv: { VITE_PUSHER_APP_KEY: mode === 'no-key' ? '' : 'synthetic-key' }, setTimeout, clearTimeout,
    })
    const bootstrap = readFileSync(new URL('../../web/resources/js/bootstrap.js', import.meta.url), 'utf8')
      .replace(/^import .*;\n/gm, '').replaceAll('import.meta.env', 'buildEnv').replace(/if \(import.meta.hot\).*;/, '')
    const app = readFileSync(new URL('../../web/resources/js/app.js', import.meta.url), 'utf8').replace(/^import .*;\n/gm, '')
    vm.runInContext(bootstrap, context); vm.runInContext(app, context)
    assert.equal(created, 0); assert.equal(h.data.has('pusherTransportTLS'), false)
    h.api.setChoice({ preferences: false, recovery: false, realtime: true })
    const eligible = ['withdraw', 'expiry'].includes(mode)
    assert.equal(created, eligible ? 1 : 0); assert.equal(listens, created)
    if (!eligible) continue
    callbacks[0]({ status: 'completed' }); assert.equal(stores.characterization.status, 'completed')
    if (mode === 'expiry') h.advance(180 * 86400000)
    else { h.data.delete('airis-consent-v1'); h.event('storage') }
    assert.equal(order[0], 'disconnect'); assert.equal(h.data.has('pusherTransportTLS'), false)
    callbacks[0]({ status: 'stale' }); assert.equal(stores.characterization.status, 'completed')
    h.api.setChoice({ preferences: false, recovery: false, realtime: true }); assert.equal(created, 2); assert.equal(listens, 2)
    h.api.reset()
  }
})

import test from 'node:test'
import assert from 'node:assert/strict'
import { connectRealtime } from '../public/consent/realtime.mjs'
import { harness } from './consent-core.test.mjs'

test('no transport/cache before opt-in or for guests; accept connects and withdrawal stops before purge', () => {
  for (const available of [false, true]) {
    const h = harness(); let created = 0, stopped = 0, subscribed = 0
    const dispose = connectRealtime({ consent: h.api, available: () => available,
      create() { created++; h.data.set('pusherTransportTLS', 'sdk'); return { disconnect() { assert.equal(h.data.get('pusherTransportTLS'), 'sdk'); stopped++ } } },
      changed(connection) { if (connection) subscribed++ },
    })
    assert.equal(created, 0); assert.equal(h.data.has('pusherTransportTLS'), false)
    h.api.setChoice({ preferences: false, recovery: false, realtime: true })
    assert.equal(created, available ? 1 : 0); assert.equal(subscribed, created)
    h.api.reset(); assert.equal(stopped, created); assert.equal(h.data.has('pusherTransportTLS'), false)
    dispose()
  }
})

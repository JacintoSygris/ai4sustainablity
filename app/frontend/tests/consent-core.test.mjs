import test from 'node:test'
import assert from 'node:assert/strict'
import { createConsent, CONSENT_KEY, TTL, POLICY_VERSION } from '../public/consent/core.mjs'

export function harness(seed = {}) {
  let time = 1800000000000
  const data = new Map(Object.entries(seed)), log = [], events = new Map(), timers = new Map()
  let next = 0, denied = false, failRemove = new Set()
  const storage = {
    get length() { return data.size }, key(i) { return [...data.keys()][i] ?? null },
    getItem(k) { log.push(['get', k]); if (denied) throw Error('denied'); return data.get(k) ?? null },
    setItem(k, v) { log.push(['set', k]); if (denied) throw Error('quota'); data.set(k, v) },
    removeItem(k) { log.push(['remove', k]); if (denied || failRemove.has(k)) throw Error('denied'); data.delete(k) },
  }
  const env = { now: () => time, storage: () => storage,
    setTimeout(fn, delay) { const id = ++next; timers.set(id, { fn, due: time + delay }); return id },
    clearTimeout(id) { timers.delete(id) },
    listen(name, fn) { if (!events.has(name)) events.set(name, new Set()); events.get(name).add(fn); return () => events.get(name).delete(fn) },
    clearSidebar() { log.push(['cookie', 'sidebar_state']) },
  }
  const api = createConsent(env)
  return { api, data, log, env, deny(v) { denied = v }, failRemovalOf(keys) { failRemove = new Set(keys === null ? [] : Array.isArray(keys) ? keys : [keys]) },
    event(name, extra = {}) { events.get(name)?.forEach(fn => fn({ key: CONSENT_KEY, ...extra })) },
    advance(ms) { time += ms; for (const [id, t] of [...timers]) if (t.due <= time) { timers.delete(id); t.fn() } },
    record(overrides = {}) { return { schema: 1, policy: POLICY_VERSION, purposes: { preferences: true, recovery: true, realtime: true }, chosenAt: time, expiresAt: time + TTL, action: 'accept', ...overrides } },
  }
}
const all = { preferences: true, recovery: true, realtime: true }
const none = { preferences: false, recovery: false, realtime: false }

test('unset/legacy/malformed/future/expiry/version/keysets and strict booleans fail closed', () => {
  const h = harness()
  for (const value of ['necessary-only', '{', 'null', JSON.stringify(h.record({ chosenAt: 1800000000001 })),
    JSON.stringify(h.record({ expiresAt: 1800000000000 + TTL + 1 })),
    JSON.stringify(h.record({ policy: 'future' })), JSON.stringify(h.record({ extra: true })),
    JSON.stringify(h.record({ schema: 2 })), JSON.stringify(h.record({ chosenAt: '1800000000000' })),
    JSON.stringify(h.record({ chosenAt: 0 })), JSON.stringify(h.record({ expiresAt: 1800000000000 })),
    JSON.stringify(h.record({ expiresAt: 1800000000000 + TTL - 1 })),
    JSON.stringify(h.record({ purposes: { ...all, recovery: 'true' } }))]) {
    h.data.set(CONSENT_KEY, value); h.event('storage'); assert.equal(h.api.hasConsent('recovery'), false)
  }
  assert.equal(h.api.setChoice({ ...all, recovery: 'true' }, 'save'), false)
  assert.equal(h.api.setChoice({ ...all, surprise: true }, 'save'), false)
  assert.equal(h.api.setChoice(all, 'scroll'), false)
})

test('navigation shares the persisted grant and queued cross-tab revocation invalidates old callbacks', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  const nextPage = createConsent(h.env)
  assert.deepEqual(nextPage.getState(), h.api.getState())
  const stale = nextPage.lease('recovery')
  h.event('storage', { newValue: JSON.stringify(h.record({ purposes: none, action: 'withdraw' })) })
  assert.equal(stale.write('p9_drafts_1', 'late'), false)
  assert.equal(nextPage.hasConsent('recovery'), true)
  assert.equal(nextPage.write('recovery', 'other_app', 'no'), false)
  assert.equal(h.data.has('other_app'), false)
  nextPage.destroy()
})

test('two failed subscribers do not prevent others from observing revoke', () => {
  const h = harness(); h.api.setChoice(all, 'accept'); let observed = 0
  h.api.subscribe(() => { throw Error('broken observer') })
  h.api.subscribe(state => { if (!state) observed++ })
  h.api.reset(); assert.equal(observed, 1)
})

test('quota failure removes a previously valid grant so another page cannot revive it', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  h.env.storage().setItem = () => { throw Error('quota') }
  assert.equal(h.api.setChoice(none, 'withdraw'), false)
  assert.equal(h.data.has(CONSENT_KEY), false)
  const nextPage = createConsent(h.env)
  assert.equal(nextPage.hasConsent('recovery'), false)
  nextPage.destroy()
})
test('failed selective draft removal fails withdrawal and cannot revive the old draft on re-consent', () => {
  const h = harness(); assert.equal(h.api.setChoice(all, 'accept'), true)
  assert.equal(h.api.write('recovery', 'p9_drafts_17', 'private old draft'), true)
  h.failRemovalOf('p9_drafts_17')
  assert.equal(h.api.setChoice(none, 'withdraw'), false)
  assert.equal(h.api.getStorageError(), true)
  assert.equal(h.api.hasConsent('recovery'), false)
  assert.equal(h.data.get('p9_drafts_17'), 'private old draft')
  assert.equal(h.api.setChoice(all, 'accept'), false)
  assert.equal(h.api.read('recovery', 'p9_drafts_17'), null)
  h.failRemovalOf(null)
  assert.equal(h.api.setChoice(all, 'accept'), true)
  assert.equal(h.api.read('recovery', 'p9_drafts_17'), null)
  assert.equal(h.data.has('p9_drafts_17'), false)
})
test('failed reset keeps the error visible and refuses to reuse a private draft on a later page', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  h.api.write('recovery', 'p9_drafts_17', 'old')
  h.failRemovalOf('p9_drafts_17')
  assert.equal(h.api.reset(), false)
  assert.equal(h.api.getStorageError(), true)
  assert.equal(h.data.has(CONSENT_KEY), false)
  const nextPage = createConsent(h.env)
  assert.equal(nextPage.read('recovery', 'p9_drafts_17'), null)
  assert.equal(nextPage.setChoice(all, 'accept'), false)
  h.failRemovalOf(null)
  assert.equal(nextPage.setChoice(all, 'accept'), true)
  assert.equal(nextPage.read('recovery', 'p9_drafts_17'), null)
  nextPage.destroy()
})
test('a failed consent-record removal cannot revive an undeleted draft on a new page when writes work', () => {
  const h = harness(); h.api.setChoice(all, 'accept'); h.api.write('recovery', 'p9_drafts_17', 'old private draft')
  h.failRemovalOf([CONSENT_KEY, 'p9_drafts_17'])
  assert.equal(h.api.reset(), false)
  const laterPage = createConsent(h.env)
  assert.equal(laterPage.hasConsent('recovery'), false)
  assert.equal(laterPage.read('recovery', 'p9_drafts_17'), null)
  assert.equal(h.data.get('p9_drafts_17'), 'old private draft')
  laterPage.destroy()
})
test('failed consumer removal erases private bytes by overwrite and reports storage error', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  h.api.write('recovery', 'p9_drafts_17', 'old')
  h.failRemovalOf('p9_drafts_17')
  assert.equal(h.api.remove('recovery', 'p9_drafts_17'), false)
  assert.equal(h.api.getStorageError(), true)
  assert.equal(h.api.read('recovery', 'p9_drafts_17'), '')
  assert.equal(h.api.hasConsent('recovery'), true)
  assert.equal(h.data.has(CONSENT_KEY), true)
})
test('a failed single-key consumer removal preserves unrelated authorized drafts and preferences', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  h.api.write('recovery', 'p9_drafts_17', 'old private draft')
  h.api.write('recovery', 'p8_guided_draft_1', 'still authorized')
  h.api.write('preferences', 'wizard_expectations_dismissed', 'true')
  h.failRemovalOf('p9_drafts_17')
  assert.equal(h.api.remove('recovery', 'p9_drafts_17'), false)
  assert.equal(h.api.getStorageError(), true)
  assert.equal(h.data.get('p9_drafts_17'), '')
  assert.equal(h.api.read('recovery', 'p8_guided_draft_1'), 'still authorized')
  assert.equal(h.api.read('preferences', 'wizard_expectations_dismissed'), 'true')
})
test('failed pending cleanup neither recurses through realtime subscribers nor prevents persisting withdrawal', () => {
  const h = harness(); h.api.setChoice(all, 'accept'); h.data.set('pusherTransportTLS', 'old')
  h.failRemovalOf('pusherTransportTLS')
  let calls = 0
  h.api.subscribe(() => { calls++; if (calls < 8) h.api.hasConsent('realtime') })
  assert.equal(h.api.setChoice({ ...all, realtime: false }, 'save'), false)
  assert.ok(calls <= 2, `subscriber reentered ${calls} times`)
  assert.equal(h.api.setChoice(none, 'withdraw'), false)
  const laterPage = createConsent(h.env)
  assert.equal(laterPage.hasConsent('realtime'), false)
  assert.equal(laterPage.hasConsent('recovery'), false)
  laterPage.destroy()
})
test('a stale tab cannot erase a newly regranted draft or other purpose on accepting again', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  const tab = createConsent(h.env)
  h.api.setChoice(none, 'withdraw')
  tab.setChoice(all, 'accept')
  tab.write('recovery', 'p9_drafts_17', 'new draft')
  h.api.setChoice(all, 'accept')
  assert.equal(h.data.get('p9_drafts_17'), 'new draft')
  tab.destroy()
})
test('a stale pending purge cannot erase a new draft after another tab has completed regrant', () => {
  const h = harness(); h.api.setChoice(all, 'accept'); h.api.write('recovery', 'p9_drafts_17', 'old draft')
  h.failRemovalOf('p9_drafts_17')
  assert.equal(h.api.setChoice(none, 'withdraw'), false)
  h.failRemovalOf(null)
  const tab = createConsent(h.env)
  assert.equal(tab.setChoice(all, 'accept'), true)
  assert.equal(tab.write('recovery', 'p9_drafts_17', 'new draft'), true)
  assert.equal(h.api.hasConsent('recovery'), true)
  assert.equal(h.data.get('p9_drafts_17'), 'new draft')
  tab.destroy()
})
test('a delayed withdrawal event retires the old lease but keeps a fresh draft under the current grant', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  const stale = h.api.lease('recovery')
  const tab = createConsent(h.env)
  h.api.setChoice(none, 'withdraw')
  tab.setChoice(all, 'accept'); tab.write('recovery', 'p9_drafts_17', 'new draft')
  h.event('storage', { newValue: JSON.stringify(h.record({ purposes: none, action: 'withdraw' })) })
  assert.equal(stale.write('p9_drafts_17', 'stale'), false)
  assert.equal(h.data.get('p9_drafts_17'), 'new draft')
  tab.destroy()
})
test('a missed withdrawal and regrant in another tab never revives the old lease, even within one clock tick', () => {
  const h = harness(); assert.equal(h.api.setChoice(all, 'accept'), true)
  const oldLease = h.api.lease('recovery')
  const tab = createConsent(h.env)
  assert.equal(tab.setChoice(none, 'withdraw'), true)
  assert.equal(tab.setChoice(all, 'accept'), true)
  assert.equal(tab.write('recovery', 'p9_drafts_17', 'new draft'), true)
  // Tab A missed every storage event while suspended.
  assert.equal(oldLease.remove('p9_drafts_17'), false)
  assert.equal(h.data.get('p9_drafts_17'), 'new draft')
  assert.equal(h.api.lease('recovery').read('p9_drafts_17'), 'new draft')
  tab.destroy()
})
test('unavailable revision generation cannot leave an old grant active after requested withdrawal', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  h.env.newRevision = () => 'invalid'
  assert.equal(h.api.setChoice(none, 'withdraw'), false)
  const tab = createConsent(h.env)
  assert.equal(tab.hasConsent('recovery'), false)
  tab.destroy()
})
test('failed consent-key removal still writes a denied legacy record when revision generation fails', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  h.failRemovalOf(CONSENT_KEY)
  h.env.newRevision = () => 'invalid'
  assert.equal(h.api.reset(), false)
  const tab = createConsent(h.env)
  assert.equal(tab.hasConsent('recovery'), false)
  tab.destroy()
})
test('a throwing revision source still permits a denied record when consent-key deletion is refused', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  h.failRemovalOf(CONSENT_KEY)
  h.env.newRevision = () => { throw Error('revision unavailable') }
  assert.equal(h.api.reset(), false)
  const tab = createConsent(h.env)
  assert.equal(tab.hasConsent('recovery'), false)
  tab.destroy()
})
test('an external consent edit conservatively retires old leases without deleting current authorized data', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  const oldLease = h.api.lease('recovery')
  const tab = createConsent(h.env)
  assert.equal(tab.setChoice({ ...all, preferences: false }, 'save'), true)
  assert.equal(tab.write('recovery', 'p9_drafts_17', 'new draft'), true)
  assert.equal(oldLease.remove('p9_drafts_17'), false)
  assert.equal(h.data.get('p9_drafts_17'), 'new draft')
  assert.equal(h.api.lease('recovery').read('p9_drafts_17'), 'new draft')
  tab.destroy()
})
test('partial failed revocation does not discard unrelated authorized drafts', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  h.api.write('recovery', 'p9_drafts_1', 'still authorized')
  h.api.write('preferences', 'sidebar_state', 'before')
  h.data.set('pusherTransportTLS', 'old cache')
  h.failRemovalOf('pusherTransportTLS')
  assert.equal(h.api.setChoice({ ...all, realtime: false }, 'save'), false)
  assert.equal(h.api.getStorageError(), true)
  assert.equal(h.api.read('recovery', 'p9_drafts_1'), 'still authorized')
  assert.equal(h.data.get('p9_drafts_1'), 'still authorized')
  assert.equal(h.api.hasConsent('realtime'), false)
  h.failRemovalOf(null)
  assert.equal(h.api.setChoice(all, 'accept'), true)
  assert.equal(h.data.has('pusherTransportTLS'), false)
  assert.equal(h.api.read('recovery', 'p9_drafts_1'), 'still authorized')
})
test('choice uses one timestamp, fixed TTL, granular purposes and immutable snapshots', () => {
  const h = harness(); assert.equal(h.api.setChoice({ ...none, recovery: true }, 'save'), true)
  const s = h.api.getState(); assert.equal(s.expiresAt - s.chosenAt, TTL)
  h.advance(1000); assert.equal(h.api.getState().expiresAt, s.expiresAt)
  assert.equal(h.api.hasConsent('preferences'), false); assert.equal(h.api.hasConsent('recovery'), true)
  assert.throws(() => { s.purposes.recovery = false })
})
test('no optional reads, exact-family purge, queued writes denied, reaccept starts fresh', () => {
  const h = harness({ unrelated: 'keep', p8_guided_draft_1: 'old', p8_guided_draft_1_conflict: 'old', p9_drafts_2: 'old', p9_drafts_other_app: 'keep', pusherTransportTLS: 'old' })
  assert.equal(h.api.read('recovery', 'p8_guided_draft_1'), null)
  assert.equal(h.log.some(([op, k]) => op === 'get' && k === 'p8_guided_draft_1'), false)
  h.api.setChoice(all, 'accept'); const queued = h.api.lease('recovery')
  assert.equal(queued.write('p8_guided_draft_1', 'fresh'), true)
  h.api.setChoice(none, 'withdraw'); assert.equal(queued.write('p8_guided_draft_1', 'late'), false)
  assert.equal(h.data.get('unrelated'), 'keep'); assert.equal(h.data.get('p9_drafts_other_app'), 'keep')
  assert.equal(h.data.has('p8_guided_draft_1'), false)
  h.api.setChoice(all, 'accept'); assert.equal(queued.write('p8_guided_draft_1', 'late'), false)
  assert.equal(h.api.read('recovery', 'p8_guided_draft_1'), null)
})
test('other tab revocation and page-open expiry stop consumers before SDK purge', () => {
  for (const mode of ['storage', 'expiry', 'reset']) {
    const h = harness(); h.api.setChoice(all, 'accept'); h.data.set('pusherTransportTLS', 'cache')
    const order = []; h.api.beforeRevoke(() => { order.push(h.data.has('pusherTransportTLS')); throw Error('listener') })
    h.api.beforeRevoke(() => order.push('disconnected'))
    let notified = 0; const unsub = h.api.subscribe(() => notified++)
    if (mode === 'storage') { h.data.set(CONSENT_KEY, JSON.stringify(h.record({ purposes: none, action: 'withdraw' }))); h.event('storage') }
    if (mode === 'expiry') h.advance(TTL)
    if (mode === 'reset') h.api.reset()
    assert.deepEqual(order, [true, 'disconnected']); assert.equal(h.data.has('pusherTransportTLS'), false)
    assert.equal(h.api.hasConsent('realtime'), false); assert.equal(notified, 1); unsub(); h.api.destroy()
  }
})
test('storage failure never activates or retains optional consent; listeners clean up', () => {
  const h = harness(); h.api.setChoice(all, 'accept'); h.deny(true)
  assert.equal(h.api.setChoice(all, 'accept'), false); assert.equal(h.api.getState(), null)
  h.deny(false); assert.equal(h.api.hasConsent('recovery'), false)
  assert.equal(h.api.setChoice(none, 'reject'), true)
  let calls = 0; const off = h.api.subscribe(() => calls++); off(); h.api.reset(); assert.equal(calls, 0)
})

for (const revoked of [['realtime'], ['preferences'], ['recovery'], ['preferences', 'recovery', 'realtime']]) {
  for (const delayed of [false, true]) {
    test(`R1: ${revoked.join('+')} withdrawal ${delayed ? 'queued after reaccept' : 'current'} preserves only currently authorized data`, () => {
      const h = harness({ unrelated: 'keep', p9_drafts_other_app: 'keep' })
      h.api.setChoice(all, 'accept')
      const tab = createConsent(h.env)
      const keys = { preferences: 'wizard_expectations_dismissed', recovery: 'p9_drafts_1', realtime: 'pusherTransportTLS' }
      const leases = Object.fromEntries(Object.keys(all).map(p => [p, tab.lease(p)]))
      for (const p of Object.keys(all)) leases[p].write(keys[p], 'keep')
      let connected = true
      const disconnected = []
      tab.beforeRevoke(ps => { disconnected.push(...ps); if (ps.includes('realtime')) connected = false })
      const incoming = JSON.stringify(h.record({ action: 'save', purposes: Object.fromEntries(Object.keys(all).map(p => [p, !revoked.includes(p)])) }))
      if (!delayed) h.data.set(CONSENT_KEY, incoming)
      h.log.length = 0
      h.event('storage', { newValue: incoming })
      const retiredLeases = delayed ? revoked : Object.keys(all)
      assert.equal(connected, !retiredLeases.includes('realtime'))
      assert.deepEqual([...new Set(disconnected)].sort(), [...retiredLeases].sort())
      assert.equal(h.log.some(([op]) => op === 'cookie'), !delayed && revoked.includes('preferences'))
      for (const p of Object.keys(all)) {
        assert.equal(h.data.get(keys[p]), !delayed && revoked.includes(p) ? undefined : 'keep', `${p} data`)
        assert.equal(leases[p].write(keys[p], 'late'), !retiredLeases.includes(p), `${p} old callback`)
        assert.equal(tab.hasConsent(p), delayed || !revoked.includes(p), `${p} current authority`)
        if (delayed || !revoked.includes(p)) assert.equal(tab.lease(p).read(keys[p]), retiredLeases.includes(p) ? 'keep' : 'late', `${p} new lease`)
      }
      assert.equal(h.data.get('unrelated'), 'keep'); assert.equal(h.data.get('p9_drafts_other_app'), 'keep')
      tab.destroy(); h.api.destroy()
    })
  }
}

test('R1: an obsolete event cannot transiently grant a purpose denied in current storage', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  const observed = []
  h.api.beforeRevoke(() => observed.push(h.api.hasConsent('recovery')))
  h.api.subscribe(() => observed.push(h.api.hasConsent('recovery')))
  h.data.set(CONSENT_KEY, JSON.stringify(h.record({ purposes: none, action: 'withdraw' })))
  h.event('storage', { newValue: JSON.stringify(h.record({ purposes: { ...all, realtime: false }, action: 'save' })) })
  assert.ok(observed.length > 0); assert.ok(observed.every(value => value === false))
  h.event('storage', { newValue: JSON.stringify(h.record()) })
  assert.equal(h.api.getState().purposes.recovery, false)
})

test('R1: changing an unrelated choice or refreshing its timestamp preserves active leases', () => {
  const h = harness(); h.api.setChoice(all, 'accept')
  const recovery = h.api.lease('recovery')
  h.api.setChoice({ ...all, preferences: false }, 'save')
  assert.equal(recovery.write('p9_drafts_1', 'still editable'), true)
  h.advance(1); h.api.setChoice({ ...all, preferences: false }, 'save')
  assert.equal(recovery.write('p9_drafts_1', 'still editable again'), true)
})

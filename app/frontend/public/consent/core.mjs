export const CONSENT_KEY = 'airis-consent-v1'
export const POLICY_VERSION = '2026-09-26.1'
export const TTL = 180 * 24 * 60 * 60 * 1000
export const PURPOSES = Object.freeze(['preferences', 'recovery', 'realtime'])
export const NONE = Object.freeze({ preferences: false, recovery: false, realtime: false })
export const ALL = Object.freeze({ preferences: true, recovery: true, realtime: true })

function exactKeys(value, keys) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    && Object.keys(value).length === keys.length && keys.every(key => Object.hasOwn(value, key))
}
function validPurposes(value) {
  return exactKeys(value, PURPOSES) && PURPOSES.every(key => typeof value[key] === 'boolean')
}
export function validRecord(value, now) {
  const legacy = ['schema', 'policy', 'purposes', 'chosenAt', 'expiresAt', 'action']
  if (!exactKeys(value, legacy) && !exactKeys(value, [...legacy, 'revision'])) return false
  if (Object.hasOwn(value, 'revision')
    && (typeof value.revision !== 'string'
      || !/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value.revision))) return false
  if (value.schema !== 1 || value.policy !== POLICY_VERSION || !validPurposes(value.purposes)) return false
  if (!Number.isSafeInteger(value.chosenAt) || !Number.isSafeInteger(value.expiresAt)
    || value.chosenAt <= 0 || value.chosenAt > now || value.expiresAt <= now
    || value.expiresAt - value.chosenAt !== TTL) return false
  if (!['accept', 'reject', 'save', 'withdraw'].includes(value.action)) return false
  if (value.action === 'accept' && !PURPOSES.every(p => value.purposes[p])) return false
  if (['reject', 'withdraw'].includes(value.action) && PURPOSES.some(p => value.purposes[p])) return false
  return true
}
export function purposeForKey(key) {
  if (typeof key !== 'string') return null
  if (['wizard_expectations_dismissed', 'p9_intro_dismissed', 'sidebar_state'].includes(key)) return 'preferences'
  if (/^p8_guided_draft_(?:\d+|unknown)(?:_conflict)?$/.test(key)
    || /^p9_drafts_(?:\d+|unknown)$/.test(key)) return 'recovery'
  if (['pusherTransportTLS', 'pusherTransportNonTLS'].includes(key)) return 'realtime'
  return null
}

// All browser I/O and time are injected. No global Storage/fetch interception.
export function createConsent(env) {
  let state = null, signature, timer, blocked = false, destroyed = false, transitioning = false, storageError = false
  const listeners = new Set(), revokers = new Set(), openers = new Set()
  let revoking = []
  const pendingPurge = new Set()
  const generations = { preferences: 0, recovery: 0, realtime: 0 }
  const newRevision = () => env.newRevision?.() ?? globalThis.crypto?.randomUUID?.()
  const safely = (set, ...args) => { for (const fn of [...set]) { try { fn(...args) } catch { /* isolate subscribers */ } } }
  const subscribeTo = (set, fn) => { set.add(fn); return () => set.delete(fn) }

  function purge(disallowed) {
    const targets = [...new Set([...disallowed, ...pendingPurge])]
    if (!targets.length) return true
    const failures = new Set()
    try {
      const storage = env.storage()
      const keys = Array.from({ length: storage.length }, (_, i) => storage.key(i))
      for (const key of keys) {
        const purpose = purposeForKey(key)
        if (targets.includes(purpose)) {
          try { storage.removeItem(key) } catch { failures.add(purpose) }
        }
      }
    } catch { targets.forEach(p => failures.add(p)) }
    if (targets.includes('preferences')) { try { env.clearSidebar() } catch { failures.add('preferences') } }
    for (const purpose of targets) {
      if (failures.has(purpose)) pendingPurge.add(purpose)
      else pendingPurge.delete(purpose)
    }
    if (pendingPurge.size) storageError = true
    return pendingPurge.size === 0
  }
  function clearGrant() {
    try {
      const storage = env.storage()
      storage.removeItem(CONSENT_KEY)
      if (storage.getItem(CONSENT_KEY) === null) return true
    } catch { /* a selective removal denial may still permit writing */ }
    try {
      const timestamp = env.now()
      let revision
      try { revision = newRevision() } catch { /* denial needs no random source */ }
      const value = { schema: 1, policy: POLICY_VERSION, purposes: NONE,
        chosenAt: timestamp, expiresAt: timestamp + TTL, action: 'withdraw', revision }
      // Denial remains representable by the compatible six-field format if
      // secure revision generation is unavailable; never fall back for grants.
      if (!validRecord(value, timestamp)) delete value.revision
      if (!validRecord(value, timestamp)) return false
      const denied = JSON.stringify(value)
      const storage = env.storage()
      storage.setItem(CONSENT_KEY, denied)
      return storage.getItem(CONSENT_KEY) === denied
    } catch { return false }
  }
  function schedule() {
    env.clearTimeout(timer)
    if (state && !destroyed) timer = env.setTimeout(refresh, Math.min(state.expiresAt - env.now(), 2147483647))
  }
  function transition(next, retired = [], external = false) {
    const nextSignature = JSON.stringify(next)
    if (nextSignature === signature && !retired.length) {
      if (!pendingPurge.size) return true
      transitioning = true
      const cleared = purge([])
      transitioning = false
      if (cleared && !blocked && storageError) {
        storageError = false
        safely(listeners, state)
      }
      return cleared
    }
    transitioning = true
    // A suspended tab may miss withdraw→regrant entirely. Every external record
    // replacement retires old leases; only currently denied data is purged.
    const changedOutside = external && nextSignature !== signature
    const revoked = PURPOSES.filter(p => retired.includes(p) || (state?.purposes[p]
      && (!next?.purposes[p] || changedOutside)))
    for (const p of PURPOSES) {
      if (revoked.includes(p) || Boolean(state?.purposes[p]) !== Boolean(next?.purposes[p])) generations[p]++
    }
    revoking = revoked
    // Make writes fail before notifying synchronous disconnect hooks.
    state = next ? Object.freeze({ ...next, purposes: Object.freeze({ ...next.purposes }) }) : null
    signature = nextSignature
    if (revoked.length) safely(revokers, revoked)
    // A delayed revoke retires old leases, not fresh data under a newer grant.
    const cleared = purge(PURPOSES.filter(p => !state?.purposes[p]))
    revoking = []
    transitioning = false
    if (!blocked) storageError = !cleared
    schedule()
    safely(listeners, state)
    return cleared
  }
  function failStorage() {
    blocked = true
    storageError = true
    // A failed local operation must not leave an old persisted grant to revive.
    clearGrant()
    const alreadyOff = state === null
    transition(null)
    purge(PURPOSES)
    if (alreadyOff) safely(listeners, state)
  }
  function refresh(retired = []) {
    if (destroyed || transitioning) return state
    let next = null
    if (!blocked) {
      try {
        const raw = env.storage().getItem(CONSENT_KEY)
        let value = null
        try { value = raw === null ? null : JSON.parse(raw) } catch { /* malformed record, no grant */ }
        if (validRecord(value, env.now())) next = value
        else if (raw !== null) env.storage().removeItem(CONSENT_KEY)
      } catch { failStorage(); return state }
    }
    // A different tab's successful new grant already cleaned these old keys.
    // The suspended tab must not replay its prior cleanup on new drafts.
    for (const purpose of pendingPurge) {
      if (next?.purposes[purpose] && !state?.purposes[purpose]) pendingPurge.delete(purpose)
    }
    transition(next, retired, true)
    // A long TTL needs several bounded timers, even if state has not changed.
    schedule()
    return state
  }
  function hasConsent(purpose) { return !destroyed && PURPOSES.includes(purpose) && refresh()?.purposes[purpose] === true && !revoking.includes(purpose) && !pendingPurge.has(purpose) }
  function setChoice(purposes, action = 'save') {
    const timestamp = env.now()
    if (destroyed || !validPurposes(purposes) || !['accept', 'reject', 'save', 'withdraw'].includes(action)) return false
    try {
      const value = { schema: 1, policy: POLICY_VERSION, purposes, chosenAt: timestamp,
        expiresAt: timestamp + TTL, action, revision: newRevision() }
      if (!validRecord(value, timestamp)) { failStorage(); return false }
      refresh()
      // A previous failed removal must not become readable after a new grant.
      const newlyGranted = PURPOSES.filter(p => purposes[p] && !state?.purposes[p])
      if (newlyGranted.length && !purge(newlyGranted)) {
        safely(listeners, state)
        return false
      }
      const storage = env.storage(), encoded = JSON.stringify(value)
      storage.setItem(CONSENT_KEY, encoded)
      if (storage.getItem(CONSENT_KEY) !== encoded) throw Error('Persistence unavailable')
      blocked = false
      storageError = false
      return transition(value)
    } catch {
      failStorage()
      return false
    }
  }
  function reset() {
    const hadError = storageError
    blocked = true
    const cleared = transition(null)
    try {
      env.storage().removeItem(CONSENT_KEY)
      if (env.storage().getItem(CONSENT_KEY) !== null) throw Error('Persistence unavailable')
      blocked = false
      const purged = purge(PURPOSES)
      storageError = !(cleared && purged)
      if (hadError) safely(listeners, state)
      return !storageError
    } catch { failStorage(); return false }
  }
  function allowed(purpose, key) { return purposeForKey(key) === purpose && hasConsent(purpose) }
  function read(purpose, key) {
    if (!allowed(purpose, key)) return null
    try { return env.storage().getItem(key) } catch { failStorage(); return null }
  }
  function write(purpose, key, value) {
    if (typeof value !== 'string' || !allowed(purpose, key)) return false
    try { env.storage().setItem(key, value); return true } catch { failStorage(); return false }
  }
  function remove(purpose, key) {
    if (purposeForKey(key) !== purpose) return false
    try { env.storage().removeItem(key); return true } catch {
      try {
        // Overwrite a failed removal without erasing other authorized drafts.
        const storage = env.storage()
        storage.setItem(key, '')
        if (storage.getItem(key) !== '') throw Error('Persistence unavailable')
        storageError = true
        safely(listeners, state)
      } catch { failStorage() }
      return false
    }
  }
  function lease(purpose) {
    const enabled = hasConsent(purpose), generation = generations[purpose]
    const current = () => enabled && hasConsent(purpose) && generations[purpose] === generation
    return {
      read: key => current() ? read(purpose, key) : null,
      write: (key, value) => current() ? write(purpose, key, value) : false,
      remove: key => current() ? remove(purpose, key) : false,
    }
  }
  const cleanup = [
    env.listen('storage', event => {
      if (event.key !== CONSENT_KEY && event.key !== null) return
      // Events can retire old work, but only current storage can grant permission.
      let retired = []
      if (event.newValue !== undefined) {
        let incoming = null
        try { incoming = JSON.parse(event.newValue) } catch { /* revoked */ }
        const valid = validRecord(incoming, env.now())
        retired = PURPOSES.filter(p => state?.purposes[p] && (!valid || !incoming.purposes[p]))
      }
      refresh(retired)
    }),
    env.listen('pageshow', () => refresh()), env.listen('focus', () => refresh()), env.listen('visibilitychange', () => refresh()),
  ]
  refresh()
  return {
    getState: () => refresh(), getStorageError: () => storageError, hasConsent, setChoice, reset, read, write, remove, lease,
    subscribe: fn => subscribeTo(listeners, fn), beforeRevoke: fn => subscribeTo(revokers, fn),
    onReopen: fn => subscribeTo(openers, fn), reopen: () => safely(openers),
    destroy() { if (destroyed) return; transition(null); destroyed = true; env.clearTimeout(timer); cleanup.forEach(fn => fn()); listeners.clear(); revokers.clear(); openers.clear() },
  }
}

const singleton = Symbol.for('airis.consent.2026-09-26.1')
export function getBrowserConsent(win = typeof window === 'undefined' ? null : window) {
  if (!win) return null
  if (!win[singleton]) win[singleton] = createConsent({
    now: () => Date.now(), storage: () => win.localStorage,
    setTimeout: (fn, ms) => win.setTimeout(fn, ms), clearTimeout: id => win.clearTimeout(id),
    listen(name, fn) { win.addEventListener(name, fn); return () => win.removeEventListener(name, fn) },
    clearSidebar() { win.document.cookie = 'sidebar_state=; Path=/; Max-Age=0; SameSite=Lax' },
  })
  return win[singleton]
}

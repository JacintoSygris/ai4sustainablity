/** The transport is injected so tests run the real lifecycle without opening sockets. */
export function connectRealtime({ consent, available, create, changed }) {
  let connection = null, stopped = false
  function stop() {
    if (!connection) return
    const previous = connection
    connection = null
    try { previous.disconnect() } finally { changed(null) }
  }
  function sync() {
    if (stopped || !consent.hasConsent('realtime') || !available()) { stop(); return }
    if (!connection) {
      try { connection = create(); changed(connection) } catch { stop() }
    }
  }
  const offRevoke = consent.beforeRevoke(purposes => { if (purposes.includes('realtime')) stop() })
  const off = consent.subscribe(sync)
  sync()
  return () => { stopped = true; off(); offRevoke(); stop() }
}

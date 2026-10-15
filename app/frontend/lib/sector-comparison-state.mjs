/** Independent informational load coordinator. Never accepts or mutates selections. */
export function createSectorComparisonLoader(fetchComparison, publish) {
  let generation = 0
  let controller = null
  return {
    async load(enabled, id, fingerprint) {
      const current = ++generation
      controller?.abort()
      controller = null
      publish({ status: "idle", data: null })
      if (!enabled || !id || !fingerprint) return
      controller = new AbortController()
      publish({ status: "loading", data: null })
      try {
        const response = await fetchComparison(id, controller.signal)
        if (current !== generation) return
        if (response.data.characterization_id !== id || response.data.input_fingerprint !== fingerprint) throw new Error("stale sector input")
        publish({ status: "ready", data: response.data })
      } catch {
        if (current === generation) publish({ status: "error", data: null })
      }
    },
    dispose() {
      generation++
      controller?.abort()
    },
  }
}

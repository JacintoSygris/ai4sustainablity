"use client"
import { useEffect, useState } from "react"
import { createSectorComparisonLoader } from "@/lib/sector-comparison-state.mjs"
import { getLaravelSectorComparison, type LaravelSectorComparison } from "@/lib/laravel-api"

type State = { status: string; data: LaravelSectorComparison | null; key?: string }
export function useSectorComparison(capability: {
  characterization_id: number
  sector_comparison_enabled?: boolean
  sector_comparison_input_fingerprint?: string
} | null) {
  const enabled = capability?.sector_comparison_enabled === true
  const id = capability?.characterization_id
  const fingerprint = capability?.sector_comparison_input_fingerprint
  const key = enabled ? `${id}:${fingerprint}` : "off"
  const [state, setState] = useState<State>({ status: "idle", data: null })
  useEffect(() => {
    const loader = createSectorComparisonLoader(
      (requestId: number, signal: AbortSignal) => getLaravelSectorComparison(requestId, signal),
      (next: State) => setState({ ...next, key }),
    )
    void loader.load(enabled, id, fingerprint)
    return () => loader.dispose()
  }, [enabled, id, fingerprint, key])
  return enabled && state.key === key ? state : { status: "idle", data: null }
}

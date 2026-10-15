import { ui, formatUi } from "@/lib/i18n/messages.mjs"
import type { LaravelSectorComparison } from "@/lib/laravel-api"

/** Informational only: no callbacks, ranking, selection or decision controls. */
export function SectorComparison({ data, status, topicId, locale }: {
  data: LaravelSectorComparison | null
  status: string
  topicId: number
  locale: "es" | "en"
}) {
  const heading = ui(locale, "Empresas del mismo sector que lo consideraron material")
  if (status === "error") return <p role="status" className="mt-2 text-xs text-muted-foreground">{ui(locale, "No se pudo cargar la comparación sectorial.")}</p>
  if (!data) return null
  return <section className="mt-2 space-y-1 text-xs text-muted-foreground" aria-label={heading}>
    <h4 className="font-medium">{heading}</h4>
    {data.comparisons.map((comparison) => {
      const cell = comparison.topics.find((item) => item.topic_id === String(topicId))
      if (!cell) return null
      const s = cell.sector
      const g = cell.global_counts
      return <div key={comparison.section ?? "global"}>
        <p>{formatUi(locale, "Actividad NACE: {0}", [comparison.section ?? ui(locale, "Sin actividad verificada")])}</p>
        {cell.source === "sector" ? <p>{formatUi(locale, "{0} de {1} empresas con evaluación conocida ({2} %). Población del sector: {3}.", [s.n_material, s.n_scored, ((cell.proportion ?? 0) * 100).toFixed(1), comparison.population_companies])}</p>
          : <>
            <p>{formatUi(locale, "Evaluaciones sectoriales: {0} de {1}. Población del sector: {2}.", [s.n_material, s.n_scored, comparison.population_companies])}</p>
            {cell.source === "global_fallback" ? <>
              <p>{ui(locale, "Soporte sectorial insuficiente. Referencia global de baja confianza.")}</p>
              <p>{formatUi(locale, "Referencia global: {0} de {1} empresas con evaluación conocida ({2} %). Población global: {3}.", [g.n_material, g.n_scored, ((cell.proportion ?? 0) * 100).toFixed(1), data.global_population_companies])}</p>
            </> : <p>{ui(locale, "Sin datos suficientes para comparar este tema NEIS.")}</p>}
          </>}
      </div>
    })}
    <p>{formatUi(locale, "Versión {0} · Fecha {1} · Periodo {2}–{3}", [data.version, data.built_at, data.coverage_period.start, data.coverage_period.end])}</p>
    <p>{formatUi(locale, "Procedencia: {0} · Autorización: {1}", [data.provenance.source_reference, data.provenance.authorization_reference])}</p>
    <p>{ui(locale, ({ automatic: "Etiquetas automáticas de informes", human: "Etiquetas revisadas por personas", mixed: "Etiquetas automáticas y revisión humana", synthetic: "Datos sintéticos de demostración" })[data.provenance.label_kind])}</p>
    <p>{ui(locale, "Orientación basada en etiquetas de informes; no determina la doble importancia relativa.")}</p>
  </section>
}

export const taxonomyTitle = "Actividades potencialmente elegibles según la Taxonomía de la UE (orientativo)"
export const taxonomyDisclaimer = "Cribado orientativo: la elegibilidad no es alineamiento con la Taxonomía de la UE. No se evalúan la contribución sustancial, el principio de no causar un perjuicio significativo (DNSH) ni las garantías mínimas. No se calculan indicadores clave de resultados (KPI) reglamentarios. Los porcentajes son valores declarados por el usuario, sin verificar y sin agregación."
export const taxonomyCoverage = "Catálogo parcial verificado con textos de 2023 y el texto climático consolidado a 1 de enero de 2024. No se evalúan modificaciones posteriores. La ausencia de coincidencias no significa que la actividad no sea elegible. Revisa la descripción oficial antes de confirmar; los códigos NACE son orientativos y no exclusivos."
export const taxonomyClassification = "La caracterización utiliza CNAE-2025. Los anexos utilizan NACE Rev. 2. Solo se comparan las clases con correspondencia verificada; las demás y los códigos de sección, división o grupo requieren revisión manual. No se equiparan automáticamente NACE Rev. 2 y Rev. 2.1."

export const objectiveLabels = {
  climate_change_mitigation: "Mitigación del cambio climático",
  climate_change_adaptation: "Adaptación al cambio climático",
  water_and_marine_resources: "Agua y recursos marinos",
  circular_economy: "Economía circular",
  pollution_prevention_and_control: "Prevención y control de la contaminación",
  biodiversity_and_ecosystems: "Biodiversidad y ecosistemas",
}

/** Blank is unknown, never zero. No aggregation across objectives or accounting denominators. */
export function declaredShare(value) {
  if (value === "" || value === null || value === undefined) return null
  const number = Number(value)
  if (!Number.isFinite(number) || number < 0 || number > 100) throw new Error("Invalid declared percentage")
  return number
}

export function taxonomyPayload(screen, edits) {
  return {
    source_hash: screen.source_hash,
    revision: screen.revision,
    activities: screen.activities.filter((activity) => edits[activity.id]?.confirmed).map((activity) => ({
      activity_id: activity.id,
      turnover_share: declaredShare(edits[activity.id].turnover_share),
      capex_share: declaredShare(edits[activity.id].capex_share),
      opex_share: declaredShare(edits[activity.id].opex_share),
    })),
  }
}

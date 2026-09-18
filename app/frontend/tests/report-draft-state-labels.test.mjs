import assert from "node:assert/strict"
import test from "node:test"
import {
  actionLabel,
  allSectionsReady,
  downloadLabel,
  humanizeKey,
  isScopingOnly,
  limitationMessage,
  sectionLabel,
  statusLabel,
  visibleNextActions,
} from "../lib/report-draft-state.mjs"

test("report draft helpers expose Spanish section and download labels without P-code leakage", () => {
  assert.equal(sectionLabel("characterization"), "Caracterización de la empresa")
  assert.equal(sectionLabel("materiality_proposal"), "Propuesta de temas (IA)")
  assert.equal(sectionLabel("double_materiality_guide"), "Guía de doble materialidad")
  assert.equal(sectionLabel("materiality_confirmation"), "Confirmación de materialidad final")
  assert.equal(sectionLabel("esrs_datapoints"), "Lista de información ESRS")
  assert.equal(sectionLabel("datapoint_responses"), "Respuestas registradas")
  assert.equal(sectionLabel("final_report_generation"), "Resumen de resultados")
  assert.equal(sectionLabel("p10_custom_section"), "Custom Section")
  assert.equal(sectionLabel("P10 Custom Section"), "Custom Section")
  assert.equal(humanizeKey("p10_report_readiness"), "Report Readiness")

  assert.equal(downloadLabel("p8_decision_sheet"), "Hoja de decisión de materialidad")
  assert.equal(downloadLabel("p9_responses_csv"), "Respuestas registradas (CSV)")
  assert.equal(downloadLabel("p9_datapoints_csv"), "Lista de información ESRS (CSV)")
  assert.equal(downloadLabel("characterization_summary_pdf"), "Resumen de caracterización (PDF)")
  assert.equal(downloadLabel("report_readiness"), "Resumen de preparación (JSON)")
  assert.equal(downloadLabel("report_package_html"), "Paquete HTML imprimible")
  assert.equal(downloadLabel("evidence_bundle_json"), "Trazabilidad de preparación (JSON)")
  assert.equal(downloadLabel("p11_custom_export"), "Custom Export")
})

test("report draft helpers translate limitations and status labels for the P10 payoff", () => {
  assert.equal(statusLabel("not_implemented"), "No disponible en esta versión")
  assert.equal(statusLabel("scoping_only"), "Modo alcance")
  assert.equal(statusLabel("generation_pending"), "Pendiente de generación")
  assert.equal(
    limitationMessage({ key: "report_package_scope", message: "Backend message." }),
    "El paquete organiza preparación ESRS 2023. No sustituye presentación oficial, aseguramiento, Taxonomía UE ni aceptación de formatos digitales.",
  )
  assert.equal(
    limitationMessage({ key: "exact_ar16_matter_to_dr_mapping_pending", message: "Backend message." }),
    "Modo alcance: la lista incluye bloques transversales disponibles, pero la información temática derivada de tus temas no se genera hasta que la plataforma tenga una correspondencia tema-requisito configurada y válida.",
  )
  assert.equal(limitationMessage({ key: "custom", message: "Mensaje del backend." }), "Mensaje del backend.")
})

test("report draft helpers detect scoping-only coverage from readiness or draft payloads", () => {
  assert.equal(isScopingOnly({ coverage_mode: "scoping_only" }, null), true)
  assert.equal(isScopingOnly(null, { coverage_mode: "scoping_only" }), true)
  assert.equal(
    isScopingOnly(
      { sections: { esrs_datapoints: { matter_to_dr_mapping_status: "pending" } } },
      { datapoints: { matter_to_dr_mapping_status: "loaded" } },
    ),
    true,
  )
  assert.equal(
    isScopingOnly(
      { coverage_mode: "full", sections: { esrs_datapoints: { matter_to_dr_mapping_status: "loaded" } } },
      { coverage_mode: "full", datapoints: { matter_to_dr_mapping_status: "loaded" } },
    ),
    false,
  )
})

test("report draft helpers remove step-6 self-links and know when only the report remains", () => {
  assert.deepEqual(visibleNextActions({ next_actions: ["/api/report/draft"] }), [])
  assert.deepEqual(visibleNextActions({ next_actions: ["/api/materiality-confirmation", "/api/report/draft"] }), [
    "/api/materiality-confirmation",
  ])
  assert.equal(allSectionsReady({ next_actions: [] }), true)
  assert.equal(allSectionsReady({ next_actions: ["/api/report/draft"] }), true)
  assert.equal(allSectionsReady({ next_actions: ["/api/esrs-datapoints/responses"] }), false)
})

test("report draft action labels use step names instead of humanized API or P-code strings", () => {
  assert.equal(actionLabel("/api/characterization"), "Completar la encuesta inicial (paso 1)")
  assert.equal(actionLabel("/api/materiality-proposal"), "Revisar la propuesta de temas (paso 2)")
  assert.equal(actionLabel("/api/materiality-confirmation"), "Confirmar la materialidad (paso 4)")
  assert.equal(actionLabel("/api/esrs-datapoints"), "Registrar información ESRS (paso 5)")
  assert.equal(actionLabel("/api/esrs-datapoints/responses"), "Registrar información ESRS (paso 5)")
  assert.equal(actionLabel("/api/report/draft"), "Revisar el resumen (paso 6)")
  assert.equal(actionLabel("/api/custom-endpoint"), "Custom Endpoint")
})

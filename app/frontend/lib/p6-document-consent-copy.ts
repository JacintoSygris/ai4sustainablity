// Consent/retention copy for the step-2 document upload panel.
//
// STATUS: DRAFT — pending explicit user sign-off (spec §5 consent-before-capture,
// acceptance gate 6.5 of app/docs/p6-report-upload-extraction-plan.md). This is
// the ONE place this text lives so the approved wording can replace it in a
// single edit. Do not duplicate this copy anywhere else in the frontend.
export const P6_DOCUMENT_CONSENT_COPY_STATUS = "draft_pending_sign_off" as const

export const P6_DOCUMENT_CONSENT_COPY =
  "Tus documentos se guardan de forma privada y solo se usan para buscar evidencias que apoyen tu revisión de temas. " +
  "El análisis se realiza dentro del servicio de la plataforma: el contenido nunca se envía a servicios de inteligencia artificial externos. " +
  "Conservamos cada documento hasta que decidas eliminarlo. " +
  "Puedes eliminarlo en cualquier momento y se borra por completo, incluidas todas las evidencias extraídas de él."

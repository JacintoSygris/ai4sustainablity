/**
 * @typedef {"completed" | "current" | "upcoming"} WizardStepStatus
 * @typedef {{ id: number, title: string, description: string }} WizardStepDefinition
 * @typedef {WizardStepDefinition & { status: WizardStepStatus }} WizardStep
 */

/** @type {WizardStepDefinition[]} */
export const WIZARD_STEPS = [
  {
    id: 1,
    title: "Describe la organización",
    description: "Registra actividad, tamaño, alcance y ejercicio de referencia.",
  },
  {
    id: 2,
    title: "Revisa temas propuestos",
    description: "Contrasta las propuestas automáticas y ajusta la lista con criterio humano.",
  },
  {
    id: 3,
    title: "Doble materialidad",
    description: "Usa la guía para ordenar el análisis que realiza tu equipo.",
  },
  {
    id: 4,
    title: "Confirma temas materiales",
    description: "Guarda la selección final y los motivos que quieras conservar.",
  },
  {
    id: 5,
    title: "Reúne información ESRS",
    description: "Registra respuestas, pendientes y no aplicables justificados.",
  },
  {
    id: 6,
    title: "Resultados",
    description: "Revisa el resumen de preparación y las descargas disponibles.",
  },
]

/**
 * @param {number} viewingStep
 * @returns {WizardStep[]}
 */
export function wizardStepsForView(viewingStep) {
  return WIZARD_STEPS.map((step) => ({
    ...step,
    status: step.id < viewingStep ? "completed" : step.id === viewingStep ? "current" : "upcoming",
  }))
}

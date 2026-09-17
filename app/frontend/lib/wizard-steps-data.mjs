/**
 * @typedef {"completed" | "current" | "upcoming"} WizardStepStatus
 * @typedef {{ id: number, title: string, description: string }} WizardStepDefinition
 * @typedef {WizardStepDefinition & { status: WizardStepStatus }} WizardStep
 */

/** @type {WizardStepDefinition[]} */
export const WIZARD_STEPS = [
  {
    id: 1,
    title: "Encuesta inicial",
    description: "Contesta unas preguntas rápidas sobre tu empresa. ¡Así podremos ayudarte mejor!",
  },
  {
    id: 2,
    title: "Revisión de temas materiales",
    description: "Revisa los temas candidatos propuestos por la IA para tu sector y modifícalos si lo consideras necesario.",
  },
  {
    id: 3,
    title: "Doble materialidad",
    description: "Realiza el análisis de doble materialidad siguiendo las indicaciones marcadas.",
  },
  {
    id: 4,
    title: "Selección final de temas relevantes",
    description: "Tras realizar el análisis, identifica los temas más relevantes para tu empresa.",
  },
  {
    id: 5,
    title: "Datapoints (ESRS)",
    description: "Rellena los indicadores sugeridos con la información de tu empresa.",
  },
  {
    id: 6,
    title: "Informe",
    description: "Revisa el borrador y las descargas preparadas por la plataforma.",
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

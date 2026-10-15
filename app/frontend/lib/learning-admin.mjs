// Client affordances only. Laravel repeats every authorization and prerequisite check.
export function canLearningAction(state, loop, action, candidate = null) {
  if (!state) return false
  const required = ["migrations", "state_directory", "python", "queue", "queue_configuration"]
  if (action !== "rollback") required.push("scheduler", "authority_lease")
  if (loop === "wp13" && ["train", "promote"].includes(action)) required.push("heldout", "code_commit")
  if (required.some(key => state.checks?.[key] !== true)) return false
  if (action !== "rollback" && (!state.engine_enabled || (loop === "wp23" && !["active", "shadow"].includes(state.bandit_mode)))) return false
  const selected = loop === "wp13" ? state.wp13?.selected : state.wp23?.active?.id
  if (action === "promote" && candidate?.id && candidate.id === selected) return false
  if (action === "promote") return candidate?.eligible === true && (loop !== "wp13" || candidate?.gate?.passed === true)
  return true
}

export const learningCheckLabels = {
  migrations: "Migraciones", state_directory: "Directorio privado de estados", python: "Entorno de aprendizaje",
  authority_lease: "Autorización de servicio vigente", queue_configuration: "Cola y bloqueos configurados", scheduler: "Planificador reciente", queue: "Trabajador de cola reciente", heldout: "Conjunto reservado verificado", code_commit: "Versión de código declarada",
}
export const learningStatusLabels = {
  queued: "En cola", running: "En curso", completed: "Completado", failed_closed: "Bloqueado por seguridad",
  registered: "Candidato registrado", no_eligible_feedback: "Sin revisiones aptas", active: "Activo", shadow: "En sombra", off: "Desactivado",
  train: "Entrenar ahora", process: "Procesar eventos", promote: "Promover", rollback: "Revertir",
}

export const learningLoopLabels = {
  wp13: "Motor de aprendizaje supervisado", wp23: "Ajuste por retroalimentación",
}

export const learningFallbackLabels = {
  no_selection: "Sin modelo seleccionado",
  serving_disabled: "Servicio de candidatos desactivado",
  baseline_unavailable: "No se pudo verificar el modelo base",
  selected_incompatible_or_unauthorized: "Modelo seleccionado incompatible o sin autorización vigente",
}

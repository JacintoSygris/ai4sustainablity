export const STUDY_STEPS = ["P5", "P6", "P7", "P8", "P9", "P10"]
/** Study signals carry only a task code, never user text or API payloads. */
let receiver = null
export function subscribeStudySignals(callback) {
  receiver = callback
  return () => { if (receiver === callback) receiver = null }
}
export function completeStudyStep(number) { completeStudyTask(STUDY_STEPS[number - 1]) }
export function completeStudyTask(step) { receiver?.({ type: "complete", step }) }
export function studyTaskError(step) { receiver?.({ type: "error", step }) }
export function studyStep(path) {
  const match = /^\/wizard\/step-([1-6])\/?$/.exec(path)
  return match ? STUDY_STEPS[Number(match[1]) - 1] : null
}

/** In-memory only. Sequential sends keep start ahead of terminal events.
 * A new consent generation invalidates queued work from an old enrolment.
 * @param {{
 * send: (event: {study_participant_id: string, task_id: string, step: string, event: string, error_count?: number}, keepalive: boolean) => Promise<unknown>,
 * uuid: () => string,
 * onComplete?: (step: string) => void,
 * onFailure?: () => void
 * onAttemptChange?: (step: string | null) => void
 * }} options
 */
export function createStudyTracker({ send, uuid, onComplete = () => {}, onFailure = () => {}, onAttemptChange = () => {} }) {
  let participant = null
  let active = null
  let generation = 0
  let queue = Promise.resolve()
  let attached = true
  const changed = () => { if (attached) onAttemptChange(active && !active.ended ? active.step : null) }
  function emit(attempt, event, keepalive = false) {
    const epoch = generation
    const id = participant
    queue = queue.then(async () => {
      if (epoch !== generation || !id || (event !== "start" && !attempt.recorded)) return
      try {
        await send({ study_participant_id: id, task_id: attempt.id, step: attempt.step, event,
          ...(event === "start" ? {} : { error_count: attempt.errors }) }, keepalive)
        if (event === "start") attempt.recorded = true
        if (event === "complete" && epoch === generation && attached) onComplete(attempt.step)
      } catch {
        if (epoch === generation && attached) onFailure()
      }
    })
  }
  return {
    attach() { attached = true; changed() },
    // Unmount drains the terminal event; only withdrawal revokes queued consent.
    detach() { attached = false; this.abandon(true) },
    setParticipant(id) {
      if (participant === id) return
      generation++
      participant = id
      active = null
      changed()
    },
    enter(path) {
      const step = studyStep(path)
      if (active?.step === step) return
      if (active && !active.ended) { active.ended = true; emit(active, "abandon") }
      active = null
      if (!participant || !step) { changed(); return }
      try {
        active = { id: uuid(), step, errors: 0, ended: false, recorded: false }
        emit(active, "start")
      } catch { onFailure() }
      changed()
    },
    signal({ type, step }) {
      if (!participant || !active || active.ended || active.step !== step) return
      if (type === "error") { active.errors++; return }
      if (type === "complete") { active.ended = true; emit(active, "complete"); changed() }
    },
    abandon(keepalive = false) {
      if (active && !active.ended) { active.ended = true; emit(active, "abandon", keepalive) }
      active = null
      changed()
    },
    flush: () => queue,
  }
}

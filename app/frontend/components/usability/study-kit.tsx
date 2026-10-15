"use client"

import { useEffect, useRef, useState, type ReactNode } from "react"
import { usePathname } from "next/navigation"
import Link from "next/link"
import { Button } from "@/components/ui/button"
import { useLocale } from "@/components/locale-provider"
import { ui } from "@/lib/i18n/messages.mjs"
import { laravelApi } from "@/lib/laravel-api"
import { createStudyTracker, subscribeStudySignals, studyStep, STUDY_STEPS } from "@/lib/usability-study.mjs"
import { STUDY_CONSENT, SUS_ITEMS } from "@/lib/usability-questionnaire.mjs"

type StudyState = {
  consent_version: string
  study_participant_id: string | null
  consented: boolean
  questionnaire_submitted: boolean
  p10_completed: boolean
  questionnaire: { answers: number[]; comments: string | null; locale: string } | null
}

/** Mounted only when the server advertises the enabled study. No browser storage. */
export function StudyKit({ children }: { children: ReactNode }) {
  const pathname = usePathname()
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)
  const [study, setStudy] = useState<StudyState | null>(null)
  const [view, setView] = useState<"banner" | "consent" | "sus">("banner")
  const [consent, setConsent] = useState(false)
  const [answers, setAnswers] = useState<Array<number | null>>(Array(10).fill(null))
  const [comments, setComments] = useState("")
  const [currentStep, setCurrentStep] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(false)
  const [eventError, setEventError] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [reload, setReload] = useState(0)
  const actionPending = useRef(false)
  const [tracker] = useState(() => createStudyTracker({
    send: (body: Record<string, unknown>, keepalive: boolean) => laravelApi("/usability-study/events", { method: "POST", body, keepalive }),
    uuid: () => crypto.randomUUID(),
    onComplete: (step: string) => {
      if (step === STUDY_STEPS[5]) {
        setStudy((current) => current ? { ...current, p10_completed: true } : current)
        setView("sus")
      }
    },
    onFailure: () => setEventError(true),
    onAttemptChange: setCurrentStep,
  }))
  const participant = study?.consented ? study.study_participant_id : null

  useEffect(() => {
    let active = true
    laravelApi<{ data: StudyState }>("/usability-study").then(({ data }) => {
      if (!active) return
      setStudy(data)
      setAnswers(data.questionnaire?.answers ?? Array(10).fill(null))
      setComments(data.questionnaire?.comments ?? "")
      setError(false)
      if (data.consented && data.p10_completed && !data.questionnaire_submitted) setView("sus")
    }).catch(() => { if (active) setError(true) })
    return () => { active = false }
  }, [reload])
  useEffect(() => {
    tracker.setParticipant(participant)
    tracker.enter(pathname)
  }, [participant, pathname, tracker])
  useEffect(() => {
    tracker.attach()
    const unsubscribe = subscribeStudySignals((signal: { type: string; step: string }) => tracker.signal(signal))
    const pagehide = () => tracker.abandon(true)
    // bfcache restoration is a fresh attempt, not a completed task.
    const pageshow = () => tracker.enter(window.location.pathname)
    window.addEventListener("pagehide", pagehide)
    window.addEventListener("pageshow", pageshow)
    return () => {
      unsubscribe()
      window.removeEventListener("pagehide", pagehide)
      window.removeEventListener("pageshow", pageshow)
      tracker.detach()
    }
  }, [tracker])

  async function action(work: () => Promise<void>) {
    if (actionPending.current) return
    actionPending.current = true
    setBusy(true); setError(false); setNotice(null)
    try { await work() } catch { setError(true) }
    finally { actionPending.current = false; setBusy(false) }
  }
  async function enrol() {
    if (!consent || !study) return
    await action(async () => {
      const { data } = await laravelApi<{ data: { study_participant_id: string } }>("/usability-study/enrol", {
        method: "POST", body: { consent: true, consent_version: study.consent_version },
      })
      const sameStudy = study.study_participant_id === data.study_participant_id
      setStudy({ ...study, consented: true, study_participant_id: data.study_participant_id,
        ...(!sameStudy ? { p10_completed: false, questionnaire_submitted: false, questionnaire: null } : {}) })
      if (!sameStudy) { setAnswers(Array(10).fill(null)); setComments("") }
      setConsent(false); setView("banner")
      if (sameStudy && !study.consented) setReload((value) => value + 1)
    })
  }
  async function withdraw() {
    await action(async () => {
      tracker.setParticipant(null)
      try {
        await laravelApi("/usability-study", { method: "DELETE" })
        setStudy((current) => current ? { ...current, study_participant_id: null, consented: false, questionnaire_submitted: false, p10_completed: false, questionnaire: null } : current)
        setAnswers(Array(10).fill(null)); setComments(""); setConsent(false); setView("banner"); setEventError(false)
        setNotice("Consentimiento retirado; tus datos del estudio se han borrado.")
      } catch (err) {
        tracker.setParticipant(participant); tracker.enter(pathname)
        throw err
      }
    })
  }
  async function questionnaire() {
    if (!participant || answers.some((answer) => answer === null)) return
    await action(async () => {
      await laravelApi("/usability-study/questionnaire", {
        method: "PUT", body: { study_participant_id: participant, answers, comments },
      })
      setStudy((current) => current ? { ...current, questionnaire_submitted: true } : current)
      setView("banner"); setNotice("Cuestionario guardado. Gracias por tu participación.")
    })
  }

  return <>
    <aside aria-label={tr("Estudio voluntario de usabilidad")} className="mb-6 space-y-3 rounded-lg border bg-background p-4">
      <h2 className="text-lg font-semibold">{tr("Estudio voluntario de usabilidad")}</h2>
      {error && <p role="alert">{tr("No se pudo guardar la acción del estudio. Inténtalo de nuevo.")}</p>}
      {eventError && <p role="alert">{tr("No se pudieron registrar algunos eventos del estudio. Las métricas pueden estar incompletas.")}</p>}
      {notice && <p role="status">{tr(notice)}</p>}
      {!study && error && <Button onClick={() => setReload((value) => value + 1)}>{tr("Reintentar")}</Button>}
      {study && view === "banner" && <div className="flex flex-wrap items-center gap-3">
        {participant ? <>
          <p>{tr("Estás participando en el estudio de usabilidad.")}</p>
          <Button variant="outline" disabled={busy} onClick={() => setView("sus")}>{tr("Cuestionario de usabilidad SUS")}</Button>
          {studyStep(pathname) === STUDY_STEPS[5] && currentStep === STUDY_STEPS[5] && <Button disabled={busy} onClick={() => tracker.signal({ type: "complete", step: STUDY_STEPS[5] })}>{tr("He terminado de revisar el informe y sus descargas")}</Button>}
        </> : <Button variant="outline" disabled={busy} onClick={() => setView("consent")}>{tr("Participar en el estudio")}</Button>}
      </div>}
      {study && view === "consent" && <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); void enrol() }}>
        {STUDY_CONSENT.map((paragraph: string) => <p key={paragraph}>{tr(paragraph)}</p>)}
        <p><Link className="underline" href="/privacy">{tr("Consulta el responsable, el contacto para ejercer tus derechos y la información de privacidad.")}</Link></p>
        <label className="flex items-start gap-2"><input type="checkbox" required checked={consent} onChange={(event) => setConsent(event.target.checked)} disabled={busy} />{tr("He leído la información y consiento expresamente el tratamiento de mis datos para este estudio voluntario.")}</label>
        <div className="flex flex-wrap gap-3">
          <Button type="submit" disabled={!consent || busy}>{tr("Inscribirme con consentimiento")}</Button>
          <Button type="button" variant="outline" disabled={busy} onClick={() => { setView("banner"); setConsent(false) }}>{tr("Continuar sin participar")}</Button>
        </div>
      </form>}
      {participant && view === "sus" && <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); void questionnaire() }}>
        <h3 className="font-semibold">{tr("Cuestionario de usabilidad SUS")}</h3>
        <p id="sus-scale">{tr("1: totalmente en desacuerdo · 3: neutral · 5: totalmente de acuerdo")}</p>
        {SUS_ITEMS.map((item: string, index: number) => <fieldset key={item} disabled={busy} aria-describedby="sus-scale" className="space-y-2">
          <legend>{index + 1}. {tr(item)}</legend>
          <div className="flex flex-wrap gap-6">{[1, 2, 3, 4, 5].map((value) => <label key={value} className="flex min-h-11 items-center gap-2">
            <input type="radio" name={`sus-${index}`} required value={value} checked={answers[index] === value} onChange={() => setAnswers((current) => current.map((answer, i) => i === index ? value : answer))} />{value}
          </label>)}</div>
        </fieldset>)}
        <label className="block">{tr("Comentarios opcionales (sin nombres, correos ni datos de tu empresa)")}<textarea className="mt-2 block w-full rounded border p-2" maxLength={2000} value={comments} onChange={(event) => setComments(event.target.value)} disabled={busy} /></label>
        <div className="flex flex-wrap gap-3">
          <Button type="submit" disabled={busy || answers.some((answer) => answer === null)}>{tr("Guardar cuestionario")}</Button>
          <Button type="button" variant="outline" disabled={busy} onClick={() => setView("banner")}>{tr("Cerrar")}</Button>
        </div>
        <p className="text-xs">{tr("Fuente del cuestionario SUS:")} <a className="underline" href="https://humanfactors.jmir.org/2020/4/e21161/">{tr("Sevilla-Gonzalez et al. (2020), CC BY 4.0")}</a>{" · "}{tr("Escala original en inglés:")} {tr("Brooke, J. (1996), SUS: a quick and dirty usability scale")}</p>
      </form>}
      {study?.study_participant_id && <Button variant="outline" disabled={busy} onClick={() => void withdraw()}>{tr("Retirar consentimiento y borrar mis datos")}</Button>}
    </aside>
    {children}
  </>
}

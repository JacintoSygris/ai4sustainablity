"use client"
import { useEffect, useRef } from "react"
import { useLocale } from "@/components/locale-provider"
import { studyTaskError, STUDY_STEPS } from "./usability-study.mjs"

/** Count newly surfaced notices, without passing their text to the study. */
export function useStudyError(step: number, message: string | null) {
  const { locale } = useLocale()
  const previous = useRef<{ message: string | null; locale: string }>({ message: null, locale })
  useEffect(() => {
    // Re-translating an existing notice is not another error occurrence.
    if (message && message !== previous.current.message && locale === previous.current.locale) {
      studyTaskError(STUDY_STEPS[step - 1])
    }
    previous.current = { message, locale }
  }, [step, message, locale])
}

"use client"

import { useCallback, useState } from "react"
import { useLocale } from "@/components/locale-provider"
import { formatUi } from "@/lib/i18n/messages.mjs"

type SystemCopy = { source: string; values: Array<string | number> }
type Message = SystemCopy | string | null

/** Only authored system copy. Substitutions are carried verbatim, never translated. */
export function systemCopy(source: string, values: Array<string | number> = []): SystemCopy {
  return { source, values }
}

/** Keep descriptions for system notices; dismiss opaque server notices when their language becomes stale. */
export function useSystemMessage(initial: string | null = null) {
  const { locale } = useLocale()
  const [stored, setStored] = useState<{ message: Message; receivedLocale: string }>({ message: initial, receivedLocale: locale })
  const setMessage = useCallback((message: Message) => setStored({ message, receivedLocale: locale }), [locale])
  const message = stored.message
  return [typeof message === "object" && message !== null
    ? formatUi(locale, message.source, message.values)
    : stored.receivedLocale === locale ? message : null, setMessage] as const
}

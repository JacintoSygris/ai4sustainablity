"use client"

import type { ReactNode } from "react"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import { GLOSSARY, type GlossaryKey } from "@/lib/glossary"

type TermProps = {
  k: GlossaryKey
  children: ReactNode
}

export function Term({ k, children }: TermProps) {
  const entry = GLOSSARY[k]

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          type="button"
          className="inline cursor-help border-b border-dotted border-primary/70 text-left text-inherit underline-offset-4 focus:outline-none focus:ring-2 focus:ring-primary/30"
        >
          {children}
        </button>
      </TooltipTrigger>
      <TooltipContent side="top" className="max-w-sm bg-foreground px-3 py-2 text-left text-background">
        <p className="font-semibold">{entry.es.term}</p>
        <p className="mt-1 leading-snug">{entry.es.definition}</p>
      </TooltipContent>
    </Tooltip>
  )
}

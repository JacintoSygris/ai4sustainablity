"use client"

import { ui } from "@/lib/i18n/messages.mjs"
import { useLocale } from "@/components/locale-provider"

import { Loader2Icon } from 'lucide-react'

import { cn } from '@/lib/utils'

function Spinner({ className, ...props }: React.ComponentProps<'svg'>) {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  return (
    <Loader2Icon
      role="status"
      aria-label={tr("Cargando")}
      className={cn('size-4 animate-spin', className)}
      {...props}
    />
  )
}

export { Spinner }

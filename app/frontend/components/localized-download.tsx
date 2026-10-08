"use client"

import { type ComponentProps } from "react"
import { useLocale } from "@/components/locale-provider"
import { Button } from "@/components/ui/button"

type LocalizedDownloadProps = Omit<ComponentProps<typeof Button>, "asChild" | "onClick"> & {
  href: string
  target?: string
  rel?: string
}

/** No navigable anchor exists until the server has persisted the locale. */
export function LocalizedDownload({ href, target, rel, disabled, children, ...props }: LocalizedDownloadProps) {
  const { changing } = useLocale()
  if (changing || disabled) {
    return <Button {...props} type="button" disabled>{children}</Button>
  }

  return (
    <Button {...props} asChild>
      <a href={href} target={target} rel={rel}>{children}</a>
    </Button>
  )
}

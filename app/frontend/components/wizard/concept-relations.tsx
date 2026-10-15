import { ui } from "@/lib/i18n/messages.mjs"

/** The backend supplies these optional, informational hints only when enabled. */
export function ConceptRelations({ items, kind, locale }: {
  items?: Array<{ id: string | number; label: string; esrs_code?: string }>
  kind: "topics" | "datapoints"
  locale: "es" | "en"
}) {
  if (!items?.length) return null
  const heading = ui(locale, kind === "topics" ? "Temas que suelen reportarse juntos" : "Conceptos relacionados")
  const note = ui(locale, kind === "topics"
    ? "Relaciones observadas en etiquetas del corpus; no determinan la doble importancia relativa."
    : "Similitud entre conceptos; no modifica las obligaciones de información.")
  return (
    <section className="mt-2 space-y-2" aria-label={heading}>
      <h4 className="text-sm font-medium">{heading}</h4>
      <p className="text-xs text-muted-foreground">{note}</p>
      <div className="flex flex-wrap gap-2">
        {items.map((item) => <span key={item.id} className="rounded-md border border-border px-2 py-1 text-xs">{item.esrs_code ?? item.id} · {item.label}</span>)}
      </div>
    </section>
  )
}

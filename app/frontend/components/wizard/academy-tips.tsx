import { AlertTriangle, SquareCheckBig } from "lucide-react"

interface AcademyTipsProps {
  title: string
  tips: string[]
  importanceTitle?: string
  importanceItems?: { title: string; description: string }[]
  links?: { title: string; href: string; image?: string }[]
  importantNote?: { title: string; content: string }
  icon?: "warning" | "check"
}

export function AcademyTips({
  title,
  tips,
  importanceTitle,
  importanceItems,
  links,
  importantNote,
  icon = "check",
}: AcademyTipsProps) {
  const TipIcon = icon === "warning" ? AlertTriangle : SquareCheckBig

  return (
    <aside className="w-full lg:w-80 shrink-0">
      <div className="rounded-xl border border-border bg-card p-6">
        <h3 className="text-lg font-semibold text-primary">Airis Academy</h3>

        <div className="mt-4">
          <div className="flex items-center gap-2">
            <TipIcon className={icon === "warning" ? "h-5 w-5 text-amber-500" : "h-5 w-5 text-accent"} />
            <span className="font-medium text-foreground">{title}</span>
          </div>

          <ul className="mt-3 space-y-2">
            {tips.map((tip, index) => (
              <li key={index} className="flex gap-2 text-sm text-muted-foreground">
                <span className="shrink-0">•</span>
                <span>{tip}</span>
              </li>
            ))}
          </ul>
        </div>

        {links && links.length > 0 && (
          <div className="mt-6 space-y-2">
            {links.map((link) => (
              <a
                key={`${link.title}-${link.href}`}
                href={link.href}
                className="block rounded-lg border border-border px-3 py-2 text-sm text-foreground hover:bg-muted"
              >
                {link.title}
              </a>
            ))}
          </div>
        )}

        {importanceTitle && importanceItems && (
          <div className="mt-6">
            <h4 className="font-medium text-foreground">{importanceTitle}</h4>
            <ul className="mt-3 space-y-3">
              {importanceItems.map((item, index) => (
                <li key={index} className="text-sm">
                  <span className="font-medium text-foreground">• {item.title}:</span>{" "}
                  <span className="text-muted-foreground">{item.description}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        {importantNote && (
          <div className="mt-6 rounded-lg bg-muted/50 p-3 text-sm">
            <h4 className="font-medium text-foreground">{importantNote.title}</h4>
            <p className="mt-1 text-muted-foreground">{importantNote.content}</p>
          </div>
        )}
      </div>
    </aside>
  )
}

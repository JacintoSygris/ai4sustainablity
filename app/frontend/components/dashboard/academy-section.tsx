"use client"

import { ui } from "@/lib/i18n/messages.mjs"
import { useLocale } from "@/components/locale-provider"

import { useState } from "react"
import { Search, ExternalLink } from "lucide-react"
import { Input } from "@/components/ui/input"
import { Card, CardContent, CardHeader } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"

const articles = [
  {
    id: 1,
    title: "¿Qué es la doble importancia relativa?",
    description:
      "Aprende sobre el concepto de doble importancia relativa y cómo evaluar los impactos ambientales y financieros de tu empresa según NEIS.",
    image: "/forest-trees-canopy-sustainability.jpg",
    isExternal: true,
  },
  {
    id: 2,
    title: "Grupos de interés",
    description:
      "Descubre cómo identificar y consultar a los grupos de interés clave para tu análisis de materialidad.",
    image: "/esg-business-meeting.jpg",
    isExternal: true,
  },
  {
    id: 3,
    title: "Energías renovables y CSRD",
    description:
      "Pistas para revisar información relacionada con energías renovables antes de incorporarla a una salida de trabajo.",
    image: "/wind-turbines-renewable-energy.jpg",
    isExternal: true,
  },
]

export function AcademySection() {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  const [search, setSearch] = useState("")

  const filteredArticles = articles.filter((article) => tr(article.title).toLowerCase().includes(search.toLowerCase()))

  return (
    <section className="mt-12">
      <h2 className="text-2xl font-bold text-foreground">{tr("Academia Airis")}</h2>
      <p className="mt-2 text-muted-foreground">
        {" "}{tr("Consulta guías de apoyo para revisar materialidad, grupos de interés e información de sostenibilidad:")}{" "}</p>

      <div className="relative mt-6 max-w-md">
        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="search"
          placeholder={tr("Buscar...")}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="pl-10"
        />
      </div>

      <div className="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        {filteredArticles.map((article) => (
          <Card key={article.id} className="overflow-hidden transition-shadow hover:shadow-lg">
            <CardHeader className="relative p-0">
              <img src={article.image || "/placeholder.svg"} alt={tr(article.title)} className="h-48 w-full object-cover" />
              {article.isExternal && (
                <Badge variant="secondary" className="absolute left-3 top-3 gap-1">
                  <ExternalLink className="h-3 w-3" />
                  {" "}{tr("Enlace externo")}{" "}</Badge>
              )}
            </CardHeader>
            <CardContent className="p-4">
              <h3 className="font-semibold text-primary">{tr(article.title)}</h3>
              <p className="mt-2 text-sm text-muted-foreground line-clamp-3">{tr(article.description)}</p>
            </CardContent>
          </Card>
        ))}
      </div>
    </section>
  )
}

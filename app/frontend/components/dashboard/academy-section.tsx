"use client"

import { useState } from "react"
import { Search, ExternalLink } from "lucide-react"
import { Input } from "@/components/ui/input"
import { Card, CardContent, CardHeader } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"

const articles = [
  {
    id: 1,
    title: "¿Qué es la doble materialidad?",
    description:
      "Aprende sobre el concepto de doble materialidad y cómo evaluar los impactos ambientales y financieros de tu empresa según ESRS.",
    image: "/forest-trees-canopy-sustainability.jpg",
    isExternal: true,
  },
  {
    id: 2,
    title: "Guía de stakeholders ESG",
    description:
      "Descubre cómo identificar y consultar a los grupos de interés clave para tu análisis de materialidad.",
    image: "/esg-business-meeting.jpg",
    isExternal: true,
  },
  {
    id: 3,
    title: "Energías renovables y CSRD",
    description:
      "Todo lo que necesitas saber sobre la divulgación de información relacionada con energías renovables en tu informe ESG.",
    image: "/wind-turbines-renewable-energy.jpg",
    isExternal: true,
  },
]

export function AcademySection() {
  const [search, setSearch] = useState("")

  const filteredArticles = articles.filter((article) => article.title.toLowerCase().includes(search.toLowerCase()))

  return (
    <section className="mt-12">
      <h2 className="text-2xl font-bold text-foreground">Airis Academy</h2>
      <p className="mt-2 text-muted-foreground">
        Descubre artículos, guías y tutoriales que te acompañarán en cada fase de la generación de un informe ESG:
      </p>

      <div className="relative mt-6 max-w-md">
        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="search"
          placeholder="Buscar..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="pl-10"
        />
      </div>

      <div className="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        {filteredArticles.map((article) => (
          <Card key={article.id} className="overflow-hidden transition-shadow hover:shadow-lg">
            <CardHeader className="relative p-0">
              <img src={article.image || "/placeholder.svg"} alt={article.title} className="h-48 w-full object-cover" />
              {article.isExternal && (
                <Badge variant="secondary" className="absolute left-3 top-3 gap-1">
                  <ExternalLink className="h-3 w-3" />
                  Enlace externo
                </Badge>
              )}
            </CardHeader>
            <CardContent className="p-4">
              <h3 className="font-semibold text-primary">{article.title}</h3>
              <p className="mt-2 text-sm text-muted-foreground line-clamp-3">{article.description}</p>
            </CardContent>
          </Card>
        ))}
      </div>
    </section>
  )
}

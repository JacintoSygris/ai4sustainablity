export type GlossaryEntry = {
  es: {
    term: string
    definition: string
  }
  en: {
    term: string
    definition: string
  }
}

export const GLOSSARY = {
  materialidad: {
    es: {
      term: "Materialidad",
      definition:
        "Un tema es material cuando importa para tu organización: porque afecta a personas o medioambiente, o porque puede afectar a resultados, costes, ingresos o riesgos. La decisión final requiere revisión humana.",
    },
    en: {
      term: "Materiality",
      definition:
        "A topic is material when it matters to your organisation: because it affects people or the environment, or because it can affect results, costs, revenue or risks. The final decision requires human review.",
    },
  },
  doble_materialidad: {
    es: {
      term: "Doble materialidad",
      definition:
        "Mirar cada tema desde dos lados: el impacto que tu empresa causa hacia fuera (personas, medioambiente) y el efecto que el tema puede tener hacia dentro (costes, ingresos, riesgos). Si cualquiera de los dos es relevante, el tema es material.",
    },
    en: {
      term: "Double materiality",
      definition:
        "Looking at each topic from two sides: the impact your company causes outward (people, environment) and the effect the topic may have inward (costs, revenue, risks). If either side is relevant, the topic is material.",
    },
  },
  adm: {
    es: {
      term: "Análisis de doble materialidad",
      definition:
        "El trabajo de revisar tus temas uno a uno con la mirada de doble materialidad, hablando con las personas adecuadas, y concluir cuáles son materiales. Esta aplicación te guía, pero el análisis lo haces tú.",
    },
    en: {
      term: "Double materiality assessment",
      definition:
        "The work of reviewing your topics one by one through the double-materiality lens, speaking with the right people, and concluding which topics are material. This application guides you, but you perform the assessment.",
    },
  },
  datapoint: {
    es: {
      term: "Punto de información",
      definition:
        "Cada elemento concreto que puede requerir una cifra, un porcentaje o una explicación. La lista depende de temas confirmados y de correspondencias configuradas.",
    },
    en: {
      term: "Information point",
      definition:
        "Each specific item that may require a figure, percentage or explanation. The list depends on confirmed topics and configured mappings.",
    },
  },
  esrs: {
    es: {
      term: "ESRS",
      definition:
        "Las normas europeas de información de sostenibilidad (European Sustainability Reporting Standards). Definen qué temas considerar y qué datos publicar.",
    },
    en: {
      term: "ESRS",
      definition:
        "The European Sustainability Reporting Standards. They define which topics to consider and which data to publish.",
    },
  },
  requisito_divulgacion: {
    es: {
      term: "Requisito de divulgación",
      definition:
        "Un apartado del estándar que agrupa varios puntos de información sobre una misma cuestión. Que un tema sea material no elimina la revisión de aplicabilidad.",
    },
    en: {
      term: "Disclosure requirement",
      definition:
        "A section of the standard that groups several information points about the same issue. A material topic still requires applicability review.",
    },
  },
  umbral: {
    es: {
      term: "Umbral de importancia",
      definition:
        "El nivel a partir del cual un impacto o efecto se considera lo bastante grande para hacer material un tema. Por debajo del umbral, el tema puede quedar fuera con justificación.",
    },
    en: {
      term: "Importance threshold",
      definition:
        "The level from which an impact or effect is considered large enough to make a topic material. Below the threshold, the topic can be left out with justification.",
    },
  },
  grupos_interes: {
    es: {
      term: "Grupos de interés",
      definition:
        "Las personas y organizaciones afectadas por tu empresa o que influyen en ella: plantilla, clientes, proveedores, vecinos, administración, financiadores.",
    },
    en: {
      term: "Stakeholder groups",
      definition:
        "The people and organizations affected by your company or that influence it: workforce, customers, suppliers, neighbours, public authorities, funders.",
    },
  },
  cadena_valor: {
    es: {
      term: "Cadena de valor",
      definition:
        "Todo lo que pasa antes y después de tu actividad: proveedores y materias primas aguas arriba, distribución, uso y fin de vida aguas abajo. Algunos temas son materiales por tu cadena de valor aunque no ocurran dentro de tu empresa.",
    },
    en: {
      term: "Value chain",
      definition:
        "Everything that happens before and after your activity: suppliers and raw materials upstream, distribution, use, and end of life downstream. Some topics are material because of your value chain even if they do not occur inside your company.",
    },
  },
  fase_transicion: {
    es: {
      term: "Aplazamiento (phase-in)",
      definition:
        "Alivio temporal del estándar: las empresas de menos de 750 personas empleadas pueden aplazar ciertos datos durante los primeros ejercicios.",
    },
    en: {
      term: "Phase-in deferral",
      definition:
        "Temporary relief in the standard: companies with fewer than 750 employees may defer certain data during the first reporting periods.",
    },
  },
} satisfies Record<string, GlossaryEntry>

export type GlossaryKey = keyof typeof GLOSSARY

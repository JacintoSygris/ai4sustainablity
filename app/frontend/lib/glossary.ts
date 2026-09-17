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
        "Un tema es material cuando importa de verdad para tu empresa: porque afecta a personas o medioambiente, o porque puede afectar a tus resultados. El informe solo profundiza en los temas materiales.",
    },
    en: {
      term: "Materiality",
      definition:
        "A topic is material when it truly matters to your company: because it affects people or the environment, or because it can affect your results. The report only goes deep on material topics.",
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
      term: "Datapoint",
      definition:
        "Cada dato concreto que el estándar pide informar: una cifra, un porcentaje o una explicación. Tu lista de datapoints sale de los temas que confirmes como materiales.",
    },
    en: {
      term: "Datapoint",
      definition:
        "Each specific data item the standard asks you to report: a figure, a percentage, or an explanation. Your datapoint list comes from the topics you confirm as material.",
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
        "Un apartado del estándar que agrupa varios datapoints sobre una misma cuestión (por ejemplo, consumo de energía). Si el tema es material, sus requisitos de divulgación aplican.",
    },
    en: {
      term: "Disclosure requirement",
      definition:
        "A section of the standard that groups several datapoints about the same issue, such as energy consumption. If the topic is material, its disclosure requirements apply.",
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

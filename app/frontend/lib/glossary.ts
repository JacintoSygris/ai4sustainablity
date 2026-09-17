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
  asg: {
    es: {
      term: "ASG",
      definition:
        "Ambiental, social y de gobernanza; ESG en inglés. Es el ámbito funcional del trabajo de sostenibilidad que esta aplicación ayuda a ordenar.",
    },
    en: {
      term: "ESG",
      definition:
        "Environmental, social and governance; ASG in Spanish. It is the functional area of sustainability work this application helps organise.",
    },
  },
  csrd: {
    es: {
      term: "CSRD",
      definition:
        "Directiva europea sobre información corporativa en materia de sostenibilidad. La aplicación apoya el trabajo, pero no decide si tu organización está obligada ni si cumple.",
    },
    en: {
      term: "CSRD",
      definition:
        "Corporate Sustainability Reporting Directive. The application supports the work, but does not decide whether your organisation is in scope or compliant.",
    },
  },
  materialidad: {
    es: {
      term: "Materialidad",
      definition:
        "Un asunto es material cuando importa para tu organización: porque afecta a personas o medioambiente, o porque puede afectar a resultados, costes, ingresos o riesgos. La decisión final requiere revisión humana.",
    },
    en: {
      term: "Materiality",
      definition:
        "A matter is material when it matters to your organisation: because it affects people or the environment, or because it can affect results, costs, revenue or risks. The final decision requires human review.",
    },
  },
  doble_materialidad: {
    es: {
      term: "Doble materialidad",
      definition:
        "Mirar cada asunto desde dos lados: el impacto que tu empresa causa hacia fuera (personas, medioambiente) y el efecto que el asunto puede tener hacia dentro (costes, ingresos, riesgos). Determina qué asuntos e información ESRS deben tratarse.",
    },
    en: {
      term: "Double materiality",
      definition:
        "Looking at each matter from two sides: the impact your company causes outward (people, environment) and the effect the matter may have inward (costs, revenue, risks). It determines which matters and ESRS information need to be addressed.",
    },
  },
  adm: {
    es: {
      term: "Análisis de doble materialidad",
      definition:
        "El trabajo de revisar tus asuntos ASG uno a uno con la mirada de doble materialidad, hablando con las personas adecuadas, y concluir cuáles son materiales. Esta aplicación te guía, pero el análisis lo hace la organización.",
    },
    en: {
      term: "Double materiality assessment",
      definition:
        "The work of reviewing ESG matters one by one through the double-materiality lens, speaking with the right people, and concluding which matters are material. This application guides you, but the organisation performs the assessment.",
    },
  },
  datapoint: {
    es: {
      term: "Punto de información",
      definition:
        "Cada indicador/dato ESRS concreto que puede requerir una cifra, un porcentaje, una explicación o una referencia. La lista depende de asuntos confirmados y de correspondencias configuradas.",
    },
    en: {
      term: "Information point",
      definition:
        "Each specific ESRS indicator/data point that may require a figure, percentage, explanation or reference. The list depends on confirmed matters and configured mappings.",
    },
  },
  esrs: {
    es: {
      term: "ESRS",
      definition:
        "Las normas europeas de información de sostenibilidad (European Sustainability Reporting Standards). Definen asuntos, requisitos de divulgación e indicadores/datos.",
    },
    en: {
      term: "ESRS",
      definition:
        "The European Sustainability Reporting Standards. They define matters, Disclosure Requirements and indicators/data.",
    },
  },
  requisito_divulgacion: {
    es: {
      term: "Requisito de divulgación",
      definition:
        "Un apartado de las ESRS que agrupa varios indicadores/datos sobre una misma cuestión. Que un asunto sea material no elimina la revisión de aplicabilidad.",
    },
    en: {
      term: "Disclosure requirement",
      definition:
        "A section of the ESRS that groups several indicators/data points about the same issue. A material matter still requires applicability review.",
    },
  },
  umbral: {
    es: {
      term: "Umbral de importancia",
      definition:
        "El nivel a partir del cual un impacto o efecto se considera lo bastante grande para hacer material un asunto. Por debajo del umbral, el asunto puede quedar fuera con justificación.",
    },
    en: {
      term: "Importance threshold",
      definition:
        "The level from which an impact or effect is considered large enough to make a matter material. Below the threshold, the matter can be left out with justification.",
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
        "Todo lo que pasa antes y después de tu actividad: proveedores y materias primas aguas arriba, distribución, uso y fin de vida aguas abajo. Algunos asuntos son materiales por tu cadena de valor aunque no ocurran dentro de tu empresa.",
    },
    en: {
      term: "Value chain",
      definition:
        "Everything that happens before and after your activity: suppliers and raw materials upstream, distribution, use, and end of life downstream. Some matters are material because of your value chain even if they do not occur inside your company.",
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

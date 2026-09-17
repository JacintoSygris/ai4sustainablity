// ESG Material Topics data structure for ESRS compliance
// Topics marked as "airisRecommended" are basic ones recommended for most companies

export interface ESGTopic {
  id: string
  name: string
  airisRecommended?: boolean
  examples?: string[]
}

export interface ESGSubcategory {
  id: string
  code: string
  name: string
  topics: ESGTopic[]
}

export interface ESGCategory {
  id: string
  name: string
  icon: "environment" | "social" | "governance"
  subcategories: ESGSubcategory[]
}

export const esgTopicsData: ESGCategory[] = [
  {
    id: "environment",
    name: "Medioambiente",
    icon: "environment",
    subcategories: [
      {
        id: "e1",
        code: "E.1",
        name: "Cambio climático",
        topics: [
          {
            id: "e1-1",
            name: "Adaptación al cambio climático",
            airisRecommended: true,
            examples: [
              "Implementación de infraestructuras resilientes ante eventos climáticos extremos.",
              "Desarrollo de planes de gestión de riesgos climáticos en la cadena de suministro.",
            ],
          },
          {
            id: "e1-2",
            name: "Mitigación del cambio climático",
            airisRecommended: true,
            examples: [
              "Reducción de emisiones de gases de efecto invernadero.",
              "Transición hacia energías renovables.",
            ],
          },
          {
            id: "e1-3",
            name: "Energía",
            airisRecommended: true,
            examples: ["Consumo y eficiencia energética.", "Uso de fuentes de energía renovable."],
          },
        ],
      },
      {
        id: "e2",
        code: "E.2",
        name: "Contaminación",
        topics: [
          {
            id: "e2-1",
            name: "Contaminación del aire",
            examples: [
              "Emisiones de partículas y gases contaminantes.",
              "Gestión de la calidad del aire en instalaciones.",
            ],
          },
          {
            id: "e2-2",
            name: "Contaminación del agua",
            examples: ["Gestión de vertidos industriales.", "Prevención de contaminación de acuíferos."],
          },
          {
            id: "e2-3",
            name: "Contaminación del suelo",
            examples: ["Gestión de residuos peligrosos.", "Remediación de suelos contaminados."],
          },
          {
            id: "e2-4",
            name: "Sustancias preocupantes",
            airisRecommended: true,
            examples: ["Uso y eliminación de sustancias químicas peligrosas.", "Cumplimiento normativo REACH."],
          },
        ],
      },
      {
        id: "e3",
        code: "E.3",
        name: "Agua y recursos marinos",
        topics: [
          {
            id: "e3-1",
            name: "Consumo de agua",
            airisRecommended: true,
            examples: ["Gestión eficiente del consumo de agua.", "Reciclaje y reutilización de aguas."],
          },
          {
            id: "e3-2",
            name: "Extracción de agua",
            examples: ["Impacto en acuíferos locales.", "Gestión sostenible de recursos hídricos."],
          },
          {
            id: "e3-3",
            name: "Vertidos de agua",
            examples: ["Tratamiento de aguas residuales.", "Calidad del agua vertida."],
          },
          {
            id: "e3-4",
            name: "Vertidos de agua en los océanos",
            examples: ["Impacto en ecosistemas marinos.", "Prevención de contaminación marina."],
          },
          {
            id: "e3-5",
            name: "Recursos marinos",
            examples: ["Pesca sostenible.", "Protección de biodiversidad marina."],
          },
        ],
      },
      {
        id: "e4",
        code: "E.4",
        name: "Biodiversidad y ecosistemas",
        topics: [
          {
            id: "e4-1",
            name: "Impacto en la biodiversidad",
            examples: ["Evaluación de impacto en especies protegidas.", "Planes de conservación de hábitats."],
          },
          {
            id: "e4-2",
            name: "Uso del suelo",
            examples: ["Gestión sostenible del territorio.", "Minimización de la deforestación."],
          },
          {
            id: "e4-3",
            name: "Servicios ecosistémicos",
            airisRecommended: true,
            examples: ["Valoración de servicios ecosistémicos.", "Integración de capital natural."],
          },
        ],
      },
      {
        id: "e5",
        code: "E.5",
        name: "Economía circular",
        topics: [
          {
            id: "e5-1",
            name: "Gestión de residuos",
            airisRecommended: true,
            examples: ["Reducción, reutilización y reciclaje de residuos.", "Gestión de residuos peligrosos."],
          },
          {
            id: "e5-2",
            name: "Diseño circular de productos",
            examples: ["Ecodiseño y durabilidad.", "Uso de materiales reciclados."],
          },
          {
            id: "e5-3",
            name: "Recursos entrantes",
            examples: ["Uso de materias primas sostenibles.", "Trazabilidad de materiales."],
          },
        ],
      },
    ],
  },
  {
    id: "social",
    name: "Social",
    icon: "social",
    subcategories: [
      {
        id: "s1",
        code: "S.1",
        name: "Plantilla propia",
        topics: [
          {
            id: "s1-1",
            name: "Condiciones de trabajo",
            airisRecommended: true,
            examples: ["Horarios y condiciones laborales.", "Salarios y beneficios."],
          },
          {
            id: "s1-2",
            name: "Salud y seguridad",
            airisRecommended: true,
            examples: ["Prevención de riesgos laborales.", "Bienestar de los empleados."],
          },
          {
            id: "s1-3",
            name: "Igualdad de trato y oportunidades",
            airisRecommended: true,
            examples: ["Diversidad e inclusión.", "Igualdad salarial."],
          },
          {
            id: "s1-4",
            name: "Formación y desarrollo",
            examples: ["Programas de capacitación.", "Desarrollo profesional."],
          },
          {
            id: "s1-5",
            name: "Conciliación vida laboral-personal",
            examples: ["Flexibilidad horaria.", "Teletrabajo y permisos."],
          },
        ],
      },
      {
        id: "s2",
        code: "S.2",
        name: "Trabajadores en la cadena de valor",
        topics: [
          {
            id: "s2-1",
            name: "Condiciones laborales en proveedores",
            airisRecommended: true,
            examples: ["Auditorías sociales a proveedores.", "Cumplimiento de estándares laborales."],
          },
          {
            id: "s2-2",
            name: "Trabajo infantil y forzoso",
            examples: ["Políticas de prevención.", "Due diligence en cadena de suministro."],
          },
          {
            id: "s2-3",
            name: "Salud y seguridad en la cadena",
            examples: ["Requisitos de seguridad a proveedores.", "Monitoreo de condiciones."],
          },
        ],
      },
      {
        id: "s3",
        code: "S.3",
        name: "Comunidades afectadas",
        topics: [
          {
            id: "s3-1",
            name: "Derechos de comunidades locales",
            examples: ["Consulta previa e informada.", "Respeto a pueblos indígenas."],
          },
          {
            id: "s3-2",
            name: "Impacto social en comunidades",
            airisRecommended: true,
            examples: ["Desarrollo económico local.", "Gestión de impactos negativos."],
          },
          {
            id: "s3-3",
            name: "Participación comunitaria",
            examples: ["Programas de inversión social.", "Diálogo con stakeholders locales."],
          },
        ],
      },
      {
        id: "s4",
        code: "S.4",
        name: "Consumidores y usuarios finales",
        topics: [
          {
            id: "s4-1",
            name: "Seguridad de productos y servicios",
            airisRecommended: true,
            examples: ["Control de calidad.", "Gestión de recalls."],
          },
          {
            id: "s4-2",
            name: "Privacidad y protección de datos",
            airisRecommended: true,
            examples: ["Cumplimiento RGPD.", "Gestión de datos personales."],
          },
          {
            id: "s4-3",
            name: "Información al consumidor",
            examples: ["Etiquetado y transparencia.", "Marketing responsable."],
          },
          {
            id: "s4-4",
            name: "Accesibilidad",
            examples: ["Diseño inclusivo de productos.", "Acceso a servicios básicos."],
          },
        ],
      },
    ],
  },
  {
    id: "governance",
    name: "Gobernanza",
    icon: "governance",
    subcategories: [
      {
        id: "g1",
        code: "G.1",
        name: "Conducta empresarial",
        topics: [
          {
            id: "g1-1",
            name: "Ética empresarial",
            airisRecommended: true,
            examples: ["Código de conducta.", "Canal de denuncias."],
          },
          {
            id: "g1-2",
            name: "Anticorrupción y soborno",
            airisRecommended: true,
            examples: ["Políticas anticorrupción.", "Formación en cumplimiento."],
          },
          {
            id: "g1-3",
            name: "Competencia leal",
            examples: ["Prácticas antimonopolio.", "Transparencia en precios."],
          },
          {
            id: "g1-4",
            name: "Fiscalidad responsable",
            examples: ["Transparencia fiscal.", "Contribución tributaria por país."],
          },
          {
            id: "g1-5",
            name: "Gestión de riesgos",
            airisRecommended: true,
            examples: ["Identificación y mitigación de riesgos.", "Controles internos."],
          },
          {
            id: "g1-6",
            name: "Protección de denunciantes",
            examples: ["Mecanismos de protección.", "Investigación de denuncias."],
          },
          {
            id: "g1-7",
            name: "Bienestar animal",
            examples: ["Políticas de bienestar animal.", "Alternativas a pruebas con animales."],
          },
          {
            id: "g1-8",
            name: "Compromiso político",
            examples: ["Lobbying responsable.", "Transparencia en donaciones políticas."],
          },
          {
            id: "g1-9",
            name: "Gestión de relaciones con proveedores",
            airisRecommended: true,
            examples: ["Evaluación ESG de proveedores.", "Plazos de pago responsables."],
          },
        ],
      },
    ],
  },
]

export const ESG_CATEGORIES = esgTopicsData

export const SUPPORTED_LOCALES = ['es', 'en']
export function normalizeLocale(value) { return value === 'en' ? 'en' : 'es' }
export function localizedText(value, locale = 'es') {
  return value?.[normalizeLocale(locale)] || ''
}
export const localeMetadata = {
  es: { title: 'Airis - Preparación NEIS asistida', description: 'Asistente para preparar informes NEIS 2023 con materialidad, datos normativos y evidencias organizadas.' },
  en: { title: 'Airis - Assisted ESRS preparation', description: 'Prepare ESRS 2023 reports with materiality, disclosure data and organised evidence.' },
}

export function localized(value) {
  return value?.es || value?.en || ""
}

export function firstOpenStepKey(guide) {
  const firstStepKey = guide?.sections?.[0]?.steps?.[0]?.key

  return firstStepKey ? [firstStepKey] : []
}

export function templateDownloadPath(templateKey) {
  return `/double-materiality-guide/templates/${templateKey}.csv`
}

export function canContinueFromGuideState({ guide, loadingInitial, errorMessage }) {
  return Boolean(guide) && !loadingInitial && !errorMessage
}

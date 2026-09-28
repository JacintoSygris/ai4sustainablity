export async function runLaravelLogout({ refreshSession, postLogout }) {
  let csrfToken

  try {
    const session = await refreshSession()
    csrfToken = session?.csrfToken
  } catch (error) {
    if (error?.status === 401) return { outcome: "signed_out" }
    return { outcome: "retryable_error", stage: "session" }
  }

  try {
    const response = await postLogout(csrfToken)
    if (response?.ok || response?.status === 401) return { outcome: "signed_out" }
    return { outcome: "retryable_error", stage: "logout" }
  } catch {
    return { outcome: "retryable_error", stage: "logout" }
  }
}

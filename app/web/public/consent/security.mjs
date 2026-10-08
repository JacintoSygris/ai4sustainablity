const loaders = new WeakMap()
function load(win) {
  if (win.turnstile) return Promise.resolve(win.turnstile)
  if (!loaders.has(win)) {
    const pending = new Promise((resolve, reject) => {
      const script = win.document.createElement('script')
      script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
      script.async = true
      script.onload = () => win.turnstile ? resolve(win.turnstile) : reject(Error('Security unavailable'))
      script.onerror = () => { script.remove(); reject(Error('Security unavailable')) }
      win.document.head.append(script)
    }).catch(error => { loaders.delete(win); throw error })
    loaders.set(win, pending)
  }
  return loaders.get(win)
}

/** Mounting creates only local UI. Only the explicit button click loads the third party. */
export function mountSecurityCheck(host, { enabled, siteKey, action }, win = window) {
  if (enabled !== true || typeof siteKey !== 'string' || siteKey.trim() === '') return () => {}
  const doc = host.ownerDocument
  let en = doc.documentElement.lang.startsWith('en')
  const info = doc.createElement('p')
  info.textContent = en
    ? 'Start Cloudflare Turnstile to verify registration security. This contacts Cloudflare and sends technical information, including your IP address. Optional preferences are not required.'
    : 'Inicia Cloudflare Turnstile para verificar la seguridad del alta. Se contactará con Cloudflare y se transmitirán datos técnicos, incluida tu dirección IP. No necesitas aceptar preferencias opcionales.'
  const link = doc.createElement('a'); link.href = 'https://www.cloudflare.com/privacypolicy/'
  link.textContent = en ? 'Cloudflare privacy' : 'Privacidad de Cloudflare'; link.className = 'underline'
  const button = doc.createElement('button'); button.type = 'button'; button.className = 'airis-consent-button'
  button.textContent = en ? 'Start security check' : 'Iniciar comprobación de seguridad'
  const target = doc.createElement('div')
  const error = doc.createElement('p'); error.setAttribute('role', 'alert'); error.hidden = true
  error.textContent = en ? 'Security check unavailable. Try again.' : 'La comprobación no está disponible. Inténtalo de nuevo.'
  let active = true, widget = null, sdk = null
  async function start() {
    if (button.disabled || !active) return
    button.disabled = true; error.hidden = true
    try {
      sdk = await load(win)
      if (!active) return
      widget = sdk.render(target, { sitekey: siteKey, action, theme: 'auto', language: en ? 'en' : 'es' })
      button.hidden = true
    } catch { if (active) { error.hidden = false; button.disabled = false } }
  }
  button.addEventListener('click', start)
  host.append(info, link, button, target, error)
  const Observer = doc.defaultView?.MutationObserver
  const observer = Observer ? new Observer(() => {
    const nextEn = doc.documentElement.lang.startsWith('en')
    if (nextEn === en) return
    en = nextEn
    info.textContent = en
    ? 'Start Cloudflare Turnstile to verify registration security. This contacts Cloudflare and sends technical information, including your IP address. Optional preferences are not required.'
    : 'Inicia Cloudflare Turnstile para verificar la seguridad del alta. Se contactará con Cloudflare y se transmitirán datos técnicos, incluida tu dirección IP. No necesitas aceptar preferencias opcionales.'
    link.textContent = en ? 'Cloudflare privacy' : 'Privacidad de Cloudflare'
    button.textContent = en ? 'Start security check' : 'Iniciar comprobación de seguridad'
    error.textContent = en ? 'Security check unavailable. Try again.' : 'La comprobación no está disponible. Inténtalo de nuevo.'
    if (active && widget !== null && sdk) {
      // Removing the old challenge also invalidates its response. Surrounding form inputs are retained.
      sdk.remove(widget); widget = null
      try { widget = sdk.render(target, { sitekey: siteKey, action, theme: 'auto', language: en ? 'en' : 'es' }) }
      catch { error.hidden = false; button.hidden = false; button.disabled = false }
    }
  }) : null
  observer?.observe(doc.documentElement, { attributes: true, attributeFilter: ['lang'] })
  return () => {
    observer?.disconnect()
    active = false; button.removeEventListener('click', start)
    if (widget !== null) sdk?.remove(widget)
    for (const node of [info, link, button, target, error]) node.remove()
  }
}

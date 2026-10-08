import { ALL, NONE, PURPOSES } from './core.mjs'

const copy = {
  es: {
    title: 'Cookies y almacenamiento local',
    intro: 'Usamos cookies necesarias para sesión y seguridad; no utilizamos analítica ni publicidad. Puedes rechazar las opcionales y seguir usando el servicio.',
    summary: 'Opcionales: recordar avisos y barra lateral, guardar borradores en este navegador y recibir actualizaciones en tiempo real mediante Pusher. Puedes retirarlas desde el pie de página.',
    necessary: 'Necesarias: siempre activas para sesión, seguridad y guardar datos en el servidor.',
    preferences: 'Preferencias: recordar avisos ocultados y la barra lateral. Al rechazarlas, esas preferencias solo duran en la página abierta.',
    recovery: 'Recuperación local: guardar borradores de los pasos 4 y 5 en este navegador. Sin ella, recargar puede perder cambios aún no guardados. Retirarla borra esas copias; conserva lo que estás editando en memoria y los datos guardados en el servidor.',
    realtime: 'Tiempo real: recibir actualizaciones mediante Pusher, si está disponible. Sin esta opción puedes guardar normalmente y actualizar la página para consultar el estado.',
    duration: 'Tu elección dura 180 días, sin renovarse por visitar. Puedes cambiarla o retirar opcionales aquí en cualquier momento.',
    accept: 'Aceptar opcionales', reject: 'Rechazar opcionales', configure: 'Configurar', save: 'Guardar selección', close: 'Cerrar',
    reopen: 'Configurar consentimiento', withdraw: 'Retirar opcionales', cookies: 'Política de cookies', privacy: 'Privacidad',
    failure: 'No se han podido guardar o borrar algunos datos locales. Puede que queden copias sin borrar. Tus cambios en memoria se conservan. Revisa los permisos del navegador y vuelve a guardar tu selección.',
  },
  en: {
    title: 'Cookies and local storage',
    intro: 'Necessary cookies for sessions and security. No analytics or advertising. You can reject optional technologies and still use the service.',
    summary: 'Optional: remember notices and the sidebar, keep drafts in this browser and receive real-time updates through Pusher. You can withdraw consent from the page footer.',
    necessary: 'Necessary: always active for sessions, security and saving data on the server.',
    preferences: 'Preferences: remember dismissed notices and the sidebar. If rejected, these preferences only last on the open page.',
    recovery: 'Local recovery: keep step 4 and 5 drafts in this browser. Without it, reloading may lose unsaved changes. Withdrawal deletes these copies; your current in-memory edits and data saved on the server remain.',
    realtime: 'Real-time updates: receive updates through Pusher, if available. Without this option you can save normally and refresh the page to check status.',
    duration: 'Your choice lasts 180 days and is not renewed by visits. You can change it or withdraw optional consent here at any time.',
    accept: 'Accept optional', reject: 'Reject optional', configure: 'Configure', save: 'Save selection', close: 'Close',
    reopen: 'Consent settings', withdraw: 'Withdraw optional', cookies: 'Cookie policy', privacy: 'Privacy',
    failure: 'Some local data could not be saved or deleted. Undeleted copies may remain. Your in-memory edits remain. Check your browser storage permissions and save your selection again.',
  },
}
const mounts = new WeakMap()

export function mountConsent(host, api) {
  if (mounts.has(host)) return mounts.get(host)
  const doc = host.ownerDocument
  let t = copy[doc.documentElement.lang.startsWith('en') ? 'en' : 'es']
  const localizedNodes = []
  const el = (tag, text, className) => {
    const node = doc.createElement(tag)
    if (text) {
      node.textContent = text
      const key = Object.keys(t).find(key => t[key] === text)
      if (key) localizedNodes.push([node, key])
    }
    if (className) node.className = className
    return node
  }
  const button = (text, fn) => {
    const node = el('button', text, 'airis-consent-button')
    node.type = 'button'; node.addEventListener('click', fn); return node
  }
  const links = () => {
    const row = el('p', '', 'airis-consent-links')
    for (const [href, label] of [['/cookies', t.cookies], ['/privacy', t.privacy]]) {
      const a = el('a', label); a.href = href; row.append(a)
    }
    return row
  }
  const shell = el('div', '', 'airis-consent')
  const notice = el('section', '', 'airis-consent-notice')
  notice.setAttribute('aria-label', t.title)
  const error = el('p', t.failure, 'airis-consent-error'); error.setAttribute('role', 'alert'); error.hidden = true
  const modalError = el('p', t.failure, 'airis-consent-error'); modalError.setAttribute('role', 'alert'); modalError.hidden = true
  const persistent = el('div', '', 'airis-consent-controls'); persistent.setAttribute('aria-label', t.reopen)
  const dialog = el('dialog', '', 'airis-consent-dialog'); dialog.setAttribute('aria-label', t.reopen)
  let returnFocus = null
  function close() {
    if (dialog.open) dialog.close()
    let visible = returnFocus?.isConnected
    for (let node = returnFocus; node; node = node.parentNode) if (node.hidden) visible = false
    const target = visible ? returnFocus : (api.getState() ? reopen : configure)
    target.focus()
  }
  const inputs = {}
  function open() {
    if (dialog.open) return
    const state = api.getState()
    for (const purpose of PURPOSES) inputs[purpose].checked = state?.purposes[purpose] === true
    returnFocus = doc.activeElement
    dialog.showModal()
    modalContent.scrollTop = 0
    inputs.preferences.focus()
  }
  function choose(purposes, action) {
    const saved = api.setChoice(purposes, action)
    render(api.getState())
    if (saved && !api.getStorageError() && dialog.open) close()
  }
  const accept = button(t.accept, () => choose(ALL, 'accept'))
  const reject = button(t.reject, () => choose(NONE, 'reject'))
  const configure = button(t.configure, open)
  const reopen = button(t.reopen, open)
  const withdraw = button(t.withdraw, () => choose(NONE, 'withdraw'))
  const actions = el('div', '', 'airis-consent-actions'); actions.append(accept, reject, configure)
  // Only the explanation can scroll; all three decisions remain in view.
  const content = el('div', '', 'airis-consent-content')
  content.append(el('h2', t.title), el('p', t.intro), el('p', t.summary), links())
  notice.append(error, content, actions)
  persistent.append(reopen, withdraw)
  const modalContent = el('div', '', 'airis-consent-content')
  modalContent.append(el('p', t.necessary))
  dialog.append(el('h2', t.reopen), modalError, modalContent)
  for (const purpose of PURPOSES) {
    const label = el('label', '', 'airis-consent-option')
    const input = el('input'); input.type = 'checkbox'; input.name = purpose; input.checked = false
    inputs[purpose] = input
    label.append(input, el('span', t[purpose])); modalContent.append(label)
  }
  const modalActions = el('div', '', 'airis-consent-actions')
  modalActions.append(button(t.save, () => choose(Object.fromEntries(PURPOSES.map(p => [p, inputs[p].checked])), 'save')), button(t.close, close))
  modalContent.append(el('p', t.duration), links())
  dialog.append(modalActions)
  dialog.addEventListener('cancel', event => { event.preventDefault(); close() })
  dialog.addEventListener('keydown', event => {
    if (event.key === 'Escape') { event.preventDefault(); close() }
    if (event.key !== 'Tab') return
    const controls = [...dialog.querySelectorAll('button, input, a[href]')]
    const first = controls[0], last = controls.at(-1)
    if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last.focus() }
    else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus() }
  })
  // Native modal keeps outside content inert; expose persistence errors inside too.
  function render(state) {
    const failed = api.getStorageError()
    error.hidden = !failed
    modalError.hidden = !failed
    notice.hidden = state !== null && !failed
    persistent.hidden = state === null
  }
  shell.append(notice, persistent, dialog); host.append(shell)
  render(api.getState())
  const off = api.subscribe(render), offOpen = api.onReopen(open)
  const Observer = doc.defaultView?.MutationObserver
  const observer = Observer ? new Observer(() => {
    t = copy[doc.documentElement.lang.startsWith('en') ? 'en' : 'es']
    for (const [node, key] of localizedNodes) node.textContent = t[key]
    notice.setAttribute('aria-label', t.title)
    persistent.setAttribute('aria-label', t.reopen)
    dialog.setAttribute('aria-label', t.reopen)
  }) : null
  observer?.observe(doc.documentElement, { attributes: true, attributeFilter: ['lang'] })
  const dispose = () => { observer?.disconnect(); if (dialog.open) close(); off(); offOpen(); shell.remove(); mounts.delete(host) }
  mounts.set(host, dispose)
  return dispose
}

export const metadata = {
  title: "Cookies y almacenamiento local - Airis",
  description: "Tecnologías necesarias, preferencias opcionales y cómo retirar tu elección en Airis.",
}

export default function CookiesPage() {
  return (
    <article className="space-y-8">
      <header className="space-y-2">
        <h1 className="text-3xl font-bold">Cookies y almacenamiento local</h1>
        <p>Versión: 26/09/2026 · Elección válida durante 180 días, sin renovación por visitas.</p>
      </header>
      <section className="space-y-3">
        <h2 className="text-xl font-semibold">Responsable y contacto</h2>
        <p>CAMBRIDGE BUSINESS INITIATIVES, S.L. (Sygris), NIF B85512895, C/Teide 4, San Sebastián de los Reyes, Madrid.
          Teléfono: +34 91 623 73 84. Contacto: <a className="underline" href="mailto:dpo@sygris.com">dpo@sygris.com</a>.
          Para ejercer derechos: <a className="underline" href="mailto:dpo@cbiconsulting.es">dpo@cbiconsulting.es</a>.</p>
      </section>
      <section className="space-y-3">
        <h2 className="text-xl font-semibold">Tu elección</h2>
        <p>Puedes aceptar, rechazar o configurar las tres finalidades opcionales. Rechazar no impide acceder, editar ni guardar
          en el servidor. El control permanente «Configurar consentimiento» permite cambiar la selección; «Retirar opcionales»
          las desactiva en un gesto. Cerrar la configuración no acepta nada. Ante una elección inválida, caducada o un cambio
          sustancial de esta política, las opciones se desactivan y se solicita una nueva elección.</p>
        <p>La retirada intenta eliminar solo las copias locales y preferencias descritas abajo. Mantiene la edición en memoria en la
          página abierta y los datos del servidor. Si el navegador impide borrar alguna copia, mostramos un aviso y no la reutilizamos
          al volver a aceptar; puede permanecer físicamente hasta que se restablezca el permiso o borres los datos del sitio desde
          la configuración del navegador. Cerrar sesión no elimina necesariamente los borradores: retira las opciones antes de
          abandonar un dispositivo compartido.</p>
      </section>
      <section className="space-y-3">
        <h2 className="text-xl font-semibold">Inventario de esta versión</h2>
        <dl className="space-y-4 break-words">
          <div><dt className="font-semibold">Necesarias · sesión y protección de solicitudes · propias, Sygris</dt>
            <dd>Cookie de sesión con nombre configurable (por defecto el nombre de aplicación normalizado seguido de «-session»)
              y <code>XSRF-TOKEN</code>. Mantienen el acceso y protegen formularios/API. El código establece por defecto
              120 minutos de vida de sesión y no exige caducidad al cerrar el navegador; el host puede configurar otros valores.
              No se borran por rechazar opcionales. La autenticación normal y social no activa «recordarme»; se retira la cookie
              heredada <code>remember_web_…</code> en las solicitudes web sin eliminar la sesión corriente.</dd></div>
          <div><dt className="font-semibold">Necesaria · elección del navegador · propia, Sygris</dt>
            <dd><code>airis-consent-v1</code> en localStorage: versión, finalidades, fecha, vencimiento, acción y
              una revisión aleatoria nueva por elección guardada para distinguir cambios entre pestañas.
              Válida 180 días desde tu acción, sin identificador publicitario ni historial centralizado de identidad.
              El antiguo <code>airis-cookie-consent</code> solo era un acuse y no autoriza ninguna finalidad.</dd></div>
          <div><dt className="font-semibold">Opcional · preferencias · propias, Sygris</dt>
            <dd><code>wizard_expectations_dismissed</code> y <code>p9_intro_dismissed</code> en localStorage recuerdan avisos ocultados.
              La cookie <code>sidebar_state</code>, cuando se usa la barra lateral, dura hasta 7 días. Las preferencias se eliminan
              al retirar, rechazar o caducar la autorización (como máximo 180 días, con limpieza al volver a abrir la aplicación).
              Sin permiso, puedes ocultar avisos y cambiar la barra en memoria, pero no se recuerdan entre visitas.</dd></div>
          <div><dt className="font-semibold">Opcional · recuperación local · propia, Sygris</dt>
            <dd><code>p8_guided_draft_&lt;id&gt;</code>, <code>p8_guided_draft_&lt;id&gt;_conflict</code> y <code>p9_drafts_&lt;id&gt;</code>
              conservan copias de decisiones y respuestas no guardadas o en conflicto de los pasos 4 y 5. Se limpian al guardar
              correctamente o descartar según el flujo, y al retirar/rechazar/caducar el permiso. No tienen duración autónoma de
              cookie: están limitadas por la autorización de 180 días y la limpieza al abrir la aplicación. El guardado en servidor
              sigue funcionando sin estas copias; cerrar o recargar puede perder ediciones aún no guardadas.</dd></div>
          <div><dt className="font-semibold">Opcional · tiempo real · Pusher, si está disponible</dt>
            <dd>Solo en superficies autenticadas pertinentes y con servicio configurado. Se contacta con el proveedor para recibir
              cambios de estado. El SDK puede usar <code>pusherTransportTLS</code> y <code>pusherTransportNonTLS</code> como caché
              de transporte. No se les atribuye una duración fija del proveedor: se eliminan al retirar/rechazar/caducar el permiso,
              después de desconectar. Sin esta opción, guarda normalmente y actualiza la página para consultar cambios.
              Consulta la <a className="underline" href="https://pusher.com/legal/privacy-policy/">privacidad de Pusher</a>.</dd></div>
        </dl>
      </section>
      <section className="space-y-3">
        <h2 className="text-xl font-semibold">Servicios que solicitas expresamente</h2>
        <p>Si el alta está habilitada y configurada, «Iniciar comprobación de seguridad» carga Cloudflare Turnstile y transmite datos
          técnicos, incluida tu IP, para verificar el registro. Es independiente de las preferencias opcionales. No se carga por
          visitar login/perfil ni con el registro deshabilitado. Su tecnología y conservación dependen del proveedor y configuración;
          no afirmamos que esté libre de cookies. Consulta la <a className="underline" href="https://www.cloudflare.com/privacypolicy/">privacidad de Cloudflare</a>.</p>
        <p>Google y Microsoft reciben la navegación cuando pulsas su enlace de acceso, sin SDK precargado. Sus páginas pueden usar
          sus propias tecnologías y plazos: <a className="underline" href="https://policies.google.com/privacy">Google</a> y{' '}
          <a className="underline" href="https://privacy.microsoft.com/privacystatement">Microsoft</a>.</p>
        <p>Esta versión no utiliza analítica ni publicidad. Las fuentes de Next se sirven localmente y las páginas de cuenta usan
          fuentes del sistema. Más información sobre tus datos y derechos en <a className="underline" href="/privacy">Privacidad</a>.</p>
      </section>
    </article>
  )
}

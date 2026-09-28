export const metadata = {
  title: "Política de privacidad - Airis",
  description: "Cómo Airis trata los datos de tu cuenta, tus respuestas y los documentos que subes.",
}

export default function PrivacyPage() {
  return (
    <article className="space-y-8">
      <header className="space-y-2">
        <h1 className="text-3xl font-bold text-foreground">Política de privacidad</h1>
        <p className="text-sm text-muted-foreground">Versión: 26/09/2026</p>
      </header>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Quién es responsable de tus datos</h2>
        <p className="text-muted-foreground">
          El responsable del tratamiento es CAMBRIDGE BUSINESS INITIATIVES, S.L. (Sygris), NIF B85512895, con domicilio
          en C/Teide 4, San Sebastián de los Reyes, Madrid. Contacto del titular: <a href="mailto:dpo@sygris.com" className="text-primary hover:underline">dpo@sygris.com</a>. Teléfono: +34 91 623 73 84.
        </p>
        <p className="rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-foreground">
          Para ejercer tus derechos, escríbenos a <a href="mailto:dpo@cbiconsulting.es" className="text-primary hover:underline">dpo@cbiconsulting.es</a>. Consulta también el <a href="https://sygris.com/legal/aviso-legal/" className="text-primary hover:underline">aviso legal de Sygris</a> y su <a href="https://sygris.com/legal/politica-privacidad/" className="text-primary hover:underline">política de privacidad</a>.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Qué datos recogemos</h2>
        <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
          <li>
            <span className="font-medium text-foreground">Datos de tu cuenta:</span> nombre, correo electrónico y la
            contraseña (que guardamos en un formato no reversible, nunca en texto legible).
          </li>
          <li>
            <span className="font-medium text-foreground">Respuestas de caracterización:</span> la información que
            introduces sobre tu empresa, tu actividad y tus decisiones a lo largo del asistente.
          </li>
          <li>
            <span className="font-medium text-foreground">Documentos que subes:</span> los archivos de tu empresa que
            aportas como evidencia para analizar tu informe.
          </li>
          <li>
            <span className="font-medium text-foreground">Datos técnicos mínimos:</span> los necesarios para mantener tu
            sesión iniciada y proteger el servicio frente a usos abusivos.
          </li>
        </ul>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Cómo tratamos los documentos que subes</h2>
        <p className="text-muted-foreground">
          Los documentos que subes se almacenan de forma privada y solo son accesibles desde tu cuenta. Su análisis se
          realiza únicamente en el propio servidor de la plataforma. No enviamos tus documentos ni su contenido a
          servicios de inteligencia artificial externos para su procesamiento. Esta función de análisis está disponible
          únicamente cuando la plataforma la habilita.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Para qué usamos tus datos</h2>
        <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
          <li>Prestarte el servicio de preparación de informes y guardar tu progreso.</li>
          <li>Analizar los documentos que aportas para ayudarte a organizar tus evidencias.</li>
          <li>Gestionar tu cuenta, tu acceso y la seguridad del servicio.</li>
        </ul>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Cuánto tiempo conservamos tus datos</h2>
        <p className="text-muted-foreground">
          Conservamos tus respuestas y tus documentos mientras mantengas tu cuenta activa y no los elimines. Si solicitas
          borrar un documento mientras se está extrayendo, la solicitud se rechaza temporalmente y tendrás que
          volver a intentarlo cuando termine la extracción. Cuando una baja se acepta, la eliminación física puede quedar
          pendiente de completar y se gestiona hasta finalizar. Las trazas técnicas pueden conservar el identificador, la
          fecha de borrado y los temas tratados, pero no las citas ni las páginas; se conserva únicamente lo necesario,
          salvo aquello que deba mantenerse por una obligación legal.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Tus derechos</h2>
        <p className="text-muted-foreground">
          De acuerdo con el Reglamento General de Protección de Datos (RGPD), puedes ejercer en cualquier momento los
          siguientes derechos sobre tus datos personales:
        </p>
        <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
          <li>
            <span className="font-medium text-foreground">Acceso:</span> saber qué datos tuyos tratamos y obtener una
            copia.
          </li>
          <li>
            <span className="font-medium text-foreground">Rectificación:</span> corregir los datos inexactos o
            incompletos.
          </li>
          <li>
            <span className="font-medium text-foreground">Supresión:</span> pedir que borremos tus datos cuando ya no
            sean necesarios.
          </li>
          <li>
            <span className="font-medium text-foreground">Portabilidad:</span> recibir tus datos en un formato
            estructurado y de uso común para llevarlos a otro servicio.
          </li>
          <li>
            <span className="font-medium text-foreground">Oposición y limitación:</span> oponerte a ciertos tratamientos
            o pedir que se limiten.
          </li>
        </ul>
        <p className="text-muted-foreground">
          Para ejercer estos derechos, escríbenos a <a href="mailto:dpo@cbiconsulting.es" className="text-primary hover:underline">dpo@cbiconsulting.es</a> o contacta con el titular en <a href="mailto:dpo@sygris.com" className="text-primary hover:underline">dpo@sygris.com</a>. También puedes presentar una reclamación ante la Agencia Española de Protección de Datos
          (<a href="https://www.aepd.es/" className="text-primary hover:underline">www.aepd.es</a>).
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Cookies</h2>
        <p className="text-muted-foreground">
          Usamos cookies necesarias para sesión y seguridad. Las preferencias de interfaz, la recuperación local de los
          borradores de los pasos 4 y 5 y las actualizaciones en tiempo real (si están disponibles) requieren tu elección opcional.
          Puedes rechazarlas y seguir editando y guardando en el servidor. Tu elección dura 180 días sin renovarse por visitas.
          «Configurar consentimiento» permite cambiarla y «Retirar opcionales» intenta eliminar las copias locales y preferencias,
          manteniendo los datos del servidor y la edición en memoria de la página abierta. Si el borrado local puede fallar,
          se muestra un aviso; en dispositivos compartidos, borra también los datos del sitio desde el navegador.
          Cerrar sesión no borra necesariamente los borradores: retira opcionales y comprueba el aviso antes de dejar el dispositivo.
          El registro local describe la configuración del navegador,
          no un historial centralizado de identidad. No utilizamos analítica ni publicidad en esta versión. Google y Microsoft
          reciben la navegación al pulsar sus enlaces. Cuando el alta está habilitada y configurada, Turnstile se carga solo al
          pulsar «Iniciar comprobación de seguridad»; contacta con Cloudflare y transmite datos técnicos, incluida la IP.
          Consulta el inventario, duraciones y terceros en la <a href="/cookies" className="text-primary hover:underline">política de cookies</a>.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Cambios en esta política</h2>
        <p className="text-muted-foreground">
          Podemos actualizar esta política para reflejar cambios en el servicio o en la normativa aplicable. Publicaremos
          siempre la versión vigente en esta misma página.
        </p>
      </section>
    </article>
  )
}

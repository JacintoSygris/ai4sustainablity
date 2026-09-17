export const metadata = {
  title: "Política de privacidad - Airis",
  description: "Cómo Airis trata los datos de tu cuenta, tus respuestas y los documentos que subes.",
}

export default function PrivacyPage() {
  return (
    <article className="space-y-8">
      <div className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
        Borrador pendiente de revisión legal
      </div>

      <header className="space-y-2">
        <h1 className="text-3xl font-bold text-foreground">Política de privacidad</h1>
        <p className="text-sm text-muted-foreground">Última actualización: pendiente de publicación.</p>
      </header>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Quién es responsable de tus datos</h2>
        <p className="text-muted-foreground">
          El responsable del tratamiento de tus datos personales es el operador de esta plataforma.
        </p>
        <p className="rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-foreground">
          [PENDIENTE: datos del responsable] — razón social, dirección, identificación fiscal y correo de contacto para
          asuntos de privacidad.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Qué datos recogemos</h2>
        <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
          <li>
            <span className="font-medium text-foreground">Datos de tu cuenta:</span> nombre, correo electrónico y la
            contraseña (que guardamos siempre cifrada, nunca en texto legible).
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
          servicios de inteligencia artificial externos ni a terceros para su procesamiento.
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
          Conservamos tus respuestas y tus documentos mientras mantengas tu cuenta activa y no los elimines. Cuando
          borras un documento, se elimina por completo de nuestros sistemas. Cuando solicitas la eliminación de tu
          cuenta, borramos de forma definitiva tus datos personales, tus respuestas y tus documentos, salvo aquello que
          debamos conservar por una obligación legal.
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
          Para ejercer estos derechos, escríbenos al correo de contacto indicado arriba. También tienes derecho a
          presentar una reclamación ante la autoridad de protección de datos competente.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Cookies</h2>
        <p className="text-muted-foreground">
          Usamos solo las cookies necesarias para iniciar sesión y mantener tu sesión segura. No utilizamos cookies de
          seguimiento publicitario ni de terceros. Tienes más detalle en el aviso de cookies del propio sitio.
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

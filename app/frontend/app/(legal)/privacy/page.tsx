export const metadata = {
  title: "Política de privacidad - Airis",
  description: "Cómo el operador debe explicar el tratamiento de cuenta, respuestas y referencias.",
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
            contraseña (guardada mediante un resumen criptográfico, no en texto legible).
          </li>
          <li>
            <span className="font-medium text-foreground">Respuestas de caracterización:</span> la información que
            introduces sobre tu empresa, tu actividad y tus decisiones a lo largo del asistente.
          </li>
          <li>
            <span className="font-medium text-foreground">Referencias y documentos, si el operador los activa:</span>{" "}
            archivos o referencias que aportas como apoyo de trabajo.
          </li>
          <li>
            <span className="font-medium text-foreground">Datos técnicos mínimos:</span> los necesarios para mantener tu
            sesión iniciada y proteger el servicio frente a usos abusivos.
          </li>
        </ul>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Cómo deben tratarse los documentos</h2>
        <p className="text-muted-foreground">
          El perfil público incluido desactiva la carga documental y no incorpora el servicio compatible de extracción.
          Si un operador activa una función documental propia, debe explicar qué archivos almacena, qué extracciones
          conserva, qué proveedores intervienen y qué registros pueden permanecer.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Para qué usamos tus datos</h2>
        <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
          <li>Prestarte el servicio de organización de información y guardar tu progreso cuando el entorno lo permita.</li>
          <li>Ordenar referencias de evidencia y respuestas registradas.</li>
          <li>Gestionar tu cuenta, tu acceso y la seguridad del servicio.</li>
        </ul>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Cuánto tiempo conservamos tus datos</h2>
        <p className="text-muted-foreground">
          La conservación depende del operador y del entorno. En la evaluación local incluida, la base SQLite se recrea
          al arrancar el componente web y no debe usarse para conservar información. En otros entornos, pueden existir
          registros, metadatos, copias de seguridad o obligaciones legales que impidan prometer un borrado absoluto sin
          una política concreta del operador.
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
          La plataforma usa cookies necesarias para iniciar sesión y mantener la sesión. Cualquier uso adicional debe
          describirse en el aviso del operador.
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

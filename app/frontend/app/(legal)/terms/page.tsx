export const metadata = {
  title: "Términos del servicio - Airis",
  description: "Condiciones de uso de la plataforma de organización de información ESRS.",
}

export default function TermsPage() {
  return (
    <article className="space-y-8">
      <div className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
        Borrador pendiente de revisión legal
      </div>

      <header className="space-y-2">
        <h1 className="text-3xl font-bold text-foreground">Términos del servicio</h1>
        <p className="text-sm text-muted-foreground">Última actualización: pendiente de publicación.</p>
      </header>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Objeto del servicio</h2>
        <p className="text-muted-foreground">
          Esta plataforma te ayuda a organizar información de sostenibilidad relacionada con ESRS 2023. El servicio
          acompaña la descripción de la organización, la revisión de temas propuestos, el registro de decisiones humanas
          y la recogida de información disponible o pendiente.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Qué NO es este servicio</h2>
        <p className="text-muted-foreground">
          El resultado que obtienes es material de trabajo y un resumen de preparación. Es importante que tengas claro
          sus límites:
        </p>
        <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
          <li>No es una presentación oficial de tu informe ante ningún organismo.</li>
          <li>No es un servicio de aseguramiento ni de verificación independiente.</li>
          <li>No sustituye el asesoramiento profesional legal, contable o de auditoría.</li>
          <li>No garantiza por sí solo cumplimiento, precisión, suficiencia metodológica ni aceptación regulatoria.</li>
        </ul>
        <p className="text-muted-foreground">
          La responsabilidad final sobre el contenido, la exactitud, la materialidad y cualquier uso de las salidas es
          siempre de tu organización.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Tu cuenta</h2>
        <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
          <li>Eres responsable de mantener la confidencialidad de tus credenciales de acceso.</li>
          <li>La información que introduzcas debe ser veraz y estar actualizada.</li>
          <li>Debes contar con permiso para registrar datos, referencias o documentos si el operador activa esa función.</li>
        </ul>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Tus contenidos</h2>
        <p className="text-muted-foreground">
          Los datos y referencias que introduces siguen siendo tuyos o de tu organización. Autorizas su tratamiento para
          prestar el servicio según la política de privacidad aplicable. La eliminación y conservación dependen del
          entorno y de las obligaciones del operador.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Uso aceptable</h2>
        <p className="text-muted-foreground">
          No puedes usar la plataforma para actividades ilícitas, para intentar acceder a cuentas o datos de otras
          personas, ni para comprometer la seguridad o la disponibilidad del servicio.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Disponibilidad y cambios</h2>
        <p className="text-muted-foreground">
          Trabajamos para mantener el servicio disponible, pero puede haber interrupciones por mantenimiento o causas
          técnicas. Podemos actualizar estas condiciones; publicaremos siempre la versión vigente en esta página.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Contacto</h2>
        <p className="rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-foreground">
          [PENDIENTE: datos del responsable] — razón social, dirección y correo de contacto del operador del servicio.
        </p>
      </section>
    </article>
  )
}

export const metadata = {
  title: "Términos del servicio - Airis",
  description: "Condiciones de uso de la plataforma de preparación de informes ESRS.",
}

export default function TermsPage() {
  return (
    <article className="space-y-8">
      <header className="space-y-2">
        <h1 className="text-3xl font-bold text-foreground">Términos del servicio</h1>
        <p className="text-sm text-muted-foreground">Versión: 26/09/2026</p>
      </header>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Objeto del servicio</h2>
        <p className="text-muted-foreground">
          Esta plataforma te ayuda a preparar y organizar la información de sostenibilidad de tu empresa siguiendo los
          estándares europeos ESRS 2023. El servicio te acompaña en la materialidad, en la selección de indicadores y en
          la recopilación de evidencias.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Qué NO es este servicio</h2>
        <p className="text-muted-foreground">
          El resultado que obtienes es un borrador de trabajo y una organización de tus evidencias. Es importante que
          tengas claro sus límites:
        </p>
        <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
          <li>No es una presentación oficial de tu informe ante ningún organismo.</li>
          <li>No es un servicio de aseguramiento ni de verificación independiente.</li>
          <li>No sustituye el asesoramiento profesional legal, contable o de auditoría.</li>
          <li>No garantiza por sí solo el cumplimiento de ninguna obligación normativa.</li>
        </ul>
        <p className="text-muted-foreground">
          La responsabilidad final sobre el contenido, la exactitud y la presentación de tu informe es siempre de tu
          empresa.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Tu cuenta</h2>
        <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
          <li>Eres responsable de mantener la confidencialidad de tus credenciales de acceso.</li>
          <li>La información que introduzcas debe ser veraz y estar actualizada.</li>
          <li>Debes contar con permiso para subir los documentos que aportes a la plataforma.</li>
        </ul>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Tus contenidos</h2>
        <p className="text-muted-foreground">
          Los documentos y datos que subes siguen siendo tuyos. Nos autorizas únicamente a tratarlos para prestarte el
          servicio, según se describe en la <a href="/privacy" className="text-primary hover:underline">política de privacidad</a>. Puedes solicitar la eliminación de tus documentos y tu cuenta conforme a esa política.
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
        <h2 className="text-xl font-semibold text-foreground">Titular y contacto</h2>
        <p className="text-muted-foreground">CAMBRIDGE BUSINESS INITIATIVES, S.L. (Sygris), NIF B85512895, C/Teide 4,
          San Sebastián de los Reyes, Madrid. Contacto: <a href="mailto:dpo@sygris.com" className="text-primary hover:underline">dpo@sygris.com</a>.
          Teléfono: +34 91 623 73 84.</p>
      </section>

    </article>
  )
}

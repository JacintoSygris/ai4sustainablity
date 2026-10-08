import { ui } from "@/lib/i18n/messages.mjs"
import { getLaravelServerLocale } from "@/lib/laravel-server"
export async function generateMetadata() {
  const locale = await getLaravelServerLocale()
  return {
  title: ui(locale, "Política de privacidad - Airis"),
  description: ui(locale, "Cómo Airis trata los datos de tu cuenta, tus respuestas y los documentos que subes."),
}
}

export default async function PrivacyPage() {
  const locale = await getLaravelServerLocale()
  const tr = (message: string) => ui(locale, message)

  return (
    <article className="space-y-8">
      <header className="space-y-2">
        <h1 className="text-3xl font-bold text-foreground">{tr("Política de privacidad")}</h1>
        <p className="text-sm text-muted-foreground">{tr("Versión: 26/09/2026")}</p>
      </header>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">{tr("Quién es responsable de tus datos")}</h2>
        <p className="text-muted-foreground">
          {" "}{tr("El responsable del tratamiento es CAMBRIDGE BUSINESS INITIATIVES, S.L. (Sygris), NIF B85512895, con domicilio en C/Teide 4, San Sebastián de los Reyes, Madrid. Contacto del titular:")}{" "}<a href="mailto:dpo@sygris.com" className="text-primary hover:underline">dpo@sygris.com</a>{tr(". Teléfono: +34 91 623 73 84.")}{" "}</p>
        <p className="rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-foreground">
          {" "}{tr("Para ejercer tus derechos, escríbenos a")}{" "}<a href="mailto:dpo@cbiconsulting.es" className="text-primary hover:underline">dpo@cbiconsulting.es</a>{tr(". Consulta también el")}{" "}<a href="https://sygris.com/legal/aviso-legal/" className="text-primary hover:underline">{tr("aviso legal de Sygris")}</a> {" "}{tr("y su")}{" "}<a href="https://sygris.com/legal/politica-privacidad/" className="text-primary hover:underline">{tr("política de privacidad")}</a>.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">{tr("Qué datos recogemos")}</h2>
        <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
          <li>
            <span className="font-medium text-foreground">{tr("Datos de tu cuenta:")}</span> {" "}{tr("nombre, correo electrónico y la contraseña (que guardamos en un formato no reversible, nunca en texto legible).")}{" "}</li>
          <li>
            <span className="font-medium text-foreground">{tr("Respuestas de caracterización:")}</span> {" "}{tr("la información que introduces sobre tu empresa, tu actividad y tus decisiones a lo largo del asistente.")}{" "}</li>
          <li>
            <span className="font-medium text-foreground">{tr("Documentos que subes:")}</span> {" "}{tr("los archivos de tu empresa que aportas como evidencia para analizar tu informe.")}{" "}</li>
          <li>
            <span className="font-medium text-foreground">{tr("Datos técnicos mínimos:")}</span> {" "}{tr("los necesarios para mantener tu sesión iniciada y proteger el servicio frente a usos abusivos.")}{" "}</li>
        </ul>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">{tr("Cómo tratamos los documentos que subes")}</h2>
        <p className="text-muted-foreground">
          {" "}{tr("Los documentos que subes se almacenan de forma privada y solo son accesibles desde tu cuenta. Su análisis se realiza únicamente en el propio servidor de la plataforma. No enviamos tus documentos ni su contenido a servicios de inteligencia artificial externos para su procesamiento. Esta función de análisis está disponible únicamente cuando la plataforma la habilita.")}{" "}</p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">{tr("Para qué usamos tus datos")}</h2>
        <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
          <li>{tr("Prestarte el servicio de preparación de informes y guardar tu progreso.")}</li>
          <li>{tr("Analizar los documentos que aportas para ayudarte a organizar tus evidencias.")}</li>
          <li>{tr("Gestionar tu cuenta, tu acceso y la seguridad del servicio.")}</li>
        </ul>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">{tr("Cuánto tiempo conservamos tus datos")}</h2>
        <p className="text-muted-foreground">
          {" "}{tr("Conservamos tus respuestas y tus documentos mientras mantengas tu cuenta activa y no los elimines. Si solicitas borrar un documento mientras se está extrayendo, la solicitud se rechaza temporalmente y tendrás que volver a intentarlo cuando termine la extracción. Cuando una baja se acepta, la eliminación física puede quedar pendiente de completar y se gestiona hasta finalizar. Las trazas técnicas pueden conservar el identificador, la fecha de borrado y los temas tratados, pero no las citas ni las páginas; se conserva únicamente lo necesario, salvo aquello que deba mantenerse por una obligación legal.")}{" "}</p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">{tr("Tus derechos")}</h2>
        <p className="text-muted-foreground">
          {" "}{tr("De acuerdo con el Reglamento General de Protección de Datos (RGPD), puedes ejercer en cualquier momento los siguientes derechos sobre tus datos personales:")}{" "}</p>
        <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
          <li>
            <span className="font-medium text-foreground">{tr("Acceso:")}</span> {" "}{tr("saber qué datos tuyos tratamos y obtener una copia.")}{" "}</li>
          <li>
            <span className="font-medium text-foreground">{tr("Rectificación:")}</span> {" "}{tr("corregir los datos inexactos o incompletos.")}{" "}</li>
          <li>
            <span className="font-medium text-foreground">{tr("Supresión:")}</span> {" "}{tr("pedir que borremos tus datos cuando ya no sean necesarios.")}{" "}</li>
          <li>
            <span className="font-medium text-foreground">{tr("Portabilidad:")}</span> {" "}{tr("recibir tus datos en un formato estructurado y de uso común para llevarlos a otro servicio.")}{" "}</li>
          <li>
            <span className="font-medium text-foreground">{tr("Oposición y limitación:")}</span> {" "}{tr("oponerte a ciertos tratamientos o pedir que se limiten.")}{" "}</li>
        </ul>
        <p className="text-muted-foreground">
          {" "}{tr("Para ejercer estos derechos, escríbenos a")}{" "}<a href="mailto:dpo@cbiconsulting.es" className="text-primary hover:underline">dpo@cbiconsulting.es</a> {" "}{tr("o contacta con el titular en")}{" "}<a href="mailto:dpo@sygris.com" className="text-primary hover:underline">dpo@sygris.com</a>{tr(". También puedes presentar una reclamación ante la Agencia Española de Protección de Datos (")}<a href="https://www.aepd.es/" className="text-primary hover:underline">www.aepd.es</a>).
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">Cookies</h2>
        <p className="text-muted-foreground">
          {" "}{tr("Usamos cookies necesarias para sesión y seguridad. Las preferencias de interfaz, la recuperación local de los borradores de los pasos 4 y 5 y las actualizaciones en tiempo real (si están disponibles) requieren tu elección opcional. Puedes rechazarlas y seguir editando y guardando en el servidor. Tu elección dura 180 días sin renovarse por visitas. «Configurar consentimiento» permite cambiarla y «Retirar opcionales» intenta eliminar las copias locales y preferencias, manteniendo los datos del servidor y la edición en memoria de la página abierta. Si el borrado local puede fallar, se muestra un aviso; en dispositivos compartidos, borra también los datos del sitio desde el navegador. Cerrar sesión no borra necesariamente los borradores: retira opcionales y comprueba el aviso antes de dejar el dispositivo. El registro local describe la configuración del navegador, no un historial centralizado de identidad. No utilizamos analítica ni publicidad en esta versión. Google y Microsoft reciben la navegación al pulsar sus enlaces. Cuando el alta está habilitada y configurada, Turnstile se carga solo al pulsar «Iniciar comprobación de seguridad»; contacta con Cloudflare y transmite datos técnicos, incluida la IP. Consulta el inventario, duraciones y terceros en la")}{" "}<a href="/cookies" className="text-primary hover:underline">{tr("política de cookies")}</a>.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold text-foreground">{tr("Cambios en esta política")}</h2>
        <p className="text-muted-foreground">
          {" "}{tr("Podemos actualizar esta política para reflejar cambios en el servicio o en la normativa aplicable. Publicaremos siempre la versión vigente en esta misma página.")}{" "}</p>
      </section>
    </article>
  )
}

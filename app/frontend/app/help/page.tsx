import Link from "next/link"
import { Mail, ShieldCheck } from "lucide-react"
import { Button } from "@/components/ui/button"

export default function HelpPage() {
  return (
    <main className="container mx-auto max-w-3xl px-4 py-12">
      <div className="space-y-6">
        <div>
          <h1 className="text-3xl font-semibold text-foreground">Ayuda</h1>
          <p className="mt-3 text-muted-foreground">
            Si tienes problemas para acceder o completar el flujo, revisa primero que hayas guardado cada paso antes de
            continuar. La sesión se gestiona desde Laravel y puede pedirte iniciar sesión de nuevo si caduca.
          </p>
        </div>

        <div className="grid gap-4 md:grid-cols-2">
          <section className="rounded-md border border-border p-4">
            <ShieldCheck className="h-5 w-5 text-primary" aria-hidden="true" />
            <h2 className="mt-3 text-lg font-medium text-foreground">Acceso y sesión</h2>
            <p className="mt-2 text-sm text-muted-foreground">
              Si aparece un aviso de sesión caducada, recarga la página e inicia sesión otra vez. No pierdas de vista
              el botón de cierre de sesión del menú superior.
            </p>
          </section>

          <section className="rounded-md border border-border p-4">
            <Mail className="h-5 w-5 text-primary" aria-hidden="true" />
            <h2 className="mt-3 text-lg font-medium text-foreground">Soporte</h2>
            <p className="mt-2 text-sm text-muted-foreground">
              Para esta release privada, contacta con el equipo responsable del despliegue si necesitas reactivar una
              cuenta o revisar datos de prueba.
            </p>
          </section>
        </div>

        <Button asChild>
          <Link href="/dashboard">Volver al panel</Link>
        </Button>
      </div>
    </main>
  )
}

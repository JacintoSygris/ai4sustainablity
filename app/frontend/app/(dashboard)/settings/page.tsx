import Link from "next/link"
import { redirect } from "next/navigation"
import { Button } from "@/components/ui/button"
import { getLaravelServerSession } from "@/lib/laravel-server"

export default async function SettingsPage() {
  const session = await getLaravelServerSession()

  if (!session) {
    redirect("/login")
  }

  return (
    <main className="container mx-auto max-w-3xl px-4 py-12">
      <div className="space-y-8">
        <div>
          <h1 className="text-3xl font-semibold text-foreground">Configuración</h1>
          <p className="mt-3 text-muted-foreground">
            Revisa la sesión activa y gestiona los cambios de perfil, contraseña o eliminación desde el formulario de
            cuenta protegido.
          </p>
        </div>

        <section className="rounded-md border border-border bg-card p-5">
          <h2 className="text-lg font-semibold text-foreground">Cuenta activa</h2>
          <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-muted-foreground">Nombre</dt>
              <dd className="mt-1 font-medium text-foreground">{session.user.name || "Sin nombre"}</dd>
            </div>
            <div>
              <dt className="text-muted-foreground">Email</dt>
              <dd className="mt-1 font-medium text-foreground">{session.user.email}</dd>
            </div>
          </dl>
        </section>

        <div className="flex flex-wrap gap-3">
          <Button asChild>
            <a href="/profile">Gestionar perfil y contraseña</a>
          </Button>
          <Button asChild variant="outline">
            <Link href="/dashboard">Volver al panel</Link>
          </Button>
        </div>
      </div>
    </main>
  )
}

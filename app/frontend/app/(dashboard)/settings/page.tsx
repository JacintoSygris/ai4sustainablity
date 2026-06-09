import Link from "next/link"
import { Button } from "@/components/ui/button"

export default function SettingsPage() {
  return (
    <main className="container mx-auto max-w-3xl px-4 py-12">
      <div className="space-y-6">
        <div>
          <h1 className="text-3xl font-semibold text-foreground">Configuración</h1>
          <p className="mt-3 text-muted-foreground">
            La gestión avanzada de cuenta no está activada en esta release privada. Usa esta página para confirmar que
            tu sesión está activa y vuelve al panel para continuar el flujo.
          </p>
        </div>

        <Button asChild>
          <Link href="/dashboard">Volver al panel</Link>
        </Button>
      </div>
    </main>
  )
}

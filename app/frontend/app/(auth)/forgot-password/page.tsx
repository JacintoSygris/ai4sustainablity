import Link from "next/link"
import { Button } from "@/components/ui/button"

export default function ForgotPasswordPage() {
  return (
    <div className="w-full max-w-md space-y-6 text-center">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Recuperar contraseña</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          La recuperación automática por email no está activada en esta release privada. Pide al equipo responsable que
          restablezca tu acceso.
        </p>
      </div>

      <Button asChild className="w-full">
        <Link href="/login">Volver a iniciar sesión</Link>
      </Button>
    </div>
  )
}

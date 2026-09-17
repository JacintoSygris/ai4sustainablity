import { redirect } from "next/navigation"
import { AuthHeader } from "@/components/auth/auth-header"
import { Footer } from "@/components/ui/footer"
import { VerifyEmailNotice } from "@/components/auth/verify-email-notice"
import { getLaravelServerSession } from "@/lib/laravel-server"

export const metadata = {
  title: "Confirma tu correo - Airis",
}

export default async function VerifyEmailPage() {
  const session = await getLaravelServerSession()

  if (!session) {
    redirect("/login")
  }

  // Already verified, or the platform does not enforce verification: nothing to do here.
  if (!session.require_email_verification || session.user.email_verified) {
    redirect("/dashboard")
  }

  return (
    <div className="flex min-h-screen flex-col">
      <AuthHeader />
      <main className="flex flex-1 items-center justify-center px-4 py-12">
        <VerifyEmailNotice email={session.user.email} />
      </main>
      <Footer />
    </div>
  )
}

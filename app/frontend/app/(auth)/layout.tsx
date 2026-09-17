import type React from "react"
import { redirect } from "next/navigation"
import { AuthHeader } from "@/components/auth/auth-header"
import { Footer } from "@/components/ui/footer"
import { getLaravelServerSession } from "@/lib/laravel-server"

export default async function AuthLayout({
  children,
}: {
  children: React.ReactNode
}) {
  const session = await getLaravelServerSession()

  if (session) {
    redirect("/dashboard")
  }

  return (
    <div className="flex min-h-screen flex-col">
      <AuthHeader />
      <main className="flex flex-1 items-center justify-center px-4 py-12">{children}</main>
      <Footer />
    </div>
  )
}

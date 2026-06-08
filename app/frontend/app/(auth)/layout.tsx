import type React from "react"
import { AuthHeader } from "@/components/auth/auth-header"
import { Footer } from "@/components/ui/footer"

export default function AuthLayout({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <div className="flex min-h-screen flex-col">
      <AuthHeader />
      <main className="flex flex-1 items-center justify-center px-4 py-12">{children}</main>
      <Footer />
    </div>
  )
}

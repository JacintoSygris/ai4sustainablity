import type React from "react"
import { Header } from "@/components/ui/header"
import { Footer } from "@/components/ui/footer"

export default function LegalLayout({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <div className="flex min-h-screen flex-col">
      <Header />
      <main className="flex-1">
        <div className="container mx-auto max-w-3xl px-4 py-12">{children}</div>
      </main>
      <Footer />
    </div>
  )
}

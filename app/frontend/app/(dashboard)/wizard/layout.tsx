import type React from "react"
import { redirect } from "next/navigation"
import { getLaravelServerSession } from "@/lib/laravel-server"

export default async function WizardLayout({
  children,
}: {
  children: React.ReactNode
}) {
  const session = await getLaravelServerSession()

  if (!session) {
    redirect("/login")
  }

  return <>{children}</>
}

import type React from "react"
import { redirect } from "next/navigation"
import { DashboardHeader } from "@/components/dashboard/dashboard-header"
import { getLaravelServerSession } from "@/lib/laravel-server"

export default async function DashboardLayout({
  children,
}: {
  children: React.ReactNode
}) {
  const session = await getLaravelServerSession()

  if (!session) {
    redirect("/login")
  }

  return (
    <div className="min-h-screen bg-secondary/30">
      <DashboardHeader user={session.user} />
      <main className="container mx-auto px-4 py-8">{children}</main>
    </div>
  )
}

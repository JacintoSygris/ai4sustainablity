import { StudyKit } from "@/components/usability/study-kit"
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

  if (session.require_email_verification && session.user.email_verified === false) {
    redirect("/verify-email")
  }

  return (
    <div className="min-h-screen bg-secondary/30">
      <DashboardHeader user={session.user} learningAdmin={session.learning_admin === true} />
      <main className="container mx-auto px-4 py-8">{session.usability_study_enabled === true ? <StudyKit>{children}</StudyKit> : children}</main>
    </div>
  )
}

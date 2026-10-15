import { notFound } from "next/navigation"
import { getLaravelServerSession } from "@/lib/laravel-server"
import { LearningAdminPanel } from "@/components/learning/admin-panel"

export default async function LearningAdminPage() {
  const session = await getLaravelServerSession()
  if (session?.learning_admin !== true) notFound()
  return <LearningAdminPanel />
}

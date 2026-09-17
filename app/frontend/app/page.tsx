import { Header } from "@/components/ui/header"
import { Footer } from "@/components/ui/footer"
import { HeroSection } from "@/components/landing/hero-section"
import { HowItWorksSection } from "@/components/landing/how-it-works-section"
import { FeaturesSection } from "@/components/landing/features-section"
import { CtaSection } from "@/components/landing/cta-section"

export default function LandingPage() {
  return (
    <div className="flex min-h-screen flex-col">
      <Header />
      <main className="flex-1">
        <HeroSection />
        <HowItWorksSection />
        <FeaturesSection />
        <CtaSection />
      </main>
      <Footer />
    </div>
  )
}

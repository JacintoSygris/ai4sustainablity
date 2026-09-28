import Link from "next/link"

export function Footer() {
  return (
    <footer className="border-t border-border bg-background py-6">
      <div className="container mx-auto flex flex-col items-center gap-5 px-4">
        <div className="flex flex-col items-center gap-2 md:items-start">
          <div className="flex items-center gap-2">
            <span className="text-xl font-bold text-primary">Airis</span>
            <span className="text-sm text-muted-foreground">©Sygris</span>
          </div>
          <nav className="flex items-center gap-4 text-sm text-muted-foreground">
            <Link href="/cookies" className="hover:text-primary hover:underline">Cookies</Link>
            <Link href="/privacy" className="hover:text-primary hover:underline">
              Privacidad
            </Link>
            <Link href="/terms" className="hover:text-primary hover:underline">
              Términos
            </Link>
          </nav>
        </div>
        <p className="max-w-3xl text-center text-sm text-muted-foreground">
          Cofinanciación de la Comunidad de Madrid y la Unión Europea (FEDER). Proyecto IA4SustainabilityReport,
          referencia 09-PYN1-00054.1/2023.
        </p>
        <div className="flex w-full flex-wrap items-center justify-center gap-16 bg-white py-16">
          <img src="/funding/pymes-2023/comunidad-madrid-positivo.png" alt="Comunidad de Madrid" className="h-14 w-auto max-w-full object-contain" />
          <img src="/funding/pymes-2023/fondos-europeos-oficial.jpg" alt="Fondos Europeos" className="h-auto min-h-8 w-[200px] shrink-0 object-contain" />
          <img
            src="/funding/pymes-2023/ue-cofinanciado-oficial.png"
            alt="Cofinanciado por la Unión Europea"
            className="h-auto w-[320px] max-w-full object-contain"
          />
        </div>
      </div>
    </footer>
  )
}

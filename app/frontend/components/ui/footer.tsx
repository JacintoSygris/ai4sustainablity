export function Footer() {
  return (
    <footer className="border-t border-border bg-background py-6">
      <div className="container mx-auto flex flex-col items-center justify-between gap-4 px-4 md:flex-row">
        <div className="flex items-center gap-2">
          <span className="text-xl font-bold text-primary">Airis</span>
          <span className="text-sm text-muted-foreground">©Sygris</span>
        </div>
        <p className="text-center text-sm text-muted-foreground md:text-left">
          Convocatoria de ayudas para el desarrollo de pymes innovadoras. Proyecto IA4SustainabilityReport Referencia:
          09-PYN1-00054.1/2023.
        </p>
        <div className="flex items-center gap-4">
          <img src="/madrid-region-logo.jpg" alt="Comunidad de Madrid" className="h-8 object-contain" />
          <img src="/european-funds-logo.jpg" alt="Fondos Europeos" className="h-8 object-contain" />
          <img
            src="/eu-flag-cofinanced.jpg"
            alt="Cofinanciado por la Unión Europea"
            className="h-8 object-contain"
          />
        </div>
      </div>
    </footer>
  )
}

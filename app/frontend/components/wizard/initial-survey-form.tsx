"use client"

import type React from "react"

import { useEffect, useState } from "react"
import { useRouter } from "next/navigation"
import { AlertCircle, AlertTriangle, ChevronDown, ChevronUp } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip"
import {
  LaravelApiError,
  getLaravelCharacterization,
  getLaravelCharacterizationOptions,
  getLaravelSession,
  getLaravelNaceCodes,
  saveLaravelCharacterizationDraft,
  type LaravelCharacterization,
  type LaravelCharacterizationOptions,
  type LaravelNaceCode,
  type LaravelOptionMap,
} from "@/lib/laravel-api"

type SelectOption = {
  value: string
  label: string
}

type FormState = {
  companyName: string
  naceCode: string
  headquartersCountry: string
  reportingYear: string
  reportingScope: string
  numSubsidiariesCountries: string
  stockListed: "" | "yes" | "no"
  reportingCurrency: string
  productServiceType: string
  regions: string[]
  valueChain: string[]
  employeeCountRange: string
  revenueRange: string
  notes: string
}

type FieldErrors = Partial<Record<keyof FormState, string>>

const emptyOptions: LaravelCharacterizationOptions = {
  levels: {
    core: {
      required_fields: [],
      draft_clearable_fields: [],
      company_profile: {
        headquarters_countries: {},
        reporting_scopes: {},
        reporting_currencies: {},
        product_service_types: {},
      },
      operations: {
        regions: {},
        value_chain: {},
        employee_count_ranges: {},
        revenue_ranges: {},
      },
    },
  },
}

function defaultReportingYear(): string {
  return String(new Date().getFullYear() - 1)
}

function emptyFormState(): FormState {
  return {
    companyName: "",
    naceCode: "",
    headquartersCountry: "Spain",
    reportingYear: defaultReportingYear(),
    reportingScope: "",
    numSubsidiariesCountries: "0",
    stockListed: "",
    reportingCurrency: "EUR",
    productServiceType: "",
    regions: [],
    valueChain: [],
    employeeCountRange: "",
    revenueRange: "",
    notes: "",
  }
}

function optionMapToList(options: LaravelOptionMap | undefined): SelectOption[] {
  return Object.entries(options ?? {}).map(([value, label]) => ({ value, label }))
}

function optionLabel(options: SelectOption[], value: string): string {
  return options.find((option) => option.value === value)?.label ?? value
}

function optionLabels(options: SelectOption[], values: string[]): string {
  return values.map((value) => optionLabel(options, value)).join(", ")
}

function stringArrayValue(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === "string") : []
}

function naceCodeLabel(code: LaravelNaceCode): string {
  const title = code.title.es ?? code.title.en

  return title ? `${code.code} - ${title}` : code.code
}

function validationMessageFor(error: LaravelApiError, key: string): string | null {
  const payload = error.payload as { errors?: Record<string, string[]> } | null
  const messages = payload?.errors?.[key]

  return Array.isArray(messages) && typeof messages[0] === "string" ? messages[0] : null
}

function stateFromCharacterization(characterization: LaravelCharacterization | null): FormState {
  if (!characterization) {
    return emptyFormState()
  }

  const companyProfile = characterization.form_data?.company_profile ?? {}
  const operations = characterization.form_data?.operations ?? {}

  return {
    companyName: companyProfile.company_name ?? "",
    naceCode: characterization.nace_code ?? "",
    headquartersCountry: companyProfile.headquarters_country ?? "Spain",
    reportingYear: companyProfile.reporting_year ? String(companyProfile.reporting_year) : defaultReportingYear(),
    reportingScope: companyProfile.reporting_scope ?? "",
    numSubsidiariesCountries:
      companyProfile.num_subsidiaries_countries == null ? "0" : String(companyProfile.num_subsidiaries_countries),
    stockListed: companyProfile.stock_listed == null ? "" : companyProfile.stock_listed ? "yes" : "no",
    reportingCurrency: companyProfile.reporting_currency ?? "EUR",
    productServiceType: companyProfile.product_service_type ?? "",
    regions: stringArrayValue(operations.regions),
    valueChain: stringArrayValue(operations.value_chain),
    employeeCountRange: operations.employee_count_range ?? "",
    revenueRange: operations.revenue_range ?? "",
    notes: typeof characterization.form_data?.notes === "string" ? characterization.form_data.notes : "",
  }
}

function p5IsComplete(formData: FormState): boolean {
  return [
    formData.companyName.trim() !== "",
    formData.naceCode.trim() !== "",
    formData.headquartersCountry.trim() !== "",
    formData.reportingYear.trim() !== "",
    formData.reportingScope.trim() !== "",
    formData.numSubsidiariesCountries.trim() !== "",
    formData.stockListed.trim() !== "",
    formData.reportingCurrency.trim() !== "",
    formData.productServiceType.trim() !== "",
    formData.regions.length > 0,
    formData.valueChain.length > 0,
    formData.employeeCountRange.trim() !== "",
    formData.revenueRange.trim() !== "",
  ].every(Boolean)
}

function toInteger(value: string): number {
  const parsed = Number.parseInt(value, 10)

  return Number.isFinite(parsed) ? parsed : 0
}

function buildDraftPayload(formData: FormState) {
  return {
    action: "save_draft" as const,
    step: "review" as const,
    nace_code: formData.naceCode.trim(),
    form_data: {
      company_profile: {
        company_name: formData.companyName.trim(),
        headquarters_country: formData.headquartersCountry,
        reporting_year: toInteger(formData.reportingYear),
        reporting_scope: formData.reportingScope,
        num_subsidiaries_countries: toInteger(formData.numSubsidiariesCountries),
        stock_listed: formData.stockListed === "yes",
        reporting_currency: formData.reportingCurrency,
        product_service_type: formData.productServiceType,
      },
      operations: {
        regions: formData.regions,
        value_chain: formData.valueChain,
        employee_count_range: formData.employeeCountRange,
        revenue_range: formData.revenueRange,
      },
      notes: formData.notes.trim() || null,
    },
  }
}

export function InitialSurveyForm() {
  const router = useRouter()
  const [expanded, setExpanded] = useState(true)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [saving, setSaving] = useState(false)
  const [isReadOnly, setIsReadOnly] = useState(false)
  const [showConfirmDialog, setShowConfirmDialog] = useState(false)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [csrfToken, setCsrfToken] = useState<string>()
  const [options, setOptions] = useState<LaravelCharacterizationOptions>(emptyOptions)
  const [formData, setFormData] = useState<FormState>(() => emptyFormState())
  const [naceOptions, setNaceOptions] = useState<LaravelNaceCode[]>([])
  const [naceLookupMessage, setNaceLookupMessage] = useState<string | null>(null)

  const coreOptions = options.levels.core
  const headquartersCountryOptions = optionMapToList(coreOptions.company_profile.headquarters_countries)
  const reportingScopeOptions = optionMapToList(coreOptions.company_profile.reporting_scopes)
  const reportingCurrencyOptions = optionMapToList(coreOptions.company_profile.reporting_currencies)
  const productServiceTypeOptions = optionMapToList(coreOptions.company_profile.product_service_types)
  const regionOptions = optionMapToList(coreOptions.operations.regions)
  const valueChainOptions = optionMapToList(coreOptions.operations.value_chain)
  const employeeCountOptions = optionMapToList(coreOptions.operations.employee_count_ranges)
  const revenueOptions = optionMapToList(coreOptions.operations.revenue_ranges)

  const totalQuestions = 13
  const answeredQuestions = [
    formData.companyName.trim() !== "",
    formData.naceCode.trim() !== "",
    formData.headquartersCountry.trim() !== "",
    formData.reportingYear.trim() !== "",
    formData.reportingScope.trim() !== "",
    formData.numSubsidiariesCountries.trim() !== "",
    formData.stockListed.trim() !== "",
    formData.reportingCurrency.trim() !== "",
    formData.productServiceType.trim() !== "",
    formData.regions.length > 0,
    formData.valueChain.length > 0,
    formData.employeeCountRange.trim() !== "",
    formData.revenueRange.trim() !== "",
  ].filter(Boolean).length
  const progress = isReadOnly ? 100 : Math.round((answeredQuestions / totalQuestions) * 100)

  useEffect(() => {
    let mounted = true

    async function loadP5() {
      try {
        const [sessionResponse, optionsResponse, characterizationResponse] = await Promise.all([
          getLaravelSession(),
          getLaravelCharacterizationOptions(),
          getLaravelCharacterization(),
        ])

        if (!mounted) {
          return
        }

        const nextState = stateFromCharacterization(characterizationResponse.data)
        setCsrfToken(sessionResponse.data.csrf_token)
        setOptions(optionsResponse.data)
        setFormData(nextState)
        setIsReadOnly(p5IsComplete(nextState))
      } catch (error) {
        if (error instanceof LaravelApiError && error.status === 401) {
          router.replace("/login")

          return
        }

        setErrorMessage("No se ha podido cargar la caracterización P5 desde Laravel.")
      } finally {
        if (mounted) {
          setLoadingInitial(false)
        }
      }
    }

    loadP5()

    return () => {
      mounted = false
    }
  }, [router])

  const updateForm = (patch: Partial<FormState>) => {
    setFormData((current) => ({ ...current, ...patch }))
    setErrorMessage(null)
    setFieldErrors((current) => {
      const next = { ...current }

      for (const key of Object.keys(patch) as Array<keyof FormState>) {
        delete next[key]
      }

      return next
    })
  }

  useEffect(() => {
    if (isReadOnly) {
      setNaceOptions([])
      setNaceLookupMessage(null)

      return
    }

    const search = formData.naceCode.trim()

    if (search.length < 2) {
      setNaceOptions([])
      setNaceLookupMessage(null)

      return
    }

    let mounted = true

    async function loadNaceOptions() {
      try {
        const response = await getLaravelNaceCodes({ search, per_page: 8 })

        if (!mounted) {
          return
        }

        setNaceOptions(response.data)
        setNaceLookupMessage(response.data.length === 0 ? "No hay códigos NACE coincidentes." : null)
      } catch {
        if (mounted) {
          setNaceOptions([])
          setNaceLookupMessage("No se ha podido consultar el catálogo NACE.")
        }
      }
    }

    loadNaceOptions()

    return () => {
      mounted = false
    }
  }, [formData.naceCode, isReadOnly])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setErrorMessage(null)
    setFieldErrors({})

    if (!p5IsComplete(formData)) {
      setErrorMessage("Completa los campos P5 obligatorios antes de continuar.")

      return
    }

    setSaving(true)

    try {
      await saveLaravelCharacterizationDraft(buildDraftPayload(formData), { csrfToken })
      setIsReadOnly(true)
      router.push("/wizard/step-2")
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")

        return
      }

      if (error instanceof LaravelApiError && error.status === 422) {
        const naceMessage =
          validationMessageFor(error, "nace_code") ?? "Selecciona un código NACE/CNAE válido del catálogo."

        setFieldErrors({ naceCode: naceMessage })
      }

      setErrorMessage("Laravel no ha podido guardar el borrador P5. Revisa los campos e inténtalo de nuevo.")
    } finally {
      setSaving(false)
    }
  }

  const ReadOnlyField = ({ label, value }: { label: string; value: string }) => (
    <div className="space-y-1">
      <p className="text-sm font-medium text-muted-foreground">{label}</p>
      <p className="text-foreground">{value || "-"}</p>
    </div>
  )

  const MultiSelectCheckboxes = ({
    id,
    label,
    options,
    values,
    onChange,
  }: {
    id: string
    label: string
    options: SelectOption[]
    values: string[]
    onChange: (values: string[]) => void
  }) => {
    const toggleValue = (value: string) => {
      onChange(values.includes(value) ? values.filter((current) => current !== value) : [...values, value])
    }

    return (
      <div className="space-y-2">
        <Label>{label}</Label>
        <div id={id} className="max-h-44 overflow-y-auto rounded-md border border-input bg-background p-2">
          {options.map((option) => (
            <label
              key={option.value}
              className="flex min-h-10 cursor-pointer items-center gap-3 rounded-sm px-2 py-2 text-sm hover:bg-muted"
            >
              <Checkbox checked={values.includes(option.value)} onCheckedChange={() => toggleValue(option.value)} />
              <span>{option.label}</span>
            </label>
          ))}
        </div>
      </div>
    )
  }

  return (
    <div className="flex-1">
      <Dialog open={showConfirmDialog} onOpenChange={setShowConfirmDialog}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <AlertTriangle className="h-5 w-5 text-amber-500" />
              Editar este paso
            </DialogTitle>
            <DialogDescription className="space-y-2 pt-2">
              <p>¿Quieres modificar la caracterización P5 guardada?</p>
              <p className="text-muted-foreground">
                Los pasos posteriores pueden necesitar regenerarse cuando cambie esta información.
              </p>
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="flex-row justify-end gap-2 sm:gap-0">
            <Button variant="ghost" onClick={() => setShowConfirmDialog(false)}>
              Cancelar
            </Button>
            <Button
              onClick={() => {
                setShowConfirmDialog(false)
                setIsReadOnly(false)
              }}
            >
              Editar P5
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-foreground">Caracterización inicial</h1>
      </div>

      <div className="mt-4 flex items-center justify-between">
        <span className="text-sm text-muted-foreground">
          {isReadOnly ? "Perfil P5 guardado como borrador" : "Campos P5 completados"}
        </span>
        <span className="text-sm font-medium text-foreground">{progress}%</span>
      </div>
      <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-muted">
        <div
          className={`h-full transition-all duration-300 ${progress === 100 ? "bg-accent" : "bg-primary"}`}
          style={{ width: `${progress}%` }}
        />
      </div>

      {errorMessage ? (
        <div className="mt-4 rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {errorMessage}
        </div>
      ) : null}

      {isReadOnly ? (
        <div className="mt-4 rounded-md border border-accent/40 bg-accent/10 px-4 py-3 text-sm text-foreground">
          P5 guardado. La propuesta IA se genera en el paso 2.
        </div>
      ) : null}

      <form onSubmit={handleSubmit} className="mt-8">
        <div className="rounded-xl border border-border bg-card">
          <button
            type="button"
            className="flex w-full items-center justify-between p-4"
            onClick={() => setExpanded(!expanded)}
          >
            <span className="text-lg font-semibold text-primary">1. Datos oficiales P5</span>
            {expanded ? (
              <ChevronUp className="h-5 w-5 text-muted-foreground" />
            ) : (
              <ChevronDown className="h-5 w-5 text-muted-foreground" />
            )}
          </button>

          {expanded && (
            <div className="space-y-8 border-t border-border p-6">
              {loadingInitial ? (
                <p className="text-sm text-muted-foreground">Cargando caracterización...</p>
              ) : isReadOnly ? (
                <>
                  <div className="grid gap-5 md:grid-cols-2">
                    <ReadOnlyField label="Nombre de la empresa" value={formData.companyName} />
                    <ReadOnlyField label="Código NACE/CNAE" value={formData.naceCode} />
                    <ReadOnlyField
                      label="País sede"
                      value={optionLabel(headquartersCountryOptions, formData.headquartersCountry)}
                    />
                    <ReadOnlyField label="Ejercicio de reporte" value={formData.reportingYear} />
                    <ReadOnlyField
                      label="Alcance de reporte"
                      value={optionLabel(reportingScopeOptions, formData.reportingScope)}
                    />
                    <ReadOnlyField label="Países con subsidiarias" value={formData.numSubsidiariesCountries} />
                    <ReadOnlyField label="Cotiza en bolsa" value={formData.stockListed === "yes" ? "Sí" : "No"} />
                    <ReadOnlyField
                      label="Moneda"
                      value={optionLabel(reportingCurrencyOptions, formData.reportingCurrency)}
                    />
                    <ReadOnlyField
                      label="Producto o servicio principal"
                      value={optionLabel(productServiceTypeOptions, formData.productServiceType)}
                    />
                    <ReadOnlyField label="Regiones" value={optionLabels(regionOptions, formData.regions)} />
                    <ReadOnlyField
                      label="Posición en cadena de valor"
                      value={optionLabels(valueChainOptions, formData.valueChain)}
                    />
                    <ReadOnlyField
                      label="Empleados"
                      value={optionLabel(employeeCountOptions, formData.employeeCountRange)}
                    />
                    <ReadOnlyField label="Ingresos" value={optionLabel(revenueOptions, formData.revenueRange)} />
                  </div>
                  <ReadOnlyField label="Notas internas" value={formData.notes} />
                </>
              ) : (
                <>
                  <section className="space-y-4">
                    <h2 className="text-base font-semibold text-foreground">Perfil de empresa</h2>
                    <div className="grid gap-5 md:grid-cols-2">
                      <div className="space-y-2">
                        <Label htmlFor="companyName">Nombre comercial o legal</Label>
                        <Input
                          id="companyName"
                          value={formData.companyName}
                          onChange={(e) => updateForm({ companyName: e.target.value })}
                        />
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="naceCode">Código NACE/CNAE principal</Label>
                        <Input
                          id="naceCode"
                          placeholder="Ej. C, K62, K62.1"
                          value={formData.naceCode}
                          onChange={(e) => updateForm({ naceCode: e.target.value })}
                          aria-invalid={fieldErrors.naceCode ? "true" : undefined}
                          aria-describedby={fieldErrors.naceCode ? "naceCode-error" : "naceCode-help"}
                        />
                        <p id="naceCode-help" className="text-xs text-muted-foreground">
                          Busca por código o descripción, incluso sin acentos, y selecciona un resultado del catálogo.
                        </p>
                        {fieldErrors.naceCode ? (
                          <p id="naceCode-error" className="text-sm text-destructive">
                            {fieldErrors.naceCode}
                          </p>
                        ) : null}
                        {naceOptions.length > 0 ? (
                          <div className="rounded-md border border-border bg-background p-1">
                            {naceOptions.map((option) => (
                              <button
                                key={option.code}
                                type="button"
                                className="block w-full rounded-sm px-2 py-2 text-left text-sm hover:bg-muted"
                                onClick={() => updateForm({ naceCode: option.code })}
                              >
                                {naceCodeLabel(option)}
                              </button>
                            ))}
                          </div>
                        ) : naceLookupMessage ? (
                          <p className="text-xs text-muted-foreground">{naceLookupMessage}</p>
                        ) : null}
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="headquartersCountry">País sede</Label>
                        <Select
                          value={formData.headquartersCountry}
                          onValueChange={(value) => updateForm({ headquartersCountry: value })}
                        >
                          <SelectTrigger id="headquartersCountry">
                            <SelectValue placeholder="Selecciona..." />
                          </SelectTrigger>
                          <SelectContent>
                            {headquartersCountryOptions.map((option) => (
                              <SelectItem key={option.value} value={option.value}>
                                {option.label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="reportingYear">Ejercicio de reporte</Label>
                        <Input
                          id="reportingYear"
                          min={2000}
                          type="number"
                          value={formData.reportingYear}
                          onChange={(e) => updateForm({ reportingYear: e.target.value })}
                        />
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="reportingScope">Alcance de reporte</Label>
                        <Select
                          value={formData.reportingScope}
                          onValueChange={(value) => updateForm({ reportingScope: value })}
                        >
                          <SelectTrigger id="reportingScope">
                            <SelectValue placeholder="Selecciona..." />
                          </SelectTrigger>
                          <SelectContent>
                            {reportingScopeOptions.map((option) => (
                              <SelectItem key={option.value} value={option.value}>
                                {option.label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="numSubsidiariesCountries">Número de países con subsidiarias</Label>
                        <Input
                          id="numSubsidiariesCountries"
                          min={0}
                          type="number"
                          value={formData.numSubsidiariesCountries}
                          onChange={(e) => updateForm({ numSubsidiariesCountries: e.target.value })}
                        />
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="stockListed">Cotiza en bolsa</Label>
                        <Select
                          value={formData.stockListed}
                          onValueChange={(value: "yes" | "no") => updateForm({ stockListed: value })}
                        >
                          <SelectTrigger id="stockListed">
                            <SelectValue placeholder="Selecciona..." />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="yes">Sí</SelectItem>
                            <SelectItem value="no">No</SelectItem>
                          </SelectContent>
                        </Select>
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="reportingCurrency">Moneda de reporte</Label>
                        <Select
                          value={formData.reportingCurrency}
                          onValueChange={(value) => updateForm({ reportingCurrency: value })}
                        >
                          <SelectTrigger id="reportingCurrency">
                            <SelectValue placeholder="Selecciona..." />
                          </SelectTrigger>
                          <SelectContent>
                            {reportingCurrencyOptions.map((option) => (
                              <SelectItem key={option.value} value={option.value}>
                                {option.label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>

                      <div className="space-y-2 md:col-span-2">
                        <Label htmlFor="productServiceType">Producto o servicio principal</Label>
                        <Select
                          value={formData.productServiceType}
                          onValueChange={(value) => updateForm({ productServiceType: value })}
                        >
                          <SelectTrigger id="productServiceType">
                            <SelectValue placeholder="Selecciona..." />
                          </SelectTrigger>
                          <SelectContent>
                            {productServiceTypeOptions.map((option) => (
                              <SelectItem key={option.value} value={option.value}>
                                {option.label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                    </div>
                  </section>

                  <section className="space-y-4">
                    <h2 className="text-base font-semibold text-foreground">Operaciones</h2>
                    <div className="grid gap-5 md:grid-cols-2">
                      <MultiSelectCheckboxes
                        id="regions"
                        label="Regiones"
                        options={regionOptions}
                        values={formData.regions}
                        onChange={(regions) => updateForm({ regions })}
                      />

                      <MultiSelectCheckboxes
                        id="valueChain"
                        label="Posición en cadena de valor"
                        options={valueChainOptions}
                        values={formData.valueChain}
                        onChange={(valueChain) => updateForm({ valueChain })}
                      />

                      <div className="space-y-2">
                        <Label htmlFor="employeeCountRange">Rango de empleados</Label>
                        <Select
                          value={formData.employeeCountRange}
                          onValueChange={(value) => updateForm({ employeeCountRange: value })}
                        >
                          <SelectTrigger id="employeeCountRange">
                            <SelectValue placeholder="Selecciona..." />
                          </SelectTrigger>
                          <SelectContent>
                            {employeeCountOptions.map((option) => (
                              <SelectItem key={option.value} value={option.value}>
                                {option.label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="revenueRange">Rango de ingresos</Label>
                        <Select
                          value={formData.revenueRange}
                          onValueChange={(value) => updateForm({ revenueRange: value })}
                        >
                          <SelectTrigger id="revenueRange">
                            <SelectValue placeholder="Selecciona..." />
                          </SelectTrigger>
                          <SelectContent>
                            {revenueOptions.map((option) => (
                              <SelectItem key={option.value} value={option.value}>
                                {option.label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>

                      <div className="space-y-2 md:col-span-2">
                        <Label htmlFor="notes">Notas internas</Label>
                        <Textarea
                          id="notes"
                          value={formData.notes}
                          onChange={(e) => updateForm({ notes: e.target.value })}
                        />
                      </div>
                    </div>
                  </section>
                </>
              )}
            </div>
          )}
        </div>

        <div className="mt-8 flex justify-end">
          {isReadOnly ? (
            <div className="flex flex-wrap justify-end gap-3">
              <TooltipProvider>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Button type="button" variant="outline" onClick={() => setShowConfirmDialog(true)} className="gap-2">
                      Editar P5
                      <AlertCircle className="h-4 w-4" />
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent side="top" className="max-w-xs bg-muted-foreground text-background">
                    <p>Editar P5 puede requerir recalcular los pasos posteriores.</p>
                  </TooltipContent>
                </Tooltip>
              </TooltipProvider>
              <Button type="button" onClick={() => router.push("/wizard/step-2")}>
                Continuar a propuesta IA
              </Button>
            </div>
          ) : (
            <Button type="submit" disabled={loadingInitial || saving}>
              {saving ? "Guardando..." : "Guardar y continuar"}
            </Button>
          )}
        </div>
      </form>
    </div>
  )
}

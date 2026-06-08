<?php

namespace App\Support;

class CharacterizationOptions
{
    public static function headquartersCountries(): array
    {
        return [
            'Spain' => 'España',
            'Portugal' => 'Portugal',
            'France' => 'Francia',
            'Germany' => 'Alemania',
            'Italy' => 'Italia',
            'United Kingdom' => 'Reino Unido',
            'United States' => 'Estados Unidos',
            'Other' => 'Otro',
        ];
    }

    public static function reportingScopes(): array
    {
        return [
            'individual' => 'Entidad individual',
            'consolidated_group' => 'Grupo consolidado',
            'not_sure' => 'No estoy seguro',
        ];
    }

    public static function reportingCurrencies(): array
    {
        return [
            'EUR' => 'EUR',
            'GBP' => 'GBP',
            'USD' => 'USD',
        ];
    }

    public static function employeeCountRanges(): array
    {
        return [
            '1_9' => '1-9',
            '10_49' => '10-49',
            '50_249' => '50-249',
            '250_499' => '250-499',
            '500_999' => '500-999',
            '1000_plus' => '1,000+',
            'not_sure' => 'No estoy seguro / prefiero no responder',
        ];
    }

    public static function revenueRanges(): array
    {
        return [
            'lte_2m' => 'Hasta EUR 2M',
            '2m_to_10m' => 'EUR 2M-10M',
            '10m_to_40m' => 'EUR 10M-40M',
            '40m_to_50m' => 'EUR 40M-50M',
            '50m_to_250m' => 'EUR 50M-250M',
            'gt_250m' => 'Más de EUR 250M',
            'not_sure' => 'No estoy seguro / prefiero no responder',
        ];
    }

    public static function employeeCountEstimate(?string $range): int
    {
        return match ($range) {
            '1_9' => 5,
            '10_49' => 30,
            '50_249' => 150,
            '250_499' => 375,
            '500_999' => 750,
            '1000_plus' => 1000,
            default => 0,
        };
    }

    public static function revenueEstimate(?string $range): float
    {
        return match ($range) {
            'lte_2m' => 1000000.0,
            '2m_to_10m' => 6000000.0,
            '10m_to_40m' => 25000000.0,
            '40m_to_50m' => 45000000.0,
            '50m_to_250m' => 150000000.0,
            'gt_250m' => 250000000.0,
            default => 0.0,
        };
    }

    public static function employeeCountRangeEstimates(): array
    {
        return collect(array_keys(self::employeeCountRanges()))
            ->mapWithKeys(fn (string $range) => [$range => self::employeeCountEstimate($range)])
            ->all();
    }

    public static function revenueRangeEstimates(): array
    {
        return collect(array_keys(self::revenueRanges()))
            ->mapWithKeys(fn (string $range) => [$range => self::revenueEstimate($range)])
            ->all();
    }

    public static function productServiceTypes(): array
    {
        return [
            'physical_product_manufacturing' => 'Producto físico (fabricación)',
            'physical_product_retail' => 'Producto físico (comercialización/retail)',
            'professional_services' => 'Servicios profesionales',
            'technical_services' => 'Servicios técnicos/ingeniería/mantenimiento',
            'software_digital_services' => 'Software/SaaS/servicios digitales',
            'construction_installations' => 'Construcción/obras/instalaciones',
            'logistics_transport_storage' => 'Logística/transporte/almacenamiento',
            'finance_insurance' => 'Finanzas/seguros',
            'energy_utilities' => 'Energía/utilities',
            'agrifood' => 'Agroalimentario',
            'health_life_sciences' => 'Salud/ciencias de la vida',
            'education_training' => 'Educación/formación',
            'hospitality_tourism' => 'Hostelería/turismo',
            'mixed' => 'Mixto',
            'not_sure' => 'No estoy seguro / prefiero no responder',
        ];
    }

    public static function regions(): array
    {
        return [
            'eu' => 'Unión Europea',
            'north_america' => 'Norteamérica',
            'latin_america' => 'América Latina',
            'asia' => 'Asia-Pacífico',
            'middle_east_africa' => 'Oriente Medio y África',
            'oceania' => 'Oceanía',
        ];
    }

    public static function valueChainPositions(): array
    {
        return [
            'upstream' => 'Actividades anteriores (upstream)',
            'direct_operations' => 'Operaciones directas',
            'downstream' => 'Actividades posteriores (downstream)',
            'services' => 'Servicios y soporte',
        ];
    }

    public static function yesNoUnknown(): array
    {
        return [
            'yes' => 'Sí',
            'no' => 'No',
            'not_sure' => 'No estoy seguro',
        ];
    }

    public static function dataReadinessItems(): array
    {
        return [
            'energy' => 'Consumo de energía',
            'ghg_emissions' => 'Emisiones GEI',
            'water' => 'Consumo de agua',
            'waste' => 'Generación de residuos',
            'health_safety' => 'Salud y seguridad',
            'equality_diversity' => 'Igualdad y diversidad',
            'training' => 'Formación',
            'policies_targets' => 'Políticas y objetivos',
            'supplier_data' => 'Datos de proveedores',
            'previous_reporting' => 'Reporting de sostenibilidad previo',
        ];
    }

    public static function dataReadinessSources(): array
    {
        return [
            'invoices' => 'Facturas',
            'erp' => 'ERP/sistema contable',
            'metering' => 'Contadores o medición directa',
            'hr_system' => 'Sistema RR. HH.',
            'manual_records' => 'Registros manuales',
            'estimate' => 'Estimación',
            'external_consultant' => 'Consultor externo',
            'other' => 'Otro',
        ];
    }

    public static function traceabilityLevels(): array
    {
        return [
            'high' => 'Alta',
            'medium' => 'Media',
            'low' => 'Baja',
            'unknown' => 'Desconocida',
        ];
    }

    public static function csrdOrientationDisclaimer(): string
    {
        return 'Orientación informativa únicamente. La determinación del alcance legal depende de la normativa vigente y de asesoramiento especialista.';
    }

    public static function submitRequiredFields(): array
    {
        return [
            'nace_code',
            'form_data.company_profile.company_name',
            'form_data.company_profile.headquarters_country',
            'form_data.company_profile.reporting_year',
            'form_data.company_profile.reporting_scope',
            'form_data.company_profile.num_subsidiaries_countries',
            'form_data.company_profile.stock_listed',
            'form_data.company_profile.reporting_currency',
            'form_data.company_profile.product_service_type',
            'form_data.operations.regions',
            'form_data.operations.value_chain',
            'form_data.operations.employee_count_range',
            'form_data.operations.revenue_range',
        ];
    }

    public static function draftClearableFields(): array
    {
        return [
            'nace_code',
            'esrs_topic_ids',
            'form_data.operations.regions',
            'form_data.operations.value_chain',
        ];
    }
}

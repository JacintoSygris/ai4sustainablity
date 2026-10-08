<?php

namespace App\Support;

class CharacterizationOptions
{
    public static function headquartersCountries(): array
    {
        return [
            'Spain' => __('España'),
            'Portugal' => 'Portugal',
            'France' => __('Francia'),
            'Germany' => __('Alemania'),
            'Italy' => __('Italia'),
            'United Kingdom' => __('Reino Unido'),
            'United States' => __('Estados Unidos'),
            'Other' => __('Otro'),
        ];
    }

    public static function reportingScopes(): array
    {
        return [
            'individual' => __('Entidad individual'),
            'consolidated_group' => __('Grupo consolidado'),
            'not_sure' => __('No estoy seguro'),
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
            'not_sure' => __('No estoy seguro / prefiero no responder'),
        ];
    }

    public static function revenueRanges(): array
    {
        return [
            'lte_2m' => __('Hasta EUR 2M'),
            '2m_to_10m' => 'EUR 2M-10M',
            '10m_to_40m' => 'EUR 10M-40M',
            '40m_to_50m' => 'EUR 40M-50M',
            '50m_to_250m' => 'EUR 50M-250M',
            'gt_250m' => __('Más de EUR 250M'),
            'not_sure' => __('No estoy seguro / prefiero no responder'),
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
            'physical_product_manufacturing' => __('Producto físico (fabricación)'),
            'physical_product_retail' => __('Producto físico (comercialización minorista)'),
            'professional_services' => __('Servicios profesionales'),
            'technical_services' => __('Servicios técnicos/ingeniería/mantenimiento'),
            'software_digital_services' => __('Programas informáticos y servicios digitales'),
            'construction_installations' => __('Construcción/obras/instalaciones'),
            'logistics_transport_storage' => __('Logística/transporte/almacenamiento'),
            'finance_insurance' => __('Finanzas/seguros'),
            'energy_utilities' => __('Energía y servicios de suministro'),
            'agrifood' => __('Agroalimentario'),
            'health_life_sciences' => __('Salud/ciencias de la vida'),
            'education_training' => __('Educación/formación'),
            'hospitality_tourism' => __('Hostelería/turismo'),
            'mixed' => __('Mixto'),
            'not_sure' => __('No estoy seguro / prefiero no responder'),
        ];
    }

    public static function regions(): array
    {
        return [
            'eu' => __('Unión Europea'),
            'north_america' => __('Norteamérica'),
            'latin_america' => __('América Latina'),
            'asia' => __('Asia-Pacífico'),
            'middle_east_africa' => __('Oriente Medio y África'),
            'oceania' => __('Oceanía'),
        ];
    }

    public static function valueChainPositions(): array
    {
        return [
            'upstream' => __('Actividades anteriores de la cadena de valor'),
            'direct_operations' => __('Operaciones directas'),
            'downstream' => __('Actividades posteriores de la cadena de valor'),
            'services' => __('Servicios y soporte'),
        ];
    }

    public static function yesNoUnknown(): array
    {
        return [
            'yes' => __('Sí'),
            'no' => __('No'),
            'not_sure' => __('No estoy seguro'),
        ];
    }

    public static function activityQuestions(): array
    {
        return [
            'physical_operations' => __('¿La empresa posee u opera instalaciones físicas propias (fábricas, plantas, talleres, almacenes, terrenos agrícolas), en lugar de ser únicamente una oficina, sociedad de cartera o entidad financiera?'),
            'water_use' => __('¿Alguna actividad propia usa o vierte agua de forma relevante (refrigeración, limpieza industrial, procesado de alimentos, riego, teñido…)?'),
            'hazardous_substances' => __('¿Se usan, almacenan, transportan o generan sustancias peligrosas, combustibles, emisiones o residuos que requieran algún control ambiental?'),
            'biodiversity_sensitive_locations' => __('¿Hay instalaciones, obras o terrenos en o cerca de espacios naturales protegidos, bosques, humedales, masas de agua o zonas de biodiversidad sensible?'),
            'physical_goods_resources' => __('¿La actividad fabrica, envasa o procesa bienes físicos, o consume materias primas o genera residuos de forma significativa?'),
            'external_value_chain_workers' => __('¿Dependen las operaciones de proveedores, subcontratas, fabricación externalizada o trabajo logístico donde las personas no están en la plantilla propia?'),
            'local_communities' => __('¿Pueden las operaciones afectar de forma directa a comunidades locales (ruido, tráfico, obras, uso de suelo, dependencia de empleo local, permisos)?'),
            'consumer_end_users' => __('¿Venden productos o servicios usados directamente por consumidores o usuarios finales cuya seguridad, salud, privacidad o bienestar pueda verse afectada?'),
            'international_footprint' => __('¿Hay actividad, proveedores o clientes relevantes fuera de España o cadenas de suministro internacionales?'),
        ];
    }

    public static function activityQuestionsNote(): string
    {
        return __('Estas preguntas son opcionales y ayudan a afinar la propuesta inicial de temas. Si no lo tienes claro, deja "No estoy seguro".');
    }

    public static function dataReadinessItems(): array
    {
        return [
            'energy' => __('Consumo de energía'),
            'ghg_emissions' => __('Emisiones GEI'),
            'water' => __('Consumo de agua'),
            'waste' => __('Generación de residuos'),
            'health_safety' => __('Salud y seguridad'),
            'equality_diversity' => __('Igualdad y diversidad'),
            'training' => __('Formación'),
            'policies_targets' => __('Políticas y objetivos'),
            'supplier_data' => __('Datos de proveedores'),
            'previous_reporting' => __('Información de sostenibilidad publicada anteriormente'),
        ];
    }

    public static function dataReadinessSources(): array
    {
        return [
            'invoices' => __('Facturas'),
            'erp' => __('ERP/sistema contable'),
            'metering' => __('Contadores o medición directa'),
            'hr_system' => __('Sistema RR. HH.'),
            'manual_records' => __('Registros manuales'),
            'estimate' => __('Estimación'),
            'external_consultant' => __('Consultor externo'),
            'other' => __('Otro'),
        ];
    }

    public static function traceabilityLevels(): array
    {
        return [
            'high' => __('Alta'),
            'medium' => __('Media'),
            'low' => __('Baja'),
            'unknown' => __('Desconocida'),
        ];
    }

    public static function csrdOrientationDisclaimer(): string
    {
        return __('Orientación informativa únicamente. La determinación del alcance legal depende de la normativa vigente y de asesoramiento especialista.');
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

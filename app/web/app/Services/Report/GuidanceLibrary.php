<?php

namespace App\Services\Report;

class GuidanceLibrary
{
    private static ?array $data = null;

    private const AUTHORITATIVE_TIERS = ['certified_support_rule', 'reviewed_plain_language'];

    public function guidanceFor(string $datapointId, string $drKey): array
    {
        $rules = $this->load()['rules'];

        if (isset($rules[$drKey])) {
            $rule = $rules[$drKey];
            $tier = $rule['provenance_tier'] ?? 'pending_review';

            return [
                'text' => $rule['text'] ?? '',
                'provenance_tier' => $tier,
                'authoritative' => in_array($tier, self::AUTHORITATIVE_TIERS, true),
                'citations' => $rule['citations'] ?? [],
            ];
        }

        return [
            'text' => "Nota: este es un texto de orientación general (no certificado) para preparar la información requerida por {$drKey} conforme a la ESRS aplicable. Verifique el texto normativo antes de divulgar.",
            'provenance_tier' => 'generic_scaffold',
            'authoritative' => false,
            'citations' => [],
        ];
    }

    public function sha256(): string
    {
        return hash('sha256', file_get_contents($this->path()));
    }

    private function load(): array
    {
        return self::$data ??= json_decode(
            file_get_contents($this->path()),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    private function path(): string
    {
        return base_path('data/atomizer_support_rules_v1.json');
    }
}

<?php

namespace App\Services\Report;

final class ReportVisibleLabels
{
    /** @var array<string, string> */
    /** @var array<string, string> */
    public static function sectionTitle(string $drKey): ?string
    {
        return (new \App\Support\EsrsDisplayCatalogue)->sectionTitle($drKey);
    }

    public static function claimLabel(string $datapointId): ?string
    {
        return (new \App\Support\EsrsDisplayCatalogue)->claimLabel($datapointId);
    }
}

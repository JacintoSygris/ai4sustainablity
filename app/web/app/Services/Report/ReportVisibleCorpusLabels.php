<?php

namespace App\Services\Report;

final class ReportVisibleCorpusLabels
{
    /** @var array<string, string> */
    public static function claimLabel(string $datapointId): ?string
    {
        return (new \App\Support\EsrsDisplayCatalogue)->claimLabel($datapointId);
    }
}

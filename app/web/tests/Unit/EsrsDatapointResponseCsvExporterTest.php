<?php

use App\Support\EsrsDatapointResponseCsvExporter;

function exportResponseRow(array $response): array
{
    $corpus = [
        'blocks' => [
            'always_required' => [
                'key' => 'always_required',
                'disclosure_requirements' => [],
                'datapoints' => [
                    ['id' => 'DP-1', 'standard' => 'ESRS 2', 'dr' => 'BP-1', 'name' => 'Datapoint'],
                ],
            ],
        ],
    ];

    $csv = (new EsrsDatapointResponseCsvExporter)->toCsv($corpus, ['responses' => ['DP-1' => $response]]);
    $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
    $header = array_shift($rows);

    return array_combine($header, $rows[0]);
}

it('neutralizes spreadsheet formulas in user-entered response fields', function () {
    $row = exportResponseRow([
        'status' => 'answered',
        'value' => '=HYPERLINK("http://evil.test","x")',
        'evidence_reference' => '+cmd',
        'note' => '@SUM(A1:A2)',
    ]);

    expect($row['response_value'])->toBe('\'=HYPERLINK("http://evil.test","x")')
        ->and($row['evidence_reference'])->toBe("'+cmd")
        ->and($row['note'])->toBe("'@SUM(A1:A2)");
});

it('keeps plain numbers and ordinary text unchanged', function () {
    $row = exportResponseRow([
        'status' => 'answered',
        'value' => '-12.5',
        'evidence_reference' => 'Informe anual 2025, p. 14',
        'note' => '',
    ]);

    expect($row['response_value'])->toBe('-12.5')
        ->and($row['evidence_reference'])->toBe('Informe anual 2025, p. 14')
        ->and($row['note'])->toBe('');
});

<?php

use App\Services\Usability\StudyAnalysis;

it('scores the standard SUS extremes and midpoint with alternating polarity', function () {
    expect(StudyAnalysis::sus([5, 1, 5, 1, 5, 1, 5, 1, 5, 1]))->toBe(100.0)
        ->and(StudyAnalysis::sus([1, 5, 1, 5, 1, 5, 1, 5, 1, 5]))->toBe(0.0)
        ->and(StudyAnalysis::sus(array_fill(0, 10, 3)))->toBe(50.0);
});

it('rejects incomplete or coerced SUS values', function (array $answers) {
    expect(fn () => StudyAnalysis::sus($answers))->toThrow(InvalidArgumentException::class);
})->with([
    'missing' => [array_fill(0, 9, 3)],
    'outside' => [[0, 3, 3, 3, 3, 3, 3, 3, 3, 3]],
    'strings' => [array_fill(0, 10, '3')],
    'unordered' => [array_combine(range(1, 10), array_fill(0, 10, 3))],
]);

it('computes sample SD and a two-sided Student t confidence interval', function () {
    $stats = StudyAnalysis::summary([60, 70, 80, 90, 100]);
    expect($stats['n'])->toBe(5)
        ->and($stats['mean'])->toBe(80.0)
        ->and(abs($stats['sd'] - sqrt(250)))->toBeLessThan(0.000001)
        ->and(abs($stats['ci95'][0] - (80 - 2.776445 * sqrt(50))))->toBeLessThan(0.000001)
        ->and(abs($stats['ci95'][1] - (80 + 2.776445 * sqrt(50))))->toBeLessThan(0.000001);
});

it('does not manufacture empty or singleton uncertainty', function () {
    expect(StudyAnalysis::summary([]))->toBe(['n' => 0, 'mean' => null, 'sd' => null, 'ci95' => null])
        ->and(StudyAnalysis::summary([50])['sd'])->toBeNull()
        ->and(StudyAnalysis::summary([50])['ci95'])->toBeNull();
});

it('counts retries without inflating participant effectiveness and separates open attempts', function () {
    $task = static fn ($step, $outcome, $end, $errors) => (object) [
        'study_participant_id' => 'synthetic-test-only', 'step' => $step,
        'started_at' => '2026-10-08 10:00:00', 'ended_at' => $end,
        'outcome' => $outcome, 'error_count' => $errors,
    ];
    $metrics = StudyAnalysis::tasks([
        $task('P5', 'complete', '2026-10-08 10:01:00', 2),
        $task('P5', 'complete', '2026-10-08 10:02:00', 0),
        $task('P6', 'abandon', '2026-10-08 10:00:30', 1),
        $task('P6', null, null, null),
    ], 1);
    expect($metrics['completion_rate'])->toBe(1 / 6)
        ->and($metrics['tasks']['P5']['successful_participants'])->toBe(1)
        ->and($metrics['tasks']['P5']['completed_seconds']['mean'])->toBe(90.0)
        ->and($metrics['tasks']['P5']['error_count'])->toBe(2)
        ->and($metrics['tasks']['P6']['open'])->toBe(1)
        ->and($metrics['tasks']['P6']['abandoned'])->toBe(1)
        ->and($metrics['tasks']['P6']['completed_seconds']['mean'])->toBeNull()
        ->and($metrics['tasks']['P7']['error_count'])->toBeNull();
});

it('neutralises spreadsheet formulas including leading whitespace and control characters', function (string $value) {
    expect(StudyAnalysis::csvCell($value))->toBe("'".$value);
})->with(['=1+1', '+SUM(A1)', '-cmd', '@SUM(A1)', '  =1', "\t=1", "\r=1", "\n=1", "\u{FEFF}=1"]);

it('keeps ordinary CSV text readable', function () {
    expect(StudyAnalysis::csvCell('Texto normal'))->toBe('Texto normal')
        ->and(StudyAnalysis::csvCell('50'))->toBe('50');
});

it('applies neutralisation to the complete CSV output rather than just a helper', function () {
    $analysis = new StudyAnalysis;
    $metrics = StudyAnalysis::tasks([], 1);
    $csv = $analysis->csv(['participants' => [[
        'study_participant_id' => ' =HYPERLINK("synthetic-test-only")', 'sus' => 50.0, ...$metrics,
    ]]]);
    $lines = explode("\n", trim($csv));
    $row = str_getcsv($lines[1], ',', '"', '');
    expect($row[0])->toBe("' =HYPERLINK(\"synthetic-test-only\")")
        ->and($row[1])->toBe('50');
});

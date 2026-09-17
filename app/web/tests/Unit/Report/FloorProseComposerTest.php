<?php

use App\Services\Report\FloorProseComposer;
use App\Services\Report\NotMaterialTopicResolver;

function omittedTopic(string $label, string $grade, array $reasons = []): array
{
    return [
        'topic_id' => 1, 'esrs_code' => 'E3', 'theme_es' => 'Agua y recursos marinos', 'label' => $label,
        'evidence_grade' => $grade, 'change_reasons' => $reasons, 'change_reason_note' => 'nota privada',
    ];
}

it('claims a human assessment only for direct evidence', function () {
    $c = new FloorProseComposer();

    expect($c->omissionStatement(omittedTopic('Agua y recursos marinos', NotMaterialTopicResolver::GRADE_DIRECT)))
        ->toBe('Agua y recursos marinos se evaluó y no se consideró material.');
});

it('claims only non-confirmation for inferred evidence', function () {
    $c = new FloorProseComposer();

    expect($c->omissionStatement(omittedTopic('Agua y recursos marinos', NotMaterialTopicResolver::GRADE_INFERRED)))
        ->toBe('Agua y recursos marinos no fue confirmado como material tras la evaluación de doble materialidad.');
});

it('appends canonical reason phrases but never the free-text note', function () {
    $c = new FloorProseComposer();

    $sentence = $c->omissionStatement(
        omittedTopic('Agua', NotMaterialTopicResolver::GRADE_DIRECT, ['threshold', 'new_data'])
    );

    expect($sentence)->toContain('Motivo: por debajo del umbral de materialidad y nueva información disponible.');
    expect($sentence)->not->toContain('nota privada');
});

it('emits no reason clause and no placeholder when no reason is stored', function () {
    $c = new FloorProseComposer();

    $sentence = $c->omissionStatement(omittedTopic('Agua', NotMaterialTopicResolver::GRADE_DIRECT, []));

    expect($sentence)->toBe('Agua se evaluó y no se consideró material.');
    expect($sentence)->not->toContain('Motivo');
});

it('builds a chapter intro that carries grade-correct omission sentences', function () {
    $c = new FloorProseComposer();

    $intro = $c->chapterIntro(
        ['Cambio climático'],
        [omittedTopic('Contaminación', NotMaterialTopicResolver::GRADE_INFERRED)]
    );

    expect($intro)->toStartWith('Este capítulo cubre Cambio climático.');
    expect($intro)->toContain('Contaminación no fue confirmado como material');
});

it('omits the not-material clause when nothing was omitted', function () {
    expect((new FloorProseComposer())->chapterIntro(['X'], []))->toBe('Este capítulo cubre X.');
});

it('scopes the not-determinable declaration to the inferred set', function () {
    $c = new FloorProseComposer();

    expect($c->notDeterminableDeclaration(true))->toContain('qué otros temas se evaluaron');
    expect($c->notDeterminableDeclaration(false))->toContain('qué temas se evaluaron');
    expect($c->notDeterminableDeclaration(false))->not->toContain('otros');
});

it('declares unresolved topic ids without printing them', function () {
    $limitation = (new FloorProseComposer())->unresolvedLimitation(2);

    expect($limitation)->toContain('2 tema(s)');
    expect($limitation)->toContain('paquete de evidencias');
});

it('declares a stale confirmation', function () {
    expect((new FloorProseComposer())->staleDisclaimer('2026-01-01T00:00:00Z'))
        ->toContain('cambió después de la confirmación');
});

it('renders cross-reference sentences for both resolutions', function () {
    $c = new FloorProseComposer();

    expect($c->crossRefSentence(['target_dr' => 'E1-6', 'resolution' => 'in_scope', 'citation' => 'AR39', 'relation' => 'related']))
        ->toContain('Véase la sección E1-6');
    expect($c->crossRefSentence(['target_dr' => 'E3-1', 'resolution' => 'omitted', 'citation' => 'AR1', 'relation' => 'related']))
        ->toContain('no material');
});

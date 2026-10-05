<?php

namespace App\Jobs;

use App\Services\LearningCaseExport;
use InvalidArgumentException;

/** Serializable private command; no queue dispatch, stored grant or exported bytes. */
final class ExportLearningCaseJob
{
    public function __construct(public readonly int $actor, public readonly array $expectedReference)
    {
        $keys = array_keys($expectedReference);
        sort($keys);
        if ($actor < 1 || $keys !== ['case_hash','case_id','expected_authorization_generation','expected_revisions','source_token']) {
            throw new InvalidArgumentException('learning_export.invalid_command');
        }
        foreach (['case_hash','source_token'] as $key) {
            if (! is_string($expectedReference[$key]) || preg_match('/\A[a-f0-9]{64}\z/D', $expectedReference[$key]) !== 1) {
                throw new InvalidArgumentException('learning_export.invalid_command');
            }
        }
        if (! is_string($expectedReference['case_id']) || preg_match('/\Asynthetic-close:[a-f0-9]{64}\z/D', $expectedReference['case_id']) !== 1
            || ! is_int($expectedReference['expected_authorization_generation']) || $expectedReference['expected_authorization_generation'] < 1
            || ! is_array($expectedReference['expected_revisions'])) {
            throw new InvalidArgumentException('learning_export.invalid_command');
        }
        $sources = array_keys($expectedReference['expected_revisions']); sort($sources);
        if ($sources !== ['p5','p6_base','p8','p9']) { throw new InvalidArgumentException('learning_export.invalid_command'); }
        // Validate command shape only; the consumer owns current clock/rights semantics.
        foreach ($expectedReference['expected_revisions'] as $header) {
            if (! is_array($header)) { throw new InvalidArgumentException('learning_export.invalid_command'); }
            $fields = array_keys($header); sort($fields);
            if ($fields !== ['digest','epoch','generation','revision']) { throw new InvalidArgumentException('learning_export.invalid_command'); }
            foreach (['generation','revision'] as $key) {
                if (! is_int($header[$key]) || $header[$key] < 1) { throw new InvalidArgumentException('learning_export.invalid_command'); }
            }
            foreach (['digest','epoch'] as $key) {
                if (! is_string($header[$key]) || preg_match('/\A[a-f0-9]{64}\z/D', $header[$key]) !== 1) { throw new InvalidArgumentException('learning_export.invalid_command'); }
            }
        }
    }

    /** Resolve authority-bearing consumer at execution time; default container binding denies. */
    public function handle(): array
    {
        return app(LearningCaseExport::class)->exportForAccount($this->actor, $this->expectedReference);
    }
}

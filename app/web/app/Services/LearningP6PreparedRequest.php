<?php

namespace App\Services;

use InvalidArgumentException;
use JsonException;

/** Private transient values only; no case, revision, rights or serving attestation. */
final class LearningP6PreparedRequest
{
    private readonly array $values;

    private readonly string $valueDigest;

    public function __construct(array $payload, private readonly ?LearningP6InterpretationContext $interpretation = null)
    {
        $values = self::detach($payload, 1);
        try {
            $encoded = json_encode($values, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 512);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Prepared payload must contain plain UTF-8 JSON values within depth 512.', 0, $exception);
        }
        $this->values = $values;
        // Ordered JSON with preserved float fractions binds typed values, not transport bytes.
        $this->valueDigest = hash('sha256', $encoded);
    }

    public function payload(): array
    {
        return $this->values;
    }

    public function digest(): string
    {
        return $this->valueDigest;
    }

    public function interpretation(): ?LearningP6InterpretationContext
    {
        return $this->interpretation;
    }

    private static function detach(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            if ($depth > 512) {
                throw new InvalidArgumentException('Prepared payload exceeds depth 512.');
            }
            $copy = [];
            foreach ($value as $key => $item) {
                $copy[$key] = self::detach($item, $depth + 1);
            }

            return $copy;
        }
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)
            || (is_float($value) && is_finite($value))) {
            return $value;
        }

        throw new InvalidArgumentException('Prepared payload accepts only plain finite JSON values.');
    }
}

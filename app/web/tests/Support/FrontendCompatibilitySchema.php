<?php

namespace Tests\Support;

use LogicException;
use stdClass;

/**
 * Bounded response-schema checks for GET /api/workflow, /api/report and
 * /api/report/draft only; this is not a whole-document OpenAPI validator.
 * Supports local schema refs, oneOf, type, enum, required, properties,
 * additionalProperties, items and maxLength. Unknown keywords fail closed.
 */
final class FrontendCompatibilitySchema
{
    private array $document;

    public function __construct()
    {
        $this->document = json_decode(
            file_get_contents(base_path('../contracts/api/frontend-characterization-openapi-v0.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return list<string> */
    public function responseErrors(string $route, string $json): array
    {
        if (! in_array($route, ['/api/workflow', '/api/report', '/api/report/draft'], true)) {
            throw new LogicException('Route outside the bounded compatibility checks: '.$route);
        }

        $schema = $this->document['paths'][$route]['get']['responses']['200']['content']['application/json']['schema']
            ?? throw new LogicException('Missing GET 200 JSON response schema: '.$route);

        // Decode objects as stdClass: {} and [] must not become interchangeable.
        return $this->errors(json_decode($json, false, 512, JSON_THROW_ON_ERROR), $schema, '$', 0);
    }

    /** @return list<string> */
    private function errors(mixed $value, array $schema, string $path, int $depth): array
    {
        if ($depth > 64) {
            throw new LogicException('Compatibility schema recursion limit exceeded.');
        }
        $supported = ['$ref', 'oneOf', 'type', 'enum', 'required', 'properties', 'additionalProperties', 'items', 'maxLength', 'description', 'title'];
        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, $supported, true)) {
                throw new LogicException('Unsupported compatibility schema keyword: '.$keyword);
            }
        }

        $errors = [];
        if (isset($schema['$ref'])) {
            $prefix = '#/components/schemas/';
            if (! str_starts_with($schema['$ref'], $prefix)) {
                throw new LogicException('Only local component schema refs are supported.');
            }
            $name = substr($schema['$ref'], strlen($prefix));
            $target = $this->document['components']['schemas'][$name]
                ?? throw new LogicException('Missing schema ref: '.$name);
            $errors = $this->errors($value, $target, $path, $depth + 1);
        }
        if (isset($schema['oneOf'])) {
            $matches = 0;
            foreach ($schema['oneOf'] as $branch) {
                if ($this->errors($value, $branch, $path, $depth + 1) === []) {
                    $matches++;
                }
            }
            if ($matches !== 1) {
                $errors[] = $path.': oneOf must match exactly one branch';
            }
        }
        if (isset($schema['type'])) {
            $matches = false;
            foreach ((array) $schema['type'] as $type) {
                $matches = $this->matchesType($value, $type) || $matches;
            }
            if (! $matches) {
                return [...$errors, $path.': wrong type'];
            }
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.': value outside enum';
        }
        if (is_string($value) && isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            $errors[] = $path.': exceeds maxLength';
        }
        if ($value instanceof stdClass) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! property_exists($value, $key)) {
                    $errors[] = $path.'.'.$key.': required field missing';
                }
            }
            $properties = $schema['properties'] ?? [];
            foreach (get_object_vars($value) as $key => $child) {
                if (array_key_exists($key, $properties)) {
                    $errors = [...$errors, ...$this->errors($child, $properties[$key], $path.'.'.$key, $depth + 1)];
                    continue;
                }
                $additional = $schema['additionalProperties'] ?? true;
                if ($additional === false) {
                    $errors[] = $path.'.'.$key.': additional field forbidden';
                } elseif (is_array($additional)) {
                    $errors = [...$errors, ...$this->errors($child, $additional, $path.'.'.$key, $depth + 1)];
                }
            }
        }
        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $index => $child) {
                $errors = [...$errors, ...$this->errors($child, $schema['items'], $path.'['.$index.']', $depth + 1)];
            }
        }

        return $errors;
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => $value instanceof stdClass,
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => throw new LogicException('Unsupported schema type: '.$type),
        };
    }
}

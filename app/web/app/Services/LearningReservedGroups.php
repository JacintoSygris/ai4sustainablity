<?php

namespace App\Services;

final class LearningReservedGroups
{
    /** Private fixed sets: validate bytes before deriving any reservation. */
    public function snapshot(): array
    {
        $groups = $hashes = [];
        foreach (['heldout', 'gold'] as $name) {
            $path = config('learning_engine.'.$name.'_path');
            $sha = config('learning_engine.'.$name.'_sha256');
            if ($name === 'gold' && ($path === '' || $path === null) && ($sha === '' || $sha === null)) { continue; }
            if (! is_string($path) || ! str_starts_with($path, DIRECTORY_SEPARATOR) || is_link($path)
                || ! is_file($path) || ! is_string($sha) || preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1) {
                throw new \RuntimeException('learning.reserved_set');
            }
            $stream = fopen($path, 'rb');
            if ($stream === false) { throw new \RuntimeException('learning.reserved_set'); }
            try { $bytes = stream_get_contents($stream, 32 * 1024 * 1024 + 1); }
            finally { fclose($stream); }
            if ($bytes === false || strlen($bytes) > 32 * 1024 * 1024 || ! hash_equals($sha, hash('sha256', $bytes))) {
                throw new \RuntimeException('learning.reserved_hash');
            }
            $doc = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            $unit = $doc['unit'] ?? 'keys';
            if (($doc['schema'] ?? null) !== 'learning-heldout-v1' || ! is_array($doc['cases'] ?? null)
                || ! array_is_list($doc['cases']) || $doc['cases'] === []
                || ! in_array($unit, ['keys', 'standards'], true) || ($name === 'heldout' && $unit !== 'keys')) {
                throw new \RuntimeException('learning.reserved_schema');
            }
            $seen = []; $axes = null;
            foreach ($doc['cases'] as $case) {
                if (! is_array($case) || count($case) !== 4 || ! is_string($case['group'] ?? null)
                    || $case['group'] === '' || strlen($case['group']) > 256 || isset($seen[$case['group']])
                    || ! is_string($case['p5_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $case['p5_sha256']) !== 1
                    || ! is_array($case['input'] ?? null) || array_is_list($case['input'])
                    || ! is_array($case['truth'] ?? null) || array_is_list($case['truth']) || $case['truth'] === []) {
                    throw new \RuntimeException('learning.reserved_case');
                }
                $keys = array_keys($case['truth']); sort($keys);
                if (count($keys) > 256 || ($axes !== null && $keys !== $axes)) { throw new \RuntimeException('learning.reserved_axes'); }
                $axes = $keys;
                foreach ($keys as $key) {
                    $pattern = $unit === 'keys' ? '/^esrs_[a-z0-9_]+$/D' : '/^[ESG][0-9]$/D';
                    if (! is_string($key) || preg_match($pattern, $key) !== 1) { throw new \RuntimeException('learning.reserved_axes'); }
                }
                foreach ($case['truth'] as $value) {
                    if ($value !== null && $value !== 0 && $value !== 1) { throw new \RuntimeException('learning.reserved_truth'); }
                }
                $seen[$case['group']] = true; $groups[$case['group']] = true;
            }
            $hashes[$name === 'gold' ? 'gold_oos' : $name] = $sha;
        }
        $keys = array_keys($groups); sort($keys);
        return ['groups' => $keys, 'fixed_sha256' => $hashes];
    }
}

<?php

namespace App\Services\Report;

use RuntimeException;

/** Resolves files only; package integrity and rights require separate qualification. */
class GenericXbrlPackageRoot
{
    public function resolve(string $relativePath): string
    {
        $configured = config('services.report.generic_xbrl_root');
        if ($configured === null) {
            throw new RuntimeException('generic_xbrl_root_missing');
        }
        if (! is_string($configured) || preg_match('/[\x00-\x1f\x7f]/', $configured)) {
            throw new RuntimeException('generic_xbrl_root_invalid');
        }

        // Backslashes are separators only on the platform that supports them.
        if (PHP_OS_FAMILY !== 'Windows' && str_contains($configured, '\\')) {
            throw new RuntimeException('generic_xbrl_root_invalid');
        }
        $root = str_replace('\\', '/', $configured);
        $windows = PHP_OS_FAMILY === 'Windows';
        if ($windows ? ! preg_match('/\A[A-Za-z]:\//', $root)
            : ! str_starts_with($root, '/') || str_starts_with($root, '//')) {
            throw new RuntimeException('generic_xbrl_root_invalid');
        }

        $anchor = $windows ? substr($root, 0, 3) : '/';
        if (strlen($root) > strlen($anchor) && str_ends_with($root, '/')) {
            $root = substr($root, 0, -1);
        }
        $suffix = substr($root, strlen($anchor));
        $components = $suffix === '' ? [] : explode('/', $suffix);
        $this->validateComponents($components, 'generic_xbrl_root_invalid');

        $current = $anchor;
        $this->physicalPath($current, 'generic_xbrl_root_invalid', 'generic_xbrl_root_invalid');
        if (! is_dir($current) || ! is_readable($current)) {
            throw new RuntimeException('generic_xbrl_root_invalid');
        }
        foreach ($components as $component) {
            $current = rtrim($current, '/').'/'.$component;
            $this->physicalPath($current, 'generic_xbrl_root_invalid', 'generic_xbrl_root_invalid');
            if (! is_dir($current) || ! is_readable($current)) {
                throw new RuntimeException('generic_xbrl_root_invalid');
            }
        }

        foreach ([dirname(base_path(), 2), base_path(), storage_path()] as $protected) {
            clearstatcache(true);
            $physical = realpath($protected);
            if ($physical === false) {
                throw new RuntimeException('generic_xbrl_root_invalid');
            }
            // Also reject ancestors: they would allow lookup into a protected tree.
            foreach ([$protected, $physical] as $boundary) {
                if ($this->within($root, $boundary) || $this->within($boundary, $root)) {
                    throw new RuntimeException('generic_xbrl_root_invalid');
                }
            }
        }

        if ($relativePath === '' || str_starts_with($relativePath, '/')
            || str_contains($relativePath, '\\') || preg_match('/[\x00-\x1f\x7f]/', $relativePath)) {
            throw new RuntimeException('generic_xbrl_path_invalid');
        }
        $components = explode('/', $relativePath);
        $this->validateComponents($components, 'generic_xbrl_path_invalid');

        $current = rtrim($root, '/');
        $last = count($components) - 1;
        foreach ($components as $index => $component) {
            $current .= '/'.$component;
            $physical = $this->physicalPath($current, 'generic_xbrl_path_invalid', 'generic_xbrl_file_missing');
            if (! $this->within($physical, $root)) {
                throw new RuntimeException('generic_xbrl_path_invalid');
            }
            if (! is_readable($current) || ($index === $last ? ! is_file($current) : ! is_dir($current))) {
                throw new RuntimeException('generic_xbrl_file_missing');
            }
        }

        return rtrim($configured, '/\\').'/'.$relativePath;
    }

    /** @param list<string> $components */
    private function validateComponents(array $components, string $code): void
    {
        foreach ($components as $component) {
            if ($component === '' || $component === '.' || $component === '..' || str_contains($component, ':')) {
                throw new RuntimeException($code);
            }
            if (PHP_OS_FAMILY === 'Windows'
                && (preg_match('/[<>"|?*]/', $component) || preg_match('/[. ]\z/', $component)
                    || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9\x{00b9}\x{00b2}\x{00b3}]|LPT[1-9\x{00b9}\x{00b2}\x{00b3}])(?:\.|\z)/iu', $component))) {
                throw new RuntimeException($code);
            }
        }
    }

    private function physicalPath(string $path, string $invalidCode, string $missingCode): string
    {
        // PHP expands Windows junctions only one level; check every component.
        // https://www.php.net/manual/en/function.realpath.php
        clearstatcache(true);
        if (is_link($path)) {
            throw new RuntimeException($invalidCode);
        }
        $physical = realpath($path);
        if ($physical === false) {
            throw new RuntimeException($missingCode);
        }
        if ($this->comparisonPath($physical) !== $this->comparisonPath($path)) {
            throw new RuntimeException($invalidCode);
        }

        return $physical;
    }

    private function within(string $path, string $root): bool
    {
        $path = $this->comparisonPath($path);
        $root = $this->comparisonPath($root);

        return $path === $root || str_starts_with($path, $root.'/');
    }

    private function comparisonPath(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}

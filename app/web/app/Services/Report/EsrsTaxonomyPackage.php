<?php

namespace App\Services\Report;

class EsrsTaxonomyPackage
{
    /**
     * @param  array<string,mixed>  $manifest
     */
    public function __construct(
        private readonly string $root,
        private readonly array $manifest,
    ) {}

    public function version(): string
    {
        return $this->manifest['version'];
    }

    /** @return array<string,string|int> */
    public function metadata(): array
    {
        return [
            'schema_version' => $this->manifest['schema_version'],
            'version' => $this->manifest['version'],
            'source_url' => $this->manifest['source_url'],
            'published_date' => $this->manifest['published_date'],
            'zip_sha256' => $this->manifest['zip_sha256'],
            'entrypoint' => $this->manifest['entrypoint'],
            'core_entrypoint' => $this->manifest['core_entrypoint'],
            'catalog' => $this->manifest['catalog'],
            'taxonomy_package' => $this->manifest['taxonomy_package'],
            'member_count' => count($this->manifest['members']),
        ];
    }

    public function entrypointPath(): string
    {
        return $this->root.'/'.$this->manifest['entrypoint'];
    }

    public function coreEntrypointPath(): string
    {
        return $this->root.'/'.$this->manifest['core_entrypoint'];
    }

    public function catalogPath(): string
    {
        return $this->root.'/'.$this->manifest['catalog'];
    }
}

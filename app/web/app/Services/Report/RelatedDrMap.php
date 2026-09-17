<?php

namespace App\Services\Report;

class RelatedDrMap
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    /** @return array<int,array{target_dr:string,relation:string,resolution:string,citation:string}> */
    public function resolveEdges(string $drKey, array $inScopeDrKeys): array
    {
        $edges = $this->load()['edges'][$drKey] ?? [];
        $inScope = array_flip($inScopeDrKeys);

        return array_map(fn (array $edge) => [
            'target_dr' => $edge['target_dr'],
            'relation' => $edge['relation'],
            'resolution' => isset($inScope[$edge['target_dr']]) ? 'in_scope' : 'omitted',
            'citation' => $edge['citation'],
        ], $edges);
    }

    public function version(): string
    {
        return $this->load()['version'];
    }

    public function sha256(): string
    {
        return hash('sha256', file_get_contents($this->path()));
    }

    /** @return array<string,mixed> */
    private function load(): array
    {
        return self::$data ??= json_decode(file_get_contents($this->path()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function path(): string
    {
        return base_path('data/related_dr_map_esrs2023_v1.json');
    }
}

<?php

namespace App\Repositories;

use App\Models\NaceCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;
use Illuminate\Support\Str;

class NaceCodeRepository
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = NaceCode::query()->orderBy('code');

        if (array_key_exists('level', $filters) && $filters['level'] !== null && $filters['level'] !== '') {
            $query->where('level', (int) $filters['level']);
        }

        if (array_key_exists('parent_code', $filters) && $filters['parent_code'] !== null && $filters['parent_code'] !== '') {
            $query->where('parent_code', $filters['parent_code']);
        }

        $perPage = (int) data_get($filters, 'per_page', 50);

        if ($search = trim((string) data_get($filters, 'search'))) {
            return $this->searchInMemory($query, $search, $perPage);
        }

        return $query->paginate($perPage);
    }

    public function options(string $locale = 'en'): Collection
    {
        $field = $locale === 'es' ? 'title_es' : 'title_en';

        return NaceCode::query()
            ->orderBy('code')
            ->get()
            ->map(fn (NaceCode $code) => [
                'code' => $code->code,
                'label' => trim($code->code . ' - ' . $code->{$field}),
                'level' => $code->level,
                'parent_code' => $code->parent_code,
            ]);
    }

    public function findByCode(?string $code): ?NaceCode
    {
        if (! $code) {
            return null;
        }

        return NaceCode::where('code', $code)->first();
    }

    private function searchInMemory(Builder $query, string $search, int $perPage): LengthAwarePaginator
    {
        $searchLower = $this->searchableText($search);
        $matches = $query->get()
            ->filter(fn (NaceCode $code) => Str::contains($this->searchableText(implode(' ', [
                $code->code,
                $code->title_en,
                $code->title_es,
            ])), $searchLower))
            ->values();

        $page = LaravelLengthAwarePaginator::resolveCurrentPage();
        $pageItems = $matches->forPage($page, $perPage)->values();

        return new LaravelLengthAwarePaginator(
            $pageItems,
            $matches->count(),
            $perPage,
            $page,
            [
                'path' => LaravelLengthAwarePaginator::resolveCurrentPath(),
                'query' => request()->query(),
            ]
        );
    }

    private function searchableText(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}

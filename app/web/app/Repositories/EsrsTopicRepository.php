<?php

namespace App\Repositories;

use App\Models\EsrsTopic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class EsrsTopicRepository
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = EsrsTopic::query()->orderBy('esrs_code');

        if ($code = data_get($filters, 'esrs_code')) {
            $query->where('esrs_code', $code);
        }

        if ($search = trim((string) data_get($filters, 'search'))) {
            $searchLower = Str::lower($search);
            $query->where(function ($q) use ($searchLower) {
                $q->whereRaw('lower(theme_en) like ?', ["%{$searchLower}%"])
                    ->orWhereRaw('lower(theme_es) like ?', ["%{$searchLower}%"])
                    ->orWhereRaw('lower(subtheme_en) like ?', ["%{$searchLower}%"])
                    ->orWhereRaw('lower(subtheme_es) like ?', ["%{$searchLower}%"])
                    ->orWhereRaw('lower(subtopic_en) like ?', ["%{$searchLower}%"])
                    ->orWhereRaw('lower(subtopic_es) like ?', ["%{$searchLower}%"]);
            });
        }

        $perPage = (int) data_get($filters, 'per_page', 50);

        return $query->paginate($perPage);
    }

    public function options(string $locale = 'en'): Collection
    {
        $themeField = $locale === 'es' ? 'theme_es' : 'theme_en';
        $subthemeField = $locale === 'es' ? 'subtheme_es' : 'subtheme_en';

        return EsrsTopic::query()
            ->orderBy('esrs_code')
            ->get()
            ->groupBy(fn (EsrsTopic $topic) => $topic->esrs_code.' - '.$topic->{$themeField})
            ->map(function ($items, $groupLabel) use ($subthemeField) {
                return [
                    'label' => $groupLabel,
                    'options' => $items->map(fn (EsrsTopic $topic) => [
                        'id' => $topic->id,
                        'label' => $topic->{$subthemeField},
                        'subtopic' => [
                            'en' => $topic->subtopic_en,
                            'es' => $topic->subtopic_es,
                        ],
                    ])->values(),
                ];
            })
            ->values();
    }

    public function findByIds(array $ids): Collection
    {
        if (empty($ids)) {
            return new Collection();
        }

        return EsrsTopic::whereIn('id', $ids)
            ->orderBy('esrs_code')
            ->get();
    }
}

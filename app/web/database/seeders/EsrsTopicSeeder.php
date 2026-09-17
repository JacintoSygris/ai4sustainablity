<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class EsrsTopicSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = base_path('data/esrs_topics.json');

        if (! File::exists($path)) {
            $this->command?->error('Missing data file: data/esrs_topics.json');

            return;
        }

        $payload = json_decode(File::get($path), true);

        if (! is_array($payload)) {
            $this->command?->error('Invalid JSON structure for ESRS topics.');

            return;
        }

        DB::table('esrs_topics')->truncate();

        $now = now();
        $records = [];

        foreach ($payload as $item) {
            $themeEs = $item['theme']['es'] ?? '';
            $subthemeEs = $item['subtheme']['es'] ?? '';
            $subtopicEs = $item['subtopic']['es'] ?? null;

            $hashSource = implode('|', [
                $item['esrs'] ?? '',
                Str::lower($themeEs),
                Str::lower($subthemeEs),
                Str::lower($subtopicEs ?? ''),
            ]);

            $records[] = [
                'esrs_code' => $item['esrs'] ?? '',
                'theme_es' => $themeEs,
                'theme_en' => $item['theme']['en'] ?? '',
                'subtheme_es' => $subthemeEs,
                'subtheme_en' => $item['subtheme']['en'] ?? '',
                'subtopic_es' => $subtopicEs,
                'subtopic_en' => $item['subtopic']['en'] ?? null,
                'examples_es' => json_encode($item['examples']['es'] ?? []),
                'examples_en' => json_encode($item['examples']['en'] ?? []),
                'competency_1' => (bool) ($item['tags']['competencies']['competency_1'] ?? false),
                'competency_2' => (bool) ($item['tags']['competencies']['competency_2'] ?? false),
                'competency_3' => (bool) ($item['tags']['competencies']['competency_3'] ?? false),
                'regulation_1' => (bool) ($item['tags']['regulations']['regulation_1'] ?? false),
                'regulation_2' => (bool) ($item['tags']['regulations']['regulation_2'] ?? false),
                'regulation_3' => (bool) ($item['tags']['regulations']['regulation_3'] ?? false),
                'internal_strategy' => (bool) ($item['tags']['internal']['strategy'] ?? false),
                'internal_activities' => (bool) ($item['tags']['internal']['activities'] ?? false),
                'internal_policies' => (bool) ($item['tags']['internal']['policies'] ?? false),
                'internal_regulations' => (bool) ($item['tags']['internal']['regulations'] ?? false),
                'consolidated' => (bool) ($item['consolidated'] ?? false),
                'hash' => hash('sha256', $hashSource),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, 200) as $chunk) {
            DB::table('esrs_topics')->insert($chunk);
        }

        $this->command?->info('Seeded ESRS topics: '.count($records));
    }
}

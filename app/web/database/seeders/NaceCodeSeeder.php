<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class NaceCodeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = base_path('data/nace_codes.json');

        if (! File::exists($path)) {
            $this->command?->error('Missing data file: data/nace_codes.json');

            return;
        }

        $payload = json_decode(File::get($path), true);

        if (! is_array($payload)) {
            $this->command?->error('Invalid JSON structure for NACE codes.');

            return;
        }

        DB::table('nace_codes')->truncate();

        $now = now();
        $records = [];

        foreach ($payload as $item) {
            $records[] = [
                'code' => $item['code'] ?? '',
                'level' => (int) ($item['level'] ?? 0),
                'parent_code' => $item['parent'] ?? null,
                'title_en' => $item['title']['en'] ?? '',
                'title_es' => $item['title']['es'] ?? '',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, 200) as $chunk) {
            DB::table('nace_codes')->insert($chunk);
        }

        $this->command?->info('Seeded NACE codes: '.count($records));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\DmaImport;
use App\Models\EsrsTopic;
use App\Services\CharacterizationStateTransaction;
use App\Services\Dma\DmaCsvParser;
use App\Services\Dma\DmaSuggestionBuilder;
use App\Support\DoubleMaterialityGuide;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DmaImportController extends Controller
{
    public function show(Request $request, int $characterization)
    {
        $this->owner($request, $characterization);
        $referenced = null;
        if ($request->has('import_ids')) {
            abort_unless(\App\Services\Dma\P8Authority::enabled(), 404);
            $validated = $request->validate([
                'import_ids' => ['required', 'array', 'min:1', 'max:20'],
                'import_ids.*' => ['required', 'integer', 'min:1', 'max:9007199254740991', 'distinct'],
            ]);
            $referenced = DmaImport::where('characterization_id', $characterization)
                ->where('user_id', $request->user()->id)->whereIn('id', $validated['import_ids'])->orderBy('id')->get();
            abort_unless($referenced->count() === count($validated['import_ids']), 404);
        }
        $import = DmaImport::where('characterization_id', $characterization)
            ->where('user_id', $request->user()->id)->latest('id')->first();

        return response()->json(['data' => $import?->presentation(),
            ...($referenced === null ? [] : ['referenced_imports' => $referenced->map(fn (DmaImport $record) => $record->presentation())->all()]),
        ]);
    }

    public function store(Request $request, int $characterization, DmaCsvParser $parser,
        DmaSuggestionBuilder $builder, CharacterizationStateTransaction $transactions)
    {
        $this->owner($request, $characterization);
        $rules = ['file', 'max:1024', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel', 'extensions:csv'];
        $request->validate(['iro_file' => ['required', ...$rules], 'stakeholder_file' => ['sometimes', ...$rules]]);
        $sources = []; $rows = [];
        $topics = EsrsTopic::query()->pluck('id')->all();
        foreach (['iro_file' => 'iro_register', 'stakeholder_file' => 'stakeholder_consultation_log'] as $field => $template) {
            if (! $request->hasFile($field)) { continue; }
            $file = $request->file($field);
            $name = $file->getClientOriginalName();
            if (! mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
                throw ValidationException::withMessages([$field => __('Use a filename of at most 255 characters without control characters.')]);
            }
            $content = file_get_contents($file->getRealPath());
            $rows[$field] = $parser->parse($content, $template, $topics, $field);
            $sources[$field] = ['original_name' => $name, 'sha256' => hash('sha256', $content), 'bytes' => strlen($content)];
        }
        if (\App\Services\Dma\P8Authority::enabled()) {
            $rows['iro_file'] = $parser->identifyRows($rows['iro_file'], $characterization, $sources['iro_file']['sha256']);
        }
        $payload = $builder->build($rows['iro_file'], $rows['stakeholder_file'] ?? []);
        $import = $transactions->run($characterization, function (Characterization $locked) use ($request, $sources, $payload) {
            abort_unless((int) $locked->user_id === (int) $request->user()->id, 404);
            if (DmaImport::where('characterization_id', $locked->id)->count() >= 20) {
                throw ValidationException::withMessages(['iro_file' => __('This characterization has reached the 20-import limit.')]);
            }
            return DmaImport::create(['characterization_id' => $locked->id, 'user_id' => $request->user()->id,
                'sources' => $sources, 'payload' => $payload, 'uploaded_at' => now()]);
        });

        return response()->json(['data' => $import->presentation()], 201);
    }

    public function export(Request $request, int $characterization, int $import)
    {
        $this->owner($request, $characterization);
        $record = DmaImport::where('characterization_id', $characterization)
            ->where('user_id', $request->user()->id)->findOrFail($import);
        $handle = fopen('php://temp', 'r+');
        $columns = DoubleMaterialityGuide::template('iro_register')['columns'];
        fputcsv($handle, array_map(fn ($column) => $column['label'][app()->getLocale() === 'en' ? 'en' : 'es'], $columns), ',', '"', '');
        foreach ($record->payload['iro_rows'] as $row) {
            fputcsv($handle, array_map(fn ($column) => self::safeCell($row[$column['key']]), $columns), ',', '"', '');
        }
        rewind($handle); $content = stream_get_contents($handle); fclose($handle);
        return response($content, 200, ['Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="dma-import-'.$record->id.'.csv"']);
    }

    private function owner(Request $request, int $id): Characterization
    {
        abort_unless(config('services.dma_import.enabled', false), 404);
        return Characterization::forUser($request->user()->id)->findOrFail($id);
    }

    public static function safeCell(string $value): string
    {
        // Include whitespace/control prefixes: never evaluate a user-controlled formula.
        return preg_match('/\A[\s\x{FEFF}]*[=+\-@]/u', $value) || preg_match('/\A[\t\r\n]/', $value) ? "'".$value : $value;
    }
}

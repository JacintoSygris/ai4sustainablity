<?php

namespace App\Services\Dma;

use App\Support\DoubleMaterialityGuide;
use Illuminate\Validation\ValidationException;

final class DmaCsvParser
{
    public const MAX_BYTES = 1_048_576;
    public const MAX_ROWS = 1000;
    public const MAX_CELL_BYTES = 2000;

    public function parse(string $content, string $template, array $topicIds, string $field): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            $this->fail($field, 'The CSV exceeds the 1 MiB limit.');
        }
        if (! mb_check_encoding($content, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content)) {
            $this->fail($field, 'The CSV must be UTF-8 text without control characters.');
        }
        $content = preg_replace('/\A\xEF\xBB\xBF/', '', $content);
        $columns = DoubleMaterialityGuide::template($template)['columns'];
        $aliases = [];
        foreach ($columns as $column) {
            foreach ([$column['key'], ...array_values($column['label'])] as $label) {
                $aliases[mb_strtolower(trim($label))] = $column['key'];
            }
        }
        $delimiter = ',';
        $firstLine = strtok($content, "\r\n");
        foreach ([',', ';'] as $candidate) {
            if (count(str_getcsv($firstLine ?: '', $candidate, '"', '')) === count($columns)) {
                $delimiter = $candidate;
                break;
            }
        }
        $records = $this->records($content, $delimiter, $field);
        $header = array_shift($records)['cells'] ?? [];
        $keys = array_map(fn ($value) => $aliases[mb_strtolower(trim($value))] ?? null, $header);
        if (count($keys) !== count($columns) || in_array(null, $keys, true) || count(array_unique($keys)) !== count($keys)) {
            $this->fail($field.'.row.1', 'Use the complete ES or EN headers from the P7 template, without duplicate columns.');
        }
        $validTopics = array_fill_keys($topicIds, true);
        $rows = [];
        $errors = [];
        foreach ($records as $record) {
            $line = $record['row'];
            if (count($record['cells']) !== count($keys)) {
                $errors[$field.'.row.'.$line] = __('The row has a different number of columns than the header.');
                continue;
            }
            $row = array_combine($keys, $record['cells']);
            $check = function (string $column, bool $valid, string $message) use (&$errors, $field, $line): void {
                if (! $valid) { $errors[$field.'.row.'.$line.'.'.$column] = __($message); }
            };
            if ($template === 'iro_register') {
                $id = trim($row['ar16_topic_id']);
                $check('ar16_topic_id', preg_match('/\A[1-9][0-9]{0,8}\z/D', $id) === 1 && isset($validTopics[(int) $id]), 'Use an existing AR16 topic ID.');
                $check('iro_description', trim($row['iro_description']) !== '', 'Describe the impact, risk or opportunity.');
                $check('iro_type', in_array(mb_strtolower(trim($row['iro_type'])), ['impact', 'risk', 'opportunity', 'impacto', 'riesgo', 'oportunidad'], true), 'Use impact, risk or opportunity (ES or EN).');
                foreach (['impact_materiality_score', 'financial_materiality_score'] as $column) {
                    $value = trim($row[$column]);
                    $check($column, $value === '' || $this->flag($value) !== null || $this->score($value) !== null, 'Use a non-negative score up to 1000000000, material/no material, or leave blank.');
                }
                $check('threshold_result', $this->verdict($row['threshold_result']) !== null, 'Use material, no material, or revisar/review; leave blank to request review.');
                $row['topic_id'] = (int) $id;
            } else {
                $date = trim($row['date']);
                $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                $check('date', $parsedDate !== false && $parsedDate->format('Y-m-d') === $date && $date <= now()->toDateString(), 'Use a valid date YYYY-MM-DD, no later than today.');
                foreach (['stakeholder_group', 'source', 'input_summary'] as $column) {
                    $check($column, trim($row[$column]) !== '', 'This consultation field is required.');
                }
                // P7 has a free-text link column. Resolve ONLY explicit IDs; never guess a topic from prose.
                $link = trim($row['matter_or_iro']);
                $topic = preg_match('/\A(?:AR16:)?([1-9][0-9]{0,8})\z/iD', $link, $match) === 1 ? (int) $match[1] : null;
                $iro = preg_match('/\AIRO:([1-9][0-9]{0,8})\z/iD', $link, $match) === 1 ? (int) $match[1] : null;
                $check('matter_or_iro', ($topic !== null && isset($validTopics[$topic])) || $iro !== null, 'Link the consultation using an AR16 topic ID, AR16:ID or IRO:CSV-row.');
                $row['topic_id'] = $topic;
                $row['iro_row'] = $iro;
            }
            $rows[] = ['row' => $line, ...$row];
        }
        if ($errors !== []) { throw ValidationException::withMessages($errors); }
        if ($rows === []) { $this->fail($field, 'The CSV contains no data rows.'); }
        return $rows;
    }

    /** Project legacy rows without rewriting the append-only import. */
    public function identifyRows(array $rows, int $characterizationId, string $sourceSha256): array
    {
        return array_map(static function (array $row) use ($characterizationId, $sourceSha256): array {
            $row['iro_id'] = hash('sha256', 'dma-iro-v1|'.$characterizationId.'|'.$sourceSha256.'|'.$row['row']);
            $row['canonical_type'] = match (mb_strtolower(trim($row['iro_type']))) {
                'impact', 'impacto' => 'impact', 'risk', 'riesgo' => 'risk',
                'opportunity', 'oportunidad' => 'opportunity',
            };
            return $row;
        }, $rows);
    }

    public function flag(string $value): ?bool
    {
        return match (mb_strtolower(trim($value))) {
            'material', 'yes', 'sí', 'si', 'true' => true,
            'no material', 'no_material', 'not material', 'no', 'false' => false,
            default => null,
        };
    }

    public function verdict(string $value): ?string
    {
        return match (mb_strtolower(trim($value))) {
            'material' => 'material',
            'no material', 'no_material', 'not material' => 'no_material',
            '', 'revisar', 'review' => 'review',
            default => null,
        };
    }

    public function score(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));
        if (preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $value) !== 1) { return null; }
        $number = (float) $value;
        return is_finite($number) && $number <= 1_000_000_000 ? $number : null;
    }

    /** RFC4180 scanner: fgetcsv alone accepts unterminated quotes and corrupt rows. */
    private function records(string $content, string $delimiter, string $field): array
    {
        $records = []; $cells = []; $cell = ''; $state = 'start'; $line = 1; $start = 1;
        $length = strlen($content);
        for ($i = 0; $i <= $length; $i++) {
            $char = $i === $length ? null : $content[$i];
            if ($state === 'quoted') {
                if ($char === null) { $this->fail($field.'.row.'.$start, 'The CSV has malformed quoting.'); }
                if ($char === '"') {
                    if (($content[$i + 1] ?? null) === '"') { $cell .= '"'; $i++; }
                    else { $state = 'closed'; }
                } else {
                    $cell .= $char;
                    if ($char === "\n" || ($char === "\r" && ($content[$i + 1] ?? '') !== "\n")) { $line++; }
                }
            } elseif ($char === $delimiter || $char === "\n" || $char === "\r" || $char === null) {
                $cells[] = $cell; $cell = ''; $state = 'start';
                if ($char !== $delimiter) {
                    if ($cells !== ['']) { $records[] = ['row' => $start, 'cells' => $cells]; }
                    if (count($records) > self::MAX_ROWS + 1) { $this->fail($field, 'The CSV exceeds the 1000-row limit.'); }
                    $cells = [];
                    if ($char === "\r" && ($content[$i + 1] ?? '') === "\n") { $i++; }
                    $line++; $start = $line;
                }
            } elseif ($char === '"' && $state === 'start') {
                $state = 'quoted';
            } elseif ($char === '"' || $state === 'closed') {
                $this->fail($field.'.row.'.$start, 'The CSV has malformed quoting.');
            } else {
                $cell .= $char; $state = 'unquoted';
            }
            if (strlen($cell) > self::MAX_CELL_BYTES || count($cells) > 10) {
                $this->fail($field.'.row.'.$start, 'A CSV cell exceeds 2000 bytes or the row has too many columns.');
            }
        }
        return $records;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}

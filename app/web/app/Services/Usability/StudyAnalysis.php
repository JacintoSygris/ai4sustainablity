<?php

namespace App\Services\Usability;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StudyAnalysis
{
    public const STEPS = ['P5', 'P6', 'P7', 'P8', 'P9', 'P10'];

    public static function sus(array $answers): float
    {
        if (! array_is_list($answers) || count($answers) !== 10) {
            throw new InvalidArgumentException('Exactly ten SUS responses are required.');
        }
        $total = 0;
        foreach ($answers as $i => $answer) {
            if (! is_int($answer) || $answer < 1 || $answer > 5) {
                throw new InvalidArgumentException('SUS responses must be integers from 1 to 5.');
            }
            $total += $i % 2 === 0 ? $answer - 1 : 5 - $answer;
        }
        return $total * 2.5;
    }

    public static function summary(array $values): array
    {
        $n = count($values);
        if ($n === 0) {
            return ['n' => 0, 'mean' => null, 'sd' => null, 'ci95' => null];
        }
        $mean = (float) array_sum($values) / $n;
        if ($n === 1) {
            return ['n' => 1, 'mean' => $mean, 'sd' => null, 'ci95' => null];
        }
        $sd = sqrt(array_sum(array_map(static fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1));
        // Two-sided Student t(.975), df 1..30. For larger samples use df=30
        // conservatively rather than pretending small formative samples are normal.
        $t = [12.706205, 4.302653, 3.182446, 2.776445, 2.570582, 2.446912,
            2.364624, 2.306004, 2.262157, 2.228139, 2.200985, 2.178813,
            2.160369, 2.144787, 2.131450, 2.119905, 2.109816, 2.100922,
            2.093024, 2.085963, 2.079614, 2.073873, 2.068658, 2.063899,
            2.059539, 2.055529, 2.051831, 2.048407, 2.045230, 2.042272];
        $margin = $t[min($n - 2, 29)] * $sd / sqrt($n);
        return ['n' => $n, 'mean' => $mean, 'sd' => $sd, 'ci95' => [$mean - $margin, $mean + $margin]];
    }

    public function report(): array
    {
        // Consistent snapshot; withdrawal is serialized with participant writers.
        return DB::transaction(function (): array {
            $participants = DB::table('usability_participants')->orderBy('study_participant_id')->lockForUpdate()->get();
            $rows = [];
            $allTasks = [];
            $scores = [];
            foreach ($participants as $participant) {
                $tasks = DB::table('usability_tasks')->where('study_participant_id', $participant->study_participant_id)->get()->all();
                $questionnaire = DB::table('usability_questionnaires')->where('study_participant_id', $participant->study_participant_id)->first();
                $score = $questionnaire ? self::sus(json_decode($questionnaire->answers, true, 512, JSON_THROW_ON_ERROR)) : null;
                if ($score !== null) {
                    $scores[] = $score;
                }
                $metrics = self::tasks($tasks, 1);
                $rows[] = ['study_participant_id' => $participant->study_participant_id, 'sus' => $score, ...$metrics];
                array_push($allTasks, ...$tasks);
            }
            return ['participants' => $rows, 'aggregate' => [
                'participants' => count($rows), 'sus' => self::summary($scores),
                ...self::tasks($allTasks, count($rows)),
            ]];
        });
    }

    public static function tasks(array $tasks, int $participants): array
    {
        $byStep = [];
        foreach (self::STEPS as $step) {
            $attempts = array_values(array_filter($tasks, static fn ($task) => $task->step === $step));
            $completed = array_values(array_filter($attempts, static fn ($task) => $task->outcome === 'complete'));
            $ended = array_values(array_filter($attempts, static fn ($task) => $task->ended_at !== null));
            $errors = array_values(array_filter($attempts, static fn ($task) => $task->error_count !== null));
            $seconds = static fn ($task): float => max(0.0, (float) CarbonImmutable::parse($task->started_at)->diffInSeconds(CarbonImmutable::parse($task->ended_at)));
            $successfulParticipants = count(array_unique(array_column($completed, 'study_participant_id')));
            $byStep[$step] = [
                'started' => count($attempts), 'completed' => count($completed),
                'abandoned' => count(array_filter($attempts, static fn ($task) => $task->outcome === 'abandon')),
                'open' => count($attempts) - count($ended),
                'successful_participants' => $successfulParticipants,
                'completion_rate' => $participants > 0 ? $successfulParticipants / $participants : null,
                'attempt_completion_rate' => count($attempts) > 0 ? count($completed) / count($attempts) : null,
                'completed_seconds' => self::summary(array_map($seconds, $completed)),
                'ended_seconds' => self::summary(array_map($seconds, $ended)),
                'error_count' => count($errors) > 0 ? array_sum(array_column($errors, 'error_count')) : null,
                'error_observed_attempts' => count($errors),
            ];
        }
        return [
            'completion_rate' => $participants > 0 ? array_sum(array_column($byStep, 'successful_participants')) / ($participants * count(self::STEPS)) : null,
            'tasks' => $byStep,
        ];
    }

    public static function csvCell(string $value): string
    {
        return preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $value) === 1 || preg_match('/^[\t\r\n]/', $value) === 1 ? "'".$value : $value;
    }

    public function csv(array $report): string
    {
        $stream = fopen('php://temp', 'w+');
        $write = static function (array $row) use ($stream): void {
            fputcsv($stream, array_map(static fn ($v) => self::csvCell($v === null ? '' : (string) $v), $row), ',', '"', '');
        };
        $write(['study_participant_id', 'sus', 'completion_rate', 'step', 'started', 'completed', 'abandoned', 'open', 'task_completion_rate', 'attempt_completion_rate', 'mean_completed_seconds', 'mean_ended_seconds', 'error_count', 'error_observed_attempts']);
        foreach ($report['participants'] as $participant) {
            foreach ($participant['tasks'] as $step => $metrics) {
                $write([$participant['study_participant_id'], $participant['sus'], $participant['completion_rate'], $step,
                    $metrics['started'], $metrics['completed'], $metrics['abandoned'], $metrics['open'], $metrics['completion_rate'],
                    $metrics['attempt_completion_rate'], $metrics['completed_seconds']['mean'], $metrics['ended_seconds']['mean'],
                    $metrics['error_count'], $metrics['error_observed_attempts']]);
            }
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }
}

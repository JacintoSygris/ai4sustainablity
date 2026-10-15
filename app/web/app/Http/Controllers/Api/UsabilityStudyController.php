<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Usability\StudyAnalysis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UsabilityStudyController extends Controller
{
    private function enabled(): void
    {
        abort_unless(config('usability.enabled'), 404);
    }

    private function locked(Request $request, callable $callback): mixed
    {
        return DB::transaction(function () use ($request, $callback) {
            // Same lock for enrolment, writes and erasure. A late write cannot
            // resurrect a withdrawn participant or target a new enrolment UUID.
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->firstOrFail();
            $participant = DB::table('usability_participants')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            return $callback($participant);
        });
    }

    private function consenting(Request $request, ?object $participant): object
    {
        abort_unless($participant !== null
            && hash_equals($participant->study_participant_id, (string) $request->input('study_participant_id'))
            && $participant->consent_version === config('usability.consent_version')
            && $participant->enrolled_at > now()->subDays(90)->format('Y-m-d H:i:s'), 403);
        return $participant;
    }

    public function show(Request $request)
    {
        $this->enabled();
        return $this->locked($request, function ($participant) {
            $consented = $participant !== null && $participant->consent_version === config('usability.consent_version')
                && $participant->enrolled_at > now()->subDays(90)->format('Y-m-d H:i:s');
            $questionnaire = $consented ? DB::table('usability_questionnaires')
                ->where('study_participant_id', $participant->study_participant_id)->first() : null;
            return response()->json(['data' => [
                'consent_version' => config('usability.consent_version'),
                'study_participant_id' => $participant?->study_participant_id,
                'consented' => $consented,
                'questionnaire' => $questionnaire === null ? null : [
                    'answers' => json_decode($questionnaire->answers, true, 512, JSON_THROW_ON_ERROR),
                    'comments' => $questionnaire->comments, 'locale' => $questionnaire->locale,
                ],
                'p10_completed' => $participant !== null && DB::table('usability_tasks')->where('study_participant_id', $participant->study_participant_id)->where('step', 'P10')->where('outcome', 'complete')->exists(),
                'questionnaire_submitted' => $participant !== null && DB::table('usability_questionnaires')->where('study_participant_id', $participant->study_participant_id)->exists(),
            ]])->header('Cache-Control', 'no-store');
        });
    }

    public function enrol(Request $request)
    {
        $this->enabled();
        $request->validate([
            'consent' => ['required', 'accepted'],
            'consent_version' => ['required', Rule::in([config('usability.consent_version')])],
        ]);
        return $this->locked($request, function ($participant) use ($request) {
            if ($participant !== null && $participant->enrolled_at <= now()->subDays(90)->format('Y-m-d H:i:s')) {
                DB::table('usability_participants')->where('study_participant_id', $participant->study_participant_id)->delete();
                $participant = null;
            }
            if ($participant === null) {
                $id = (string) Str::uuid();
                DB::table('usability_participants')->insert([
                    'study_participant_id' => $id, 'user_id' => $request->user()->id,
                    'consent_version' => config('usability.consent_version'), 'consented_at' => now(), 'enrolled_at' => now(),
                ]);
            } else {
                // Re-consent after a protocol version change keeps existing data
                // but requires a new explicit action, never an implicit upgrade.
                $id = $participant->study_participant_id;
                if ($participant->consent_version !== config('usability.consent_version')) {
                    DB::table('usability_participants')->where('study_participant_id', $id)->update([
                        'consent_version' => config('usability.consent_version'), 'consented_at' => now(),
                    ]);
                }
            }
            return response()->json(['data' => ['study_participant_id' => $id]])->header('Cache-Control', 'no-store');
        });
    }

    public function withdraw(Request $request)
    {
        // Erasure stays available when collection is switched off.
        if (Schema::hasTable('usability_participants')) {
            $this->locked($request, function ($participant) {
                if ($participant !== null) {
                    DB::table('usability_participants')->where('study_participant_id', $participant->study_participant_id)->delete();
                }
            });
        }
        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function event(Request $request)
    {
        $this->enabled();
        $data = $request->validate([
            'study_participant_id' => ['required', 'uuid'],
            'task_id' => ['required', 'uuid'],
            'step' => ['required', Rule::in(StudyAnalysis::STEPS)],
            'event' => ['required', Rule::in(['start', 'complete', 'abandon'])],
            'error_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);
        return $this->locked($request, function ($participant) use ($request, $data) {
            $participant = $this->consenting($request, $participant);
            $task = DB::table('usability_tasks')->where('id', $data['task_id'])->first();
            $timestamp = now();
            if ($data['event'] === 'start') {
                if ($task !== null) {
                    abort_unless($task->study_participant_id === $participant->study_participant_id && $task->step === $data['step'], 403);
                    return response()->noContent()->header('Cache-Control', 'no-store'); // Idempotent retry, including after completion.
                }
                abort_if(DB::table('usability_tasks')->where('study_participant_id', $participant->study_participant_id)->count() >= 1000, 422);
                DB::table('usability_tasks')->insert([
                    'id' => $data['task_id'], 'study_participant_id' => $participant->study_participant_id,
                    'step' => $data['step'], 'started_at' => $timestamp,
                ]);
            } else {
                abort_unless($task !== null && $task->study_participant_id === $participant->study_participant_id && $task->step === $data['step'], 403);
                if ($task->outcome !== null) {
                    abort_unless($task->outcome === $data['event'], 409);
                    return response()->noContent()->header('Cache-Control', 'no-store');
                }
                DB::table('usability_tasks')->where('id', $task->id)->update([
                    'ended_at' => $timestamp, 'outcome' => $data['event'], 'error_count' => $data['error_count'] ?? null,
                ]);
            }
            DB::table('usability_events')->insert([
                'task_id' => $data['task_id'], 'event' => $data['event'], 'occurred_at' => $timestamp,
                'error_count' => $data['event'] === 'start' ? null : ($data['error_count'] ?? null),
            ]);
            return response()->noContent()->header('Cache-Control', 'no-store');
        });
    }

    public function questionnaire(Request $request)
    {
        $this->enabled();
        $data = $request->validate([
            'study_participant_id' => ['required', 'uuid'],
            'answers' => ['required', 'array', 'size:10', 'list'],
            'answers.*' => ['required', 'integer', 'between:1,5'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);
        return $this->locked($request, function ($participant) use ($request, $data) {
            $participant = $this->consenting($request, $participant);
            $answers = array_map('intval', $data['answers']);
            StudyAnalysis::sus($answers);
            // One submission per participant; repeat clicks cannot create
            // duplicate responses. Deliberate edits replace the same response.
            DB::table('usability_questionnaires')->updateOrInsert(['study_participant_id' => $participant->study_participant_id], [
                'answers' => json_encode($answers, JSON_THROW_ON_ERROR), 'locale' => app()->getLocale(),
                'comments' => $data['comments'] ?? null, 'submitted_at' => now(),
            ]);
            return response()->noContent()->header('Cache-Control', 'no-store');
        });
    }

    public function export(Request $request, StudyAnalysis $analysis)
    {
        $this->enabled();
        abort_unless(in_array((int) $request->user()->id, config('usability.admin_user_ids', []), true), 403);
        // Free text is deliberately excluded: it might disclose an identity.
        return response($analysis->csv($analysis->report()))
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="usability-study.csv"')
            ->header('Cache-Control', 'no-store');
    }
}

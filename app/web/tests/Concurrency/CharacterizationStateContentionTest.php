<?php

use App\Http\Controllers\Api\CharacterizationDocumentController;
use App\Http\Controllers\Api\ReportFactController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\ProfileController;
use App\Models\Characterization;
use App\Models\CharacterizationDocument;
use App\Models\EsrsTopic;
use App\Models\ReportAuditEvent;
use App\Models\ReportingFact;
use App\Models\User;
use App\Services\CharacterizationStateTransaction;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Services\Report\ReportingFactProjector;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class, DatabaseMigrations::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! extension_loaded('pcntl')) {
        $this->markTestSkipped('Requires the opt-in MySQL contention lane with pcntl.');
    }

    config([
        'services.private_dev.auto_login' => false,
        'services.esrs_datapoints.matter_dr_mapping_path' => base_path('data/ar16_to_esrs_dr_mapping_esrs2023_v1.json'),
    ]);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
});

it('serializes sibling form data writers and preserves both committed subtrees', function () {
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create([
        'user_id' => $user->id,
        'form_data' => [
            'materiality_confirmation' => ['revision' => 4, 'confirmed_topic_ids' => [1]],
            'esrs_datapoint_responses' => ['revision' => 7, 'responses' => []],
        ],
    ]);
    $barrier = contentionBarrier('form-data');

    DB::disconnect();
    $firstPid = forkContentionChild($barrier, function () use ($characterization, $barrier): void {
        app(CharacterizationStateTransaction::class)->run(
            $characterization->id,
            function (Characterization $locked) use ($barrier): void {
                file_put_contents($barrier['locked'], 'locked');
                waitForContentionPath($barrier['release'], 5000);
                $formData = $locked->form_data ?? [];
                Arr::set($formData, 'double_materiality_process.updated_at', '2026-09-24T10:00:00Z');
                $locked->forceFill(['form_data' => $formData])->save();
            },
        );
    });

    waitForContentionPath($barrier['locked'], 5000);
    DB::disconnect();
    $secondPid = forkContentionChild($barrier, function () use ($characterization, $barrier): void {
        app(CharacterizationStateTransaction::class)->run(
            $characterization->id,
            function (Characterization $locked) use ($barrier): void {
                file_put_contents($barrier['second_acquired'], 'acquired');
                $formData = $locked->form_data ?? [];
                Arr::set($formData, 'esrs_datapoint_responses.revision', 8);
                $locked->forceFill(['form_data' => $formData])->save();
            },
        );
    });

    usleep(250000);
    expect(file_exists($barrier['second_acquired']))->toBeFalse();
    file_put_contents($barrier['release'], 'release');
    waitForContentionChildren([$firstPid, $secondPid], $barrier);

    DB::reconnect();
    $fresh = Characterization::query()->findOrFail($characterization->id);
    expect(data_get($fresh->form_data, 'double_materiality_process.updated_at'))->toBe('2026-09-24T10:00:00Z')
        ->and(data_get($fresh->form_data, 'materiality_confirmation.revision'))->toBe(4)
        ->and(data_get($fresh->form_data, 'esrs_datapoint_responses.revision'))->toBe(8);

    removeContentionBarrier($barrier);
});

it('revalidates fact membership after a concurrent scope shrink commits', function () {
    $user = User::factory()->create();
    $topic = EsrsTopic::query()->where('esrs_code', 'E2')->firstOrFail();
    $characterization = Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$topic->id],
        'form_data' => [
            'operations' => ['employee_count_range' => '50_249'],
            'materiality_confirmation' => ['confirmed_topic_ids' => [$topic->id]],
            'esrs_datapoint_responses' => ['schema_version' => 'v0', 'responses' => []],
        ],
    ]);
    $corpus = app(EsrsDatapointCorpusBuilder::class)->build($characterization);
    $datapointId = data_get($corpus, 'blocks.topical.datapoints.0.id');
    expect($datapointId)->toBeString()->not->toBe('');
    $barrier = contentionBarrier('fact-membership');

    DB::disconnect();
    $scopePid = forkContentionChild($barrier, function () use ($characterization, $barrier): void {
        app(CharacterizationStateTransaction::class)->run(
            $characterization->id,
            function (Characterization $locked) use ($barrier): void {
                file_put_contents($barrier['locked'], 'locked');
                $formData = $locked->form_data ?? [];
                Arr::set($formData, 'materiality_confirmation.confirmed_topic_ids', []);
                $locked->forceFill(['esrs_topic_ids' => [], 'form_data' => $formData]);
                waitForContentionPath($barrier['release'], 5000);
                $locked->save();
            },
        );
    });

    waitForContentionPath($barrier['locked'], 5000);
    DB::disconnect();
    $factPid = forkContentionChild($barrier, function () use ($user, $datapointId, $barrier): void {
        $request = Request::create('/api/report/facts', 'PUT', [
            'facts' => [[
                'datapoint_id' => $datapointId,
                'applicability' => 'applicable',
                'value_type' => 'text',
                'value' => ['text' => 'Must not survive a concurrent scope shrink.'],
                'unit' => null,
                'decimals' => null,
                'dimensions' => [],
                'language' => 'en',
                'nil' => false,
                'nil_reason' => null,
                'evidence_refs' => [['type' => 'note', 'value' => 'Concurrency regression.']],
                'provenance' => 'api',
                'approval_status' => 'review_required',
                'blocking_reasons' => [],
            ]],
        ]);
        $request->setUserResolver(fn (): User => User::query()->findOrFail($user->id));
        $response = app(ReportFactController::class)->update(
            $request,
            app(ReportingFactProjector::class),
            app(EsrsDatapointCorpusBuilder::class),
        );
        file_put_contents($barrier['result'], json_encode([
            'status' => $response->getStatusCode(),
            'body' => $response->getData(true),
        ], JSON_THROW_ON_ERROR));
    });

    usleep(250000);
    expect(file_exists($barrier['result']))->toBeFalse();
    file_put_contents($barrier['release'], 'release');
    waitForContentionChildren([$scopePid, $factPid], $barrier);

    $result = json_decode(file_get_contents($barrier['result']), true, 512, JSON_THROW_ON_ERROR);
    DB::reconnect();
    expect($result['status'])->toBe(422)
        ->and(data_get($result, 'body.code'))->toBe('reporting_fact_invalid')
        ->and(ReportingFact::query()->where('characterization_id', $characterization->id)->count())->toBe(0)
        ->and(ReportAuditEvent::query()->where('characterization_id', $characterization->id)->count())->toBe(0);

    removeContentionBarrier($barrier);
});

it('serializes document upload with account deletion and leaves no private bytes', function () {
    config(['services.p6_document_upload.enabled' => true]);
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    Queue::fake();

    $user = User::factory()->create();
    $characterization = Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
    ]);
    $barrier = contentionBarrier('upload-account-delete');

    DB::disconnect();
    $uploadPid = forkContentionChild($barrier, function () use ($user, $barrier): void {
        Event::listen('eloquent.creating: '.CharacterizationDocument::class, function () use ($barrier): void {
            file_put_contents($barrier['locked'], 'upload-ready');
            waitForContentionPath($barrier['release'], 5000);
        });
        $upload = UploadedFile::fake()->createWithContent(
            'report.pdf',
            "%PDF-1.7\nContention test document with enough content."
        );
        $request = Request::create(
            '/api/characterization/documents',
            'POST',
            [],
            [],
            ['document' => $upload],
        );
        $request->setUserResolver(fn (): User => User::query()->findOrFail($user->id));

        app(CharacterizationDocumentController::class)->store($request);
    });

    waitForContentionPath($barrier['locked'], 5000);
    DB::disconnect();
    $deletePid = forkContentionChild($barrier, function () use ($user, $barrier): void {
        $lockedUser = User::query()->findOrFail($user->id);
        Auth::login($lockedUser);
        Event::listen('eloquent.deleting: '.User::class, function () use ($barrier): void {
            file_put_contents($barrier['second_acquired'], 'account-deleting');
        });
        $request = Request::create('/profile', 'DELETE', ['password' => 'password']);
        $request->setUserResolver(fn (): User => $lockedUser);
        $session = app('session')->driver();
        $session->start();
        $session->put('auth_version', (int) $lockedUser->auth_version);
        $request->setLaravelSession($session);

        app(ProfileController::class)->destroy($request);
    });

    usleep(250000);
    expect(file_exists($barrier['second_acquired']))->toBeFalse();
    file_put_contents($barrier['release'], 'release');
    waitForContentionChildren([$uploadPid, $deletePid], $barrier);

    DB::reconnect();
    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(Characterization::query()->whereKey($characterization->id)->exists())->toBeFalse()
        ->and(CharacterizationDocument::query()->where('characterization_id', $characterization->id)->exists())->toBeFalse()
        ->and(Storage::disk(CharacterizationDocument::STORAGE_DISK)->allFiles())->toBe([]);

    removeContentionBarrier($barrier);
});

it('revalidates password and session epoch after waiting for the account row lock', function () {
    $user = User::factory()->create([
        'password' => Hash::make('original-password'),
        'auth_version' => 0,
    ]);
    $barrier = contentionBarrier('password-epoch');

    DB::disconnect();
    $winnerPid = forkContentionChild($barrier, function () use ($user, $barrier): void {
        DB::transaction(function () use ($user, $barrier): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            file_put_contents($barrier['locked'], 'locked');
            waitForContentionPath($barrier['release'], 5000);
            $locked->forceFill([
                'password' => Hash::make('winner-password'),
                'auth_version' => 1,
            ])->save();
        });
    });

    waitForContentionPath($barrier['locked'], 5000);
    DB::disconnect();
    $loserPid = forkContentionChild($barrier, function () use ($user, $barrier): void {
        $stale = User::query()->findOrFail($user->id);
        Auth::login($stale);
        $request = Request::create('/password', 'PUT', [
            'current_password' => 'original-password',
            'password' => 'loser-password',
            'password_confirmation' => 'loser-password',
        ]);
        $request->setUserResolver(fn (): User => $stale);
        $session = app('session')->driver();
        $session->start();
        $session->put('auth_version', 0);
        $request->setLaravelSession($session);
        Event::listen('eloquent.retrieved: '.User::class, function () use ($barrier): void {
            file_put_contents($barrier['second_acquired'], 'acquired');
        });

        try {
            app(PasswordController::class)->update($request);
            file_put_contents($barrier['result'], 'accepted');
        } catch (\Illuminate\Validation\ValidationException) {
            file_put_contents($barrier['result'], 'rejected');
        }
    });

    usleep(250000);
    expect(file_exists($barrier['second_acquired']))->toBeFalse();
    file_put_contents($barrier['release'], 'release');
    waitForContentionChildren([$winnerPid, $loserPid], $barrier);

    DB::reconnect();
    $fresh = User::query()->findOrFail($user->id);
    expect(file_get_contents($barrier['result']))->toBe('rejected')
        ->and((int) $fresh->auth_version)->toBe(1)
        ->and(Hash::check('winner-password', $fresh->password))->toBeTrue()
        ->and(Hash::check('loser-password', $fresh->password))->toBeFalse();

    removeContentionBarrier($barrier);
});

/** @return array<string, string> */
function contentionBarrier(string $label): array
{
    $directory = sys_get_temp_dir().'/ia4s-contention-'.str_replace('.', '', uniqid($label.'-', true));
    mkdir($directory, 0700, true);

    return [
        'directory' => $directory,
        'locked' => $directory.'/locked',
        'release' => $directory.'/release',
        'second_acquired' => $directory.'/second-acquired',
        'result' => $directory.'/result.json',
        'error' => $directory.'/child-error',
    ];
}

function forkContentionChild(array $barrier, Closure $callback): int
{
    $pid = pcntl_fork();
    expect($pid)->toBeGreaterThanOrEqual(0);

    if ($pid === 0) {
        try {
            DB::reconnect();
            $callback();
            DB::disconnect();
            exit(0);
        } catch (Throwable $throwable) {
            file_put_contents($barrier['error'], $throwable::class.': '.$throwable->getMessage());
            exit(1);
        }
    }

    return $pid;
}

function waitForContentionPath(string $path, int $timeoutMs): void
{
    $deadline = microtime(true) + ($timeoutMs / 1000);

    while (! file_exists($path) && microtime(true) < $deadline) {
        usleep(10000);
    }

    expect(file_exists($path))->toBeTrue("Timed out waiting for contention barrier: {$path}");
}

/** @param array<int, int> $pids */
function waitForContentionChildren(array $pids, array $barrier): void
{
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        expect(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0, file_exists($barrier['error']) ? file_get_contents($barrier['error']) : 'Contention child failed.');
    }
}

function removeContentionBarrier(array $barrier): void
{
    foreach ($barrier as $key => $path) {
        if ($key !== 'directory' && file_exists($path)) {
            unlink($path);
        }
    }

    if (is_dir($barrier['directory'])) {
        rmdir($barrier['directory']);
    }
}

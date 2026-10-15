<?php

use App\Http\Controllers\Api\CharacterizationController;
use App\Http\Controllers\Api\CharacterizationDocumentController;
use App\Http\Controllers\Api\DoubleMaterialityGuideController;
use App\Http\Controllers\Api\EsrsDatapointController;
use App\Http\Controllers\Api\EsrsTopicController;
use App\Http\Controllers\Api\FrontendSessionController;
use App\Http\Controllers\Api\GuidedReportController;
use App\Http\Controllers\Api\MaterialityConfirmationController;
use App\Http\Controllers\Api\MaterialityProposalController;
use App\Http\Controllers\Api\NaceCodeController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReportFactController;
use App\Http\Controllers\Api\ReportSnapshotController;
use App\Http\Controllers\Api\WorkflowController;
use App\Support\RegistrationGuard;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->group(function () {
    Route::get('locale', [\App\Http\Controllers\Api\LocaleController::class, 'show'])->name('api.locale.show');
    Route::match(['put', 'post'], 'locale', [\App\Http\Controllers\Api\LocaleController::class, 'update'])
        ->middleware('throttle:30,1,locale')->name('api.locale.update');
});

// Public (no-auth) config for the pre-login register page: Turnstile site key
// (public by design) + whether email verification is enforced. Lets the Next
// register form render the widget only when configured.
Route::middleware(['web'])->get('auth/register-config', function () {
    return response()->json([
        'data' => [
            'registration_enabled' => RegistrationGuard::registrationAvailable(),
            'turnstile_site_key' => RegistrationGuard::registrationAvailable()
                ? config('services.auth_hardening.turnstile.site_key')
                : null,
            'require_email_verification' => (bool) config('services.auth_hardening.require_email_verification'),
            'honeypot_field' => (string) config('services.auth_hardening.honeypot_field'),
        ],
    ]);
})->name('api.auth.register_config');

Route::middleware(['web', 'private-dev-user', 'auth', 'verified.required'])->group(function () {
    Route::get('admin/learning', [\App\Http\Controllers\Api\LearningAdminController::class, 'show'])->middleware('throttle:20,1,learning-admin-read');
    Route::post('admin/learning/actions', [\App\Http\Controllers\Api\LearningAdminController::class, 'store'])->middleware('throttle:5,1,learning-admin-actions');
    Route::get('learning-case/draft', [\App\Http\Controllers\Api\LearningCaseController::class, 'show'])->name('api.learning_case.draft');
    Route::put('learning-case/draft', [\App\Http\Controllers\Api\LearningCaseController::class, 'update'])->name('api.learning_case.save');
    Route::post('learning-case/close', [\App\Http\Controllers\Api\LearningCaseController::class, 'close'])->name('api.learning_case.close');
    Route::post('learning-case/withdraw', [\App\Http\Controllers\Api\LearningCaseController::class, 'withdraw'])->name('api.learning_case.withdraw');
    // Session must be readable by UNVERIFIED users so the frontend can detect
    // the state and show the verify-email screen — exempt it from the guard.
    Route::get('auth/session', [FrontendSessionController::class, 'show'])
        ->withoutMiddleware('verified.required')
        ->name('api.auth.session');
    Route::get('workflow', [WorkflowController::class, 'show'])
        ->name('api.workflow.show');
    Route::get('nace-codes', [NaceCodeController::class, 'index'])
        ->name('api.nace-codes.index');
    Route::get('esrs-topics', [EsrsTopicController::class, 'index'])
        ->name('api.esrs-topics.index');
    Route::get('characterization/options', [CharacterizationController::class, 'options'])
        ->name('api.characterization.options');
    Route::get('characterization/documents', [CharacterizationDocumentController::class, 'index'])
        ->name('api.characterization.documents.index');
    // Uploads and submits are expensive (50 MB files, extraction jobs, AI
    // calls), so cap them per user. The prefix gives each route its own
    // bucket; without it both would share one per-user counter.
    Route::post('characterization/documents', [CharacterizationDocumentController::class, 'store'])
        ->middleware('throttle:10,1,characterization-documents')
        ->name('api.characterization.documents.store');
    Route::delete('characterization/documents/{document}', [CharacterizationDocumentController::class, 'destroy'])
        ->whereNumber('document')
        ->name('api.characterization.documents.destroy');
    Route::get('characterization', [CharacterizationController::class, 'show'])
        ->name('api.characterization.show');
    Route::put('characterization', [CharacterizationController::class, 'update'])
        ->name('api.characterization.update');
    Route::post('characterization/submit', [CharacterizationController::class, 'submit'])
        ->middleware('throttle:10,1,characterization-submit')
        ->name('api.characterization.submit');
    Route::get('characterizations/{characterization}/sector-comparison', [\App\Http\Controllers\Api\SectorComparisonController::class, 'show'])
        ->where('characterization', '[1-9][0-9]{0,14}')->middleware('throttle:30,1,sector-comparison-read')->name('api.sector-comparison.show');
    Route::get('materiality-proposal', [MaterialityProposalController::class, 'show'])
        ->name('api.materiality-proposal.show');
    Route::put('materiality-proposal', [MaterialityProposalController::class, 'update'])
        ->name('api.materiality-proposal.update');
    Route::get('double-materiality-guide/templates/{template}.csv', [DoubleMaterialityGuideController::class, 'templateCsv'])
        ->where('template', '[a-z_]+')
        ->name('api.double-materiality-guide.templates.csv');
    Route::get('double-materiality-guide/state', [DoubleMaterialityGuideController::class, 'state'])
        ->name('api.double-materiality-guide.state');
    Route::put('double-materiality-guide/state', [DoubleMaterialityGuideController::class, 'updateState'])
        ->name('api.double-materiality-guide.state.update');
    Route::get('double-materiality-guide', [DoubleMaterialityGuideController::class, 'show'])
        ->name('api.double-materiality-guide.show');
    Route::post('characterizations/{characterization}/dma-imports/{import}/nlp-runs', [\App\Http\Controllers\Api\DmaNlpController::class, 'store'])
        ->whereNumber(['characterization', 'import'])->middleware('throttle:dma-nlp-store');
    Route::get('characterizations/{characterization}/dma-nlp-runs/{run}', [\App\Http\Controllers\Api\DmaNlpController::class, 'show'])
        ->whereNumber(['characterization', 'run'])->middleware('throttle:dma-nlp-show');
    Route::post('characterizations/{characterization}/dma-nlp-runs/{run}/reviews', [\App\Http\Controllers\Api\DmaNlpController::class, 'review'])
        ->whereNumber(['characterization', 'run'])->middleware('throttle:dma-nlp-review');
    Route::get('characterizations/{characterization}/dma-imports', [\App\Http\Controllers\Api\DmaImportController::class, 'show'])
        ->where('characterization', '[1-9][0-9]{0,14}')->middleware('throttle:30,1,dma-import-read')->middleware('throttle:iro-read')->name('api.dma-imports.show');
    Route::post('characterizations/{characterization}/dma-imports', [\App\Http\Controllers\Api\DmaImportController::class, 'store'])
        ->where('characterization', '[1-9][0-9]{0,14}')->middleware('throttle:5,1,dma-import-upload')->middleware('throttle:iro-write')->name('api.dma-imports.store');
    Route::get('characterizations/{characterization}/dma-imports/{import}/iro.csv', [\App\Http\Controllers\Api\DmaImportController::class, 'export'])
        ->where('characterization', '[1-9][0-9]{0,14}')->where('import', '[1-9][0-9]{0,14}')->middleware('throttle:10,1,dma-import-export')->middleware('throttle:iro-export')->name('api.dma-imports.export');
    Route::get('materiality-confirmation', [MaterialityConfirmationController::class, 'show'])
        ->middleware('throttle:iro-read')
        ->name('api.materiality-confirmation.show');
    Route::put('materiality-confirmation', [MaterialityConfirmationController::class, 'update'])
        ->middleware('throttle:iro-write')
        ->name('api.materiality-confirmation.update');
    Route::get('materiality-confirmation/decision-sheet', [MaterialityConfirmationController::class, 'decisionSheet'])
        ->middleware('throttle:iro-export')
        ->name('api.materiality-confirmation.decision-sheet');
    Route::post('materiality-confirmation/preview', [MaterialityConfirmationController::class, 'preview'])
        ->middleware('throttle:iro-read')
        ->name('api.materiality-confirmation.preview');
    Route::get('report/taxonomy', [GuidedReportController::class, 'taxonomyStatus'])
        ->name('api.report.taxonomy');
    Route::get('report/xbrl-identifications', [\App\Http\Controllers\Api\XbrlRuntimeIdentificationController::class, 'index'])
        ->middleware('throttle:30,1,xbrl-runtime-read')->name('api.report.xbrl-identifications.index');
    Route::post('report/xbrl-identifications', [\App\Http\Controllers\Api\XbrlRuntimeIdentificationController::class, 'store'])
        ->middleware('throttle:5,1,xbrl-runtime-identify')->name('api.report.xbrl-identifications.store');
    Route::post('report/xbrl-identifications/{run}/reviews', [\App\Http\Controllers\Api\XbrlRuntimeIdentificationController::class, 'review'])
        ->middleware('throttle:20,1,xbrl-runtime-review')->whereNumber('run')->name('api.report.xbrl-identifications.review');
    Route::get('report/draft', [ReportController::class, 'draft'])
        ->name('api.report.draft');
    Route::get('report/eu-taxonomy', [\App\Http\Controllers\Api\EuTaxonomyEligibilityController::class, 'show'])
        ->middleware('throttle:30,1,eu-taxonomy-read')
        ->name('api.report.eu-taxonomy.show');
    Route::put('report/eu-taxonomy', [\App\Http\Controllers\Api\EuTaxonomyEligibilityController::class, 'update'])
        ->middleware('throttle:10,1,eu-taxonomy-write')
        ->name('api.report.eu-taxonomy.update');
    Route::get('report/package', [ReportController::class, 'package'])
        ->name('api.report.package');
    Route::get('report/evidence-bundle', [ReportController::class, 'evidenceBundle'])
        ->name('api.report.evidence-bundle');
    Route::get('report/html', [GuidedReportController::class, 'html'])
        ->name('api.report.html');
    Route::get('report/xhtml', [GuidedReportController::class, 'xhtml'])
        ->middleware('throttle:10,1,report-xhtml-presentation')->name('api.report.xhtml');
    Route::get('report/xhtml-ixbrl-candidate', [GuidedReportController::class, 'xhtmlIxbrlCandidate'])
        ->middleware('throttle:2,1,report-xhtml-ixbrl')
        ->name('api.report.xhtml-ixbrl-candidate');
    Route::get('report/ixbrl-package', [GuidedReportController::class, 'ixbrlPackage'])
        ->middleware('throttle:2,1,report-xhtml-ixbrl')
        ->name('api.report.ixbrl-package');
    Route::get('report/facts', [ReportFactController::class, 'index'])
        ->middleware('throttle:30,1,report-facts-read')
        ->name('api.report.facts.index');
    Route::put('report/facts', [ReportFactController::class, 'update'])
        ->middleware('throttle:10,1,report-facts-write')
        ->name('api.report.facts.update');
    Route::post('report/facts/{fact}/review', [ReportFactController::class, 'review'])
        ->whereNumber('fact')
        ->middleware('throttle:20,1,report-facts-review')
        ->name('api.report.facts.review');
    Route::post('report/snapshot', [ReportSnapshotController::class, 'store'])
        ->name('api.report.snapshot.store');
    Route::get('report/snapshots', [ReportSnapshotController::class, 'index'])
        ->name('api.report.snapshots.index');
    Route::post('report/snapshots/{snapshot}/approve', [ReportSnapshotController::class, 'approve'])
        ->whereNumber('snapshot')
        ->name('api.report.snapshots.approve');
    Route::get('report', [ReportController::class, 'show'])
        ->name('api.report.show');
    Route::get('guided-report/docx', [GuidedReportController::class, 'docx'])
        ->name('api.guided-report.docx');
    Route::get('guided-report/evidence-bundle', [GuidedReportController::class, 'evidenceBundle'])
        ->name('api.guided-report.evidence-bundle');
    Route::get('esrs-datapoints/export.localized.csv', [EsrsDatapointController::class, 'exportLocalizedCsv'])
        ->middleware('throttle:iro-export')
        ->name('api.esrs-datapoints.export.localized.csv');
    Route::get('esrs-datapoints/responses/export.localized.csv', [EsrsDatapointController::class, 'exportLocalizedResponsesCsv'])
        ->middleware('throttle:iro-export')
        ->name('api.esrs-datapoints.responses.export.localized.csv');
    Route::get('esrs-datapoints/responses/export.csv', [EsrsDatapointController::class, 'exportResponsesCsv'])
        ->middleware('throttle:iro-export')
        ->name('api.esrs-datapoints.responses.export.csv');
    Route::get('esrs-datapoints/responses', [EsrsDatapointController::class, 'responses'])
        ->middleware('throttle:iro-read')
        ->name('api.esrs-datapoints.responses.show');
    Route::put('esrs-datapoints/responses', [EsrsDatapointController::class, 'updateResponses'])
        ->middleware('throttle:iro-write')
        ->name('api.esrs-datapoints.responses.update');
    Route::get('esrs-datapoints/export.csv', [EsrsDatapointController::class, 'exportCsv'])
        ->middleware('throttle:iro-export')
        ->name('api.esrs-datapoints.export.csv');
    Route::get('esrs-datapoints', [EsrsDatapointController::class, 'index'])
        ->middleware('throttle:iro-read')
        ->name('api.esrs-datapoints.index');
});

// Study data uses the existing Laravel session/CSRF authority. No client IDs
// select an account: every write is bound to request->user().
Route::middleware(['web', 'auth', 'verified.required'])->prefix('usability-study')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\UsabilityStudyController::class, 'show'])->middleware('throttle:30,1,usability-read');
    Route::post('enrol', [\App\Http\Controllers\Api\UsabilityStudyController::class, 'enrol'])->middleware('throttle:5,1,usability-enrol');
    Route::delete('/', [\App\Http\Controllers\Api\UsabilityStudyController::class, 'withdraw'])->withoutMiddleware('verified.required')->middleware('throttle:10,1,usability-withdraw');
    Route::post('events', [\App\Http\Controllers\Api\UsabilityStudyController::class, 'event'])->middleware('throttle:60,1,usability-events');
    Route::put('questionnaire', [\App\Http\Controllers\Api\UsabilityStudyController::class, 'questionnaire'])->middleware('throttle:10,1,usability-questionnaire');
    Route::get('export.csv', [\App\Http\Controllers\Api\UsabilityStudyController::class, 'export'])->middleware('throttle:5,1,usability-export');
});

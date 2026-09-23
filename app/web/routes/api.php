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
use App\Http\Controllers\Api\ReportFactController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReportSnapshotController;
use Illuminate\Support\Facades\Route;

// Public (no-auth) config for the pre-login register page: Turnstile site key
// (public by design) + whether email verification is enforced. Lets the Next
// register form render the widget only when configured.
Route::middleware(['web'])->get('auth/register-config', function () {
    return response()->json([
        'data' => [
            'turnstile_site_key' => config('services.auth_hardening.turnstile.site_key'),
            'require_email_verification' => (bool) config('services.auth_hardening.require_email_verification'),
            'honeypot_field' => (string) config('services.auth_hardening.honeypot_field'),
        ],
    ]);
})->name('api.auth.register_config');

Route::middleware(['web', 'auth', 'verified.required'])->group(function () {
    // Session must be readable by UNVERIFIED users so the frontend can detect
    // the state and show the verify-email screen — exempt it from the guard.
    Route::get('auth/session', [FrontendSessionController::class, 'show'])
        ->withoutMiddleware('verified.required')
        ->name('api.auth.session');
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
    Route::get('materiality-confirmation', [MaterialityConfirmationController::class, 'show'])
        ->name('api.materiality-confirmation.show');
    Route::put('materiality-confirmation', [MaterialityConfirmationController::class, 'update'])
        ->name('api.materiality-confirmation.update');
    Route::get('materiality-confirmation/decision-sheet', [MaterialityConfirmationController::class, 'decisionSheet'])
        ->name('api.materiality-confirmation.decision-sheet');
    Route::post('materiality-confirmation/preview', [MaterialityConfirmationController::class, 'preview'])
        ->name('api.materiality-confirmation.preview');
    Route::get('report/draft', [ReportController::class, 'draft'])
        ->name('api.report.draft');
    Route::get('report/package', [ReportController::class, 'package'])
        ->name('api.report.package');
    Route::get('report/evidence-bundle', [ReportController::class, 'evidenceBundle'])
        ->name('api.report.evidence-bundle');
    Route::get('report/taxonomy', [ReportController::class, 'taxonomy'])
        ->name('api.report.taxonomy');
    Route::get('report/html', [GuidedReportController::class, 'html'])
        ->name('api.report.html');
    Route::get('report/xhtml-ixbrl-candidate', [GuidedReportController::class, 'xhtmlIxbrlCandidate'])
        ->name('api.report.xhtml-ixbrl-candidate');
    Route::get('report/facts', [ReportFactController::class, 'index'])
        ->name('api.report.facts.index');
    Route::put('report/facts', [ReportFactController::class, 'update'])
        ->name('api.report.facts.update');
    Route::post('report/facts/{fact}/review', [ReportFactController::class, 'review'])
        ->whereNumber('fact')
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
    Route::get('esrs-datapoints/responses/export.csv', [EsrsDatapointController::class, 'exportResponsesCsv'])
        ->name('api.esrs-datapoints.responses.export.csv');
    Route::get('esrs-datapoints/responses', [EsrsDatapointController::class, 'responses'])
        ->name('api.esrs-datapoints.responses.show');
    Route::put('esrs-datapoints/responses', [EsrsDatapointController::class, 'updateResponses'])
        ->name('api.esrs-datapoints.responses.update');
    Route::get('esrs-datapoints/export.csv', [EsrsDatapointController::class, 'exportCsv'])
        ->name('api.esrs-datapoints.export.csv');
    Route::get('esrs-datapoints', [EsrsDatapointController::class, 'index'])
        ->name('api.esrs-datapoints.index');
});

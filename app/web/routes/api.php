<?php

use App\Http\Controllers\Api\CharacterizationController;
use App\Http\Controllers\Api\DoubleMaterialityGuideController;
use App\Http\Controllers\Api\EsrsDatapointController;
use App\Http\Controllers\Api\EsrsTopicController;
use App\Http\Controllers\Api\FrontendSessionController;
use App\Http\Controllers\Api\MaterialityConfirmationController;
use App\Http\Controllers\Api\MaterialityProposalController;
use App\Http\Controllers\Api\NaceCodeController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'private-dev-user', 'auth'])->group(function () {
    Route::get('auth/session', [FrontendSessionController::class, 'show'])
        ->name('api.auth.session');
    Route::get('workflow', [WorkflowController::class, 'show'])
        ->name('api.workflow.show');
    Route::get('nace-codes', [NaceCodeController::class, 'index'])
        ->name('api.nace-codes.index');
    Route::get('esrs-topics', [EsrsTopicController::class, 'index'])
        ->name('api.esrs-topics.index');
    Route::get('characterization/options', [CharacterizationController::class, 'options'])
        ->name('api.characterization.options');
    Route::get('characterization', [CharacterizationController::class, 'show'])
        ->name('api.characterization.show');
    Route::put('characterization', [CharacterizationController::class, 'update'])
        ->name('api.characterization.update');
    Route::post('characterization/submit', [CharacterizationController::class, 'submit'])
        ->name('api.characterization.submit');
    Route::get('materiality-proposal', [MaterialityProposalController::class, 'show'])
        ->name('api.materiality-proposal.show');
    Route::put('materiality-proposal', [MaterialityProposalController::class, 'update'])
        ->name('api.materiality-proposal.update');
    Route::get('double-materiality-guide/templates/{template}.csv', [DoubleMaterialityGuideController::class, 'templateCsv'])
        ->where('template', '[A-Za-z0-9_]+')
        ->name('api.double-materiality-guide.templates.csv');
    Route::get('double-materiality-guide', [DoubleMaterialityGuideController::class, 'show'])
        ->name('api.double-materiality-guide.show');
    Route::get('materiality-confirmation', [MaterialityConfirmationController::class, 'show'])
        ->name('api.materiality-confirmation.show');
    Route::put('materiality-confirmation', [MaterialityConfirmationController::class, 'update'])
        ->name('api.materiality-confirmation.update');
    Route::get('materiality-confirmation/decision-sheet', [MaterialityConfirmationController::class, 'decisionSheet'])
        ->name('api.materiality-confirmation.decision-sheet');
    Route::get('report/draft', [ReportController::class, 'draft'])
        ->name('api.report.draft');
    Route::get('report', [ReportController::class, 'show'])
        ->name('api.report.show');
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

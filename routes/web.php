<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvestigationPdfController;
use App\Http\Controllers\SlackCommandController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

// The monitored surface. Deliberately unauthenticated and cheap to poll.
Route::get('/healthz', HealthController::class)->name('healthz');
Route::get('/health', HealthController::class);

// Investigation report export. Behind auth: the document contains commit
// messages, diffs and internal ticket references.
Route::middleware('auth')->group(function () {
    Route::get('/incidents/{incident}/report.pdf', InvestigationPdfController::class)
        ->name('incidents.report.pdf');

    Route::get('/incidents/{incident}/investigations/{investigation}/report.pdf', InvestigationPdfController::class)
        ->name('incidents.investigation.report.pdf');
});

/*
| Inbound Slack slash command.
|
| Cannot sit behind session auth -- Slack posts server to server -- so it
| authenticates by verifying Slack's request signature instead, and refuses
| everything when no signing secret is configured.
*/
Route::post('/slack/commands/investigate', SlackCommandController::class)
    ->name('slack.investigate');

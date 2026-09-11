<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Investigation;
use App\Reporting\InvestigationReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Renders an investigation as a self-contained PDF.
 *
 * The point of this project is that a conclusion can be audited, and an audit
 * trail is only useful if it can leave the system -- attached to a ticket, sent
 * to someone without a login, or kept after the demo database is reset. So the
 * document carries the full evidence ledger and the rejected claims, not just
 * the headline finding.
 */
class InvestigationPdfController extends Controller
{
    public function __invoke(Incident $incident, ?Investigation $investigation = null): Response
    {
        $investigation ??= $incident->investigations()->latest('id')->firstOrFail();

        abort_unless($investigation->incident_id === $incident->id, 404);

        $investigation->load(['incident.monitoredEndpoint', 'hypotheses.evidence', 'evidence', 'actions']);

        $pdf = Pdf::loadView('reports.investigation-pdf', [
            'incident' => $incident,
            'investigation' => $investigation,
            'report' => InvestigationReport::for($investigation),
        ])->setPaper('a4');

        return $pdf->download(
            $incident->reference.'-investigation-'.$investigation->created_at->format('Ymd-Hi').'.pdf',
        );
    }
}

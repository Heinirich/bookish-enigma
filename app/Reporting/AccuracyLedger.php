<?php

namespace App\Reporting;

use App\Models\Hypothesis;
use App\Models\Incident;

/**
 * Accuracy measured against reality rather than against seeded answers.
 *
 * The evaluation harness scores the agent on incidents whose cause we planted,
 * which measures capability under known conditions. This measures something
 * different and harder to fake: of the conclusions a human actually judged, how
 * many were right.
 *
 * Deliberately reports the unjudged count too. A high confirmation rate over
 * three verdicts is not evidence of anything, and hiding the denominator would
 * make it look like it were.
 */
class AccuracyLedger
{
    public function summary(): array
    {
        $judged = Hypothesis::whereNotNull('verdict')->count();
        $confirmed = Hypothesis::where('verdict', 'confirmed')->count();

        $leadConfirmed = Hypothesis::where('verdict', 'confirmed')->where('stance', 'cause')->count();

        return [
            'judged' => $judged,
            'confirmed' => $confirmed,
            'refuted' => $judged - $confirmed,
            'awaiting' => Hypothesis::whereNull('verdict')->where('status', 'accepted')->count(),
            'rate' => $judged > 0 ? round($confirmed / $judged, 3) : null,
            'lead_confirmed' => $leadConfirmed,
            'incidents_resolved' => Incident::whereNotNull('resolved_at')->count(),
            'incidents_open' => Incident::whereNull('resolved_at')->count(),
            // Fewer than this and a percentage is noise, not a measurement.
            'is_meaningful' => $judged >= 5,
        ];
    }

    /**
     * Calibration against human verdicts: was the agent's stated confidence
     * predictive of being right? Buckets, because a single number would hide
     * whether it is overconfident specifically at the top of the range.
     */
    public function calibration(): array
    {
        $buckets = [
            ['label' => '0–25%', 'min' => 0.0, 'max' => 0.25],
            ['label' => '25–50%', 'min' => 0.25, 'max' => 0.5],
            ['label' => '50–75%', 'min' => 0.5, 'max' => 0.75],
            ['label' => '75–100%', 'min' => 0.75, 'max' => 1.01],
        ];

        return collect($buckets)->map(function (array $bucket) {
            $rows = Hypothesis::whereNotNull('verdict')
                ->where('confidence', '>=', $bucket['min'])
                ->where('confidence', '<', $bucket['max'])
                ->get();

            $confirmed = $rows->where('verdict', 'confirmed')->count();

            return [
                'label' => $bucket['label'],
                'count' => $rows->count(),
                'confirmed' => $confirmed,
                'rate' => $rows->count() > 0 ? round($confirmed / $rows->count(), 3) : null,
            ];
        })->all();
    }
}

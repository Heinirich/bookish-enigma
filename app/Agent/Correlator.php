<?php

namespace App\Agent;

use App\Models\Deployment;
use App\Models\Incident;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every number the agent reasons over is computed here, in PHP, from the
 * database -- not by the model.
 *
 * A 4B model asked to locate a changepoint across a few hundred samples and
 * match it to a deploy will confabulate plausible arithmetic. So the split is:
 * this class establishes *what happened and when*, and the model is left with
 * the genuinely interpretive part -- which candidate best explains it, and how
 * confident that reading deserves to be.
 */
class Correlator
{
    /** Tolerance either side of the estimated changepoint, in minutes. */
    private const CHANGEPOINT_GRACE_MINUTES = 2.0;

    /** Per-minute latency buckets across the incident window. */
    public function series(Incident $incident): array
    {
        $rows = DB::table('health_checks')
            ->selectRaw("
                date_trunc('minute', checked_at)                                  as bucket,
                count(*)                                                          as samples,
                round(percentile_cont(0.95) within group (order by latency_ms))    as p95,
                round(percentile_cont(0.50) within group (order by latency_ms))    as p50,
                round(avg(case when is_error then 1.0 else 0.0 end) * 100)        as error_pct,
                max(deploy_sha)                                                   as deploy_sha
            ")
            ->where('monitored_endpoint_id', $incident->monitored_endpoint_id)
            ->whereBetween('checked_at', [$incident->window_start, $incident->window_end])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows->map(fn ($r) => [
            'at' => Carbon::parse($r->bucket)->toIso8601String(),
            'samples' => (int) $r->samples,
            'p95_ms' => (int) $r->p95,
            'p50_ms' => (int) $r->p50,
            'error_pct' => (int) $r->error_pct,
            'deploy_sha' => $r->deploy_sha ? substr($r->deploy_sha, 0, 7) : null,
        ])->all();
    }

    /**
     * The minute at which latency (or error rate) stepped up.
     *
     * Chooses the bucket with the largest step relative to the running median of
     * everything before it, which is robust to the jitter a noisy scenario adds
     * and does not assume the degradation starts at a fixed offset.
     */
    public function changepoint(Incident $incident, array $series): ?array
    {
        if (count($series) < 4) {
            return null;
        }

        $best = null;

        for ($i = 2; $i < count($series); $i++) {
            $before = array_slice($series, 0, $i);
            $after = array_slice($series, $i);

            $beforeP95 = $this->median(array_column($before, 'p95_ms'));
            $afterP95 = $this->median(array_column($after, 'p95_ms'));

            $beforeErr = $this->median(array_column($before, 'error_pct'));
            $afterErr = $this->median(array_column($after, 'error_pct'));

            if ($beforeP95 <= 0) {
                continue;
            }

            $latencyRatio = $afterP95 / $beforeP95;
            $errorDelta = $afterErr - $beforeErr;

            // Rank candidate split points by how cleanly they separate the series.
            $strength = max($latencyRatio, 1.0) + ($errorDelta / 20);

            if ($best === null || $strength > $best['strength']) {
                $best = [
                    'strength' => $strength,
                    'at' => $series[$i]['at'],
                    'p95_before_ms' => (int) round($beforeP95),
                    'p95_after_ms' => (int) round($afterP95),
                    'latency_ratio' => round($latencyRatio, 2),
                    'error_pct_before' => (int) round($beforeErr),
                    'error_pct_after' => (int) round($afterErr),
                ];
            }
        }

        if ($best === null || $best['latency_ratio'] < 1.5 && $best['error_pct_after'] <= $best['error_pct_before']) {
            return null;
        }

        unset($best['strength']);

        // Minute buckets can only ever name the first *fully* degraded minute,
        // so a deploy that shipped mid-bucket looks like it landed after the
        // break it caused. Refine against raw samples to recover the real edge.
        $best['bucket_at'] = $best['at'];
        $best['at'] = $this->refine($incident, $best)->toIso8601String();

        return $best;
    }

    /**
     * Narrow the changepoint from minute resolution to the individual sample
     * where latency crosses the midpoint between the before and after medians
     * and stays there. Without this the correlator is systematically biased by
     * up to a minute against the very deploy that caused the incident.
     */
    private function refine(Incident $incident, array $best): Carbon
    {
        $bucketAt = Carbon::parse($best['bucket_at']);
        $threshold = ($best['p95_before_ms'] + $best['p95_after_ms']) / 2;

        $samples = DB::table('health_checks')
            ->select('checked_at', 'latency_ms', 'is_error')
            ->where('monitored_endpoint_id', $incident->monitored_endpoint_id)
            ->whereBetween('checked_at', [
                $bucketAt->copy()->subMinutes(2),
                $bucketAt->copy()->addMinutes(2),
            ])
            ->orderBy('checked_at')
            ->get();

        $elevated = fn ($s) => $s->latency_ms >= $threshold || $s->is_error;

        foreach ($samples as $i => $sample) {
            if (! $elevated($sample)) {
                continue;
            }

            // Require the elevation to persist, so one jittery sample does not
            // drag the changepoint earlier than the real break.
            $confirmations = 0;
            for ($j = $i + 1; $j < min($i + 4, count($samples)); $j++) {
                if ($elevated($samples[$j])) {
                    $confirmations++;
                }
            }

            if ($confirmations >= 2) {
                return Carbon::parse($sample->checked_at);
            }
        }

        return $bucketAt;
    }

    /**
     * Deployments that could explain a changepoint, ranked.
     *
     * Timing dominates because a deploy after the fact cannot be the cause;
     * changed-path relevance only breaks ties between deploys close in time.
     *
     * @return array<int,array<string,mixed>>
     */
    public function candidates(Incident $incident, ?Carbon $changepointAt = null): array
    {
        $config = config('agent.correlation');
        $pivot = $changepointAt ?? $incident->window_end;

        $from = $pivot->copy()->subMinutes($config['lookback_minutes']);
        $to = $pivot->copy()->addMinutes($config['lookahead_minutes']);

        $deployments = Deployment::with('commit')
            ->whereBetween('deployed_at', [$from, $to])
            ->orderByDesc('deployed_at')
            ->get();

        $candidates = $deployments->map(function (Deployment $deployment) use ($pivot, $config) {
            $minutesBefore = $deployment->deployed_at->diffInSeconds($pivot, false) / 60;

            $timeScore = $this->timeScore($minutesBefore, $config['lookback_minutes']);
            $pathScore = $this->pathScore($deployment->commit?->changed_files ?? []);

            return [
                'sha' => substr($deployment->sha, 0, 7),
                'full_sha' => $deployment->sha,
                'deployment_id' => $deployment->id,
                'message' => $deployment->commit?->message ?? $deployment->release_name,
                'author' => $deployment->author,
                'deployed_at' => $deployment->deployed_at->toIso8601String(),
                'minutes_before_changepoint' => round($minutesBefore, 1),
                'changed_files' => $deployment->commit?->changed_files ?? [],
                'files_changed' => $deployment->commit?->diff_summary,
                'timing_score' => round($timeScore, 3),
                'path_relevance_score' => round($pathScore, 3),
                'correlation_score' => round(0.65 * $timeScore + 0.35 * $pathScore, 3),
            ];
        })
            ->sortByDesc('correlation_score')
            ->values()
            ->all();

        return $candidates;
    }

    /**
     * 1.0 immediately before the changepoint, decaying to 0 across the lookback.
     *
     * The changepoint estimate carries real uncertainty -- sampling interval,
     * deploy-log clock skew, and the time a bad build takes to affect traffic --
     * so deploys landing slightly *after* it keep near-full credit and only then
     * fall away. A hard cliff at zero would systematically exonerate the deploy
     * that shipped seconds before the break.
     */
    private function timeScore(float $minutesBefore, int $lookbackMinutes): float
    {
        if ($minutesBefore < 0) {
            $minutesAfter = abs($minutesBefore);

            return $minutesAfter <= self::CHANGEPOINT_GRACE_MINUTES
                ? 1.0 - ($minutesAfter / self::CHANGEPOINT_GRACE_MINUTES) * 0.1
                : max(0.0, 0.9 - ($minutesAfter - self::CHANGEPOINT_GRACE_MINUTES) * 0.3);
        }

        if ($minutesBefore > $lookbackMinutes) {
            return 0.0;
        }

        return 1.0 - ($minutesBefore / $lookbackMinutes);
    }

    /**
     * Fraction of changed files that sit on the synchronous request path.
     * Deliberately coarse -- it is a tie-breaker, not a verdict.
     */
    private function pathScore(array $files): float
    {
        if ($files === []) {
            return 0.0;
        }

        $onPath = [
            'app/Http/', 'app/Services/', 'app/Queries/', 'app/Clients/',
            'app/Providers/', 'app/Models/', 'app/Contracts/', 'app/Resources/',
            'app/Http/Resources/', 'config/',
        ];

        $offPath = [
            'tests/', 'docs/', 'README', '.github/', 'package.json',
            'resources/css/', 'resources/js/', 'deploy/', 'scripts/', '.md',
        ];

        $score = 0.0;

        foreach ($files as $file) {
            foreach ($offPath as $needle) {
                if (str_contains($file, $needle)) {
                    continue 2;
                }
            }

            foreach ($onPath as $needle) {
                if (str_starts_with($file, $needle)) {
                    $score += 1.0;

                    continue 2;
                }
            }

            $score += 0.3; // unrecognised path: mildly suspicious
        }

        return min(1.0, $score / count($files));
    }

    private function median(array $values): float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));

        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $mid = (int) floor($count / 2);

        return $count % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2
            : (float) $values[$mid];
    }
}

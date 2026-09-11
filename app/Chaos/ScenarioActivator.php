<?php

namespace App\Chaos;

use App\Models\Commit;
use App\Models\Deployment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Activating a scenario does two things at once: it writes a real deployment
 * history (culprit plus decoys) and flips the chaos profile that shapes
 * /healthz. That coupling is what makes every demo run a scored eval case --
 * ground truth exists because the deploy and the degradation are the same act.
 */
class ScenarioActivator
{
    public function __construct(private readonly string $repo = 'acme/payments-api') {}

    public function activate(string $key, ?Carbon $at = null): Deployment
    {
        $scenario = ScenarioLibrary::get($key);

        if ($scenario === null) {
            throw new \InvalidArgumentException("Unknown scenario [{$key}].");
        }

        $at ??= now();

        // Decoys land first so the timeline reads naturally.
        foreach ($scenario['decoys'] as $decoy) {
            $this->recordDeployment(
                key: $key,
                meta: $decoy,
                at: $at->copy()->addMinutes($decoy['offset']),
                isCause: false,
            );
        }

        $culprit = $this->recordDeployment(
            key: $key,
            meta: $scenario['cause'],
            at: $at->copy()->addMinutes($scenario['deploy_offset_minutes'] ?? -2),
            // A scenario can declare that no deployment is to blame. Its deploy
            // still carries the chaos profile, but it is not ground truth, and
            // the correct answer becomes "no deployment implicated".
            isCause: ! ($scenario['expects_no_deploy_cause'] ?? false),
            chaosProfile: $scenario['profile'],
        );

        new ChaosProfile(
            scenarioKey: $key,
            label: $scenario['label'],
            baseLatencyMs: $scenario['profile']['base_latency_ms'],
            jitterMs: $scenario['profile']['jitter_ms'],
            errorRate: $scenario['profile']['error_rate'],
            deploySha: $culprit->sha,
            activatedAt: $at->toIso8601String(),
        )->activate();

        return $culprit;
    }

    public function deactivate(): ChaosProfile
    {
        return ChaosProfile::reset();
    }

    private function recordDeployment(
        string $key,
        array $meta,
        Carbon $at,
        bool $isCause,
        ?array $chaosProfile = null,
    ): Deployment {
        $sha = $this->fakeSha();

        Commit::create([
            'sha' => $sha,
            'repo' => $this->repo,
            'message' => $meta['message'],
            'author' => $meta['author'],
            'committed_at' => $at->copy()->subMinutes(random_int(3, 20)),
            'changed_files' => $meta['changed_files'],
            'additions' => $meta['additions'],
            'deletions' => $meta['deletions'],
            // Generated from the file stats, in the same shape ApiGitHubConnector
            // produces, so seeded commits and real ones are indistinguishable to
            // the agent -- and neither carries a hint about what went wrong.
            'diff_summary' => $this->fileStats($meta),
            'patch' => $this->dedent($meta['patch'] ?? ''),
            'html_url' => "https://github.com/{$this->repo}/commit/{$sha}",
        ]);

        return Deployment::create([
            'sha' => $sha,
            'repo' => $this->repo,
            'ref' => 'main',
            'environment' => 'production',
            'release_name' => Str::slug(Str::words($meta['message'], 4, '')),
            'author' => $meta['author'],
            'deployed_at' => $at,
            'html_url' => "https://github.com/{$this->repo}/deployments",
            'chaos_profile' => $chaosProfile,
            'is_seeded_cause' => $isCause,
            'scenario_key' => $key,
        ]);
    }

    /** "path (+12/-3); other/path (+4/-0)" — the same format the GitHub connector emits. */
    private function fileStats(array $meta): string
    {
        $files = $meta['changed_files'];
        $count = max(1, count($files));

        // Spread the totals across the files; exact per-file numbers are not
        // meaningful here and nothing downstream depends on them.
        $addPer = intdiv($meta['additions'], $count);
        $delPer = intdiv($meta['deletions'], $count);

        return collect($files)
            ->map(fn (string $file) => "{$file} (+{$addPer}/-{$delPer})")
            ->implode('; ');
    }

    /** Heredocs in the scenario file are indented for readability; patches are not. */
    private function dedent(string $patch): string
    {
        $lines = explode("\n", $patch);

        $indent = collect($lines)
            ->filter(fn (string $line) => trim($line) !== '')
            ->map(fn (string $line) => strlen($line) - strlen(ltrim($line)))
            ->min() ?? 0;

        return trim(collect($lines)
            ->map(fn (string $line) => substr($line, $indent))
            ->implode("\n"));
    }

    private function fakeSha(): string
    {
        return substr(hash('sha1', Str::uuid()->toString()), 0, 40);
    }
}

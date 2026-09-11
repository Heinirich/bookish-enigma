<?php

namespace App\Console\Commands;

use App\Agent\ActionExecutor;
use App\Models\AgentAction;
use Illuminate\Console\Command;

class ActionsCommand extends Command
{
    protected $signature = 'actions
                            {action=list : list|approve|reject}
                            {id? : Action id, or "all" for every pending action}';

    protected $description = 'Review and release the write actions an investigation staged';

    public function handle(ActionExecutor $executor): int
    {
        return match ($this->argument('action')) {
            'list' => $this->list(),
            'approve' => $this->resolve($executor, approve: true),
            'reject' => $this->resolve($executor, approve: false),
            default => self::FAILURE,
        };
    }

    private function list(): int
    {
        $actions = AgentAction::where('was_write', true)
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        if ($actions->isEmpty()) {
            $this->info('No write actions staged.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'investigation', 'tool', 'status', 'target', 'link'],
            $actions->map(fn (AgentAction $a) => [
                $a->id,
                $a->investigation_id,
                $a->tool,
                $a->status,
                \Illuminate\Support\Str::limit(
                    $a->arguments['channel'] ?? $a->arguments['summary'] ?? $a->arguments['title'] ?? '—', 34,
                ),
                $a->external_url ?? '—',
            ])->all(),
        );

        $pending = $actions->where('status', 'pending_approval')->count();

        if ($pending > 0) {
            $this->newLine();
            $this->comment("{$pending} awaiting approval. Release with: php artisan actions approve <id|all>");
            $this->line('These write to real Slack, Jira and Notion when the drivers are set to `api`.');
        }

        return self::SUCCESS;
    }

    private function resolve(ActionExecutor $executor, bool $approve): int
    {
        $id = $this->argument('id');

        if (! $id) {
            $this->error('Give an action id, or "all".');

            return self::FAILURE;
        }

        $query = AgentAction::where('was_write', true)->where('status', 'pending_approval');
        $actions = $id === 'all' ? $query->get() : $query->where('id', $id)->get();

        if ($actions->isEmpty()) {
            $this->warn('Nothing pending matches that.');

            return self::SUCCESS;
        }

        foreach ($actions as $action) {
            if (! $approve) {
                $executor->reject($action, 'cli');
                $this->line("  rejected #{$action->id} {$action->tool}");

                continue;
            }

            $result = $executor->execute($action, 'cli');

            $result->status === 'succeeded'
                ? $this->info("  ✓ #{$result->id} {$result->tool} — {$result->result_summary} {$result->external_url}")
                : $this->error("  ✗ #{$result->id} {$result->tool} — {$result->error}");
        }

        return self::SUCCESS;
    }
}

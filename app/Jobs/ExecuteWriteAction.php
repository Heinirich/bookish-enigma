<?php

namespace App\Jobs;

use App\Agent\ActionExecutor;
use App\Models\AgentAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteWriteAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public AgentAction $action,
        public string $approvedBy = 'operator',
    ) {}

    public function handle(ActionExecutor $executor): void
    {
        $executor->execute($this->action, $this->approvedBy);
    }
}

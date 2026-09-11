<?php

namespace App\Jobs;

use App\Agent\Investigator;
use App\Models\Incident;
use App\Models\Investigation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunInvestigation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /**
     * An incident deleted before its job runs -- routine when demo data is reset
     * -- should discard the job quietly rather than fill the failed table.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Incident $incident,
        public ?int $investigationId = null,
    ) {}

    public function handle(Investigator $investigator): void
    {
        $investigation = $this->investigationId
            ? Investigation::find($this->investigationId)
            : Investigator::open($this->incident);

        // Same reasoning as deleteWhenMissingModels: a reset between dispatch and
        // execution is expected, not an error.
        if ($investigation === null) {
            return;
        }

        $this->incident->update(['status' => 'investigating']);

        $investigator->run($investigation);
    }
}

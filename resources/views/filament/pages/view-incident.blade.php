<x-filament-panels::page>
    @php
        $investigation = $this->getInvestigation();
        $report = $this->getReport();
        $signal = $record->detection_signal ?? [];
    @endphp

    @if ($record->isResolved())
        <div class="rounded-xl border border-success-300 bg-success-50 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::badge color="success">Resolved</x-filament::badge>
                <span class="text-sm text-gray-600">
                    {{ $record->resolved_at->diffForHumans() }} by {{ $record->resolved_by }}
                </span>
            </div>

            @if ($record->resolution_note)
                <p class="mt-2 text-sm text-gray-950">{{ $record->resolution_note }}</p>
            @endif

            @if ($record->actual_cause_sha)
                @php
                    $lead = $this->getReport()?->leadHypothesis();
                    $agentAgreed = $lead && $lead->root_cause_sha === $record->actual_cause_sha;
                @endphp

                <p class="mt-2 text-xs text-gray-500">
                    Actual cause <code>{{ substr($record->actual_cause_sha, 0, 7) }}</code>
                    @if ($lead)
                        — the agent blamed
                        <code>{{ $lead->root_cause_sha ? substr($lead->root_cause_sha, 0, 7) : 'no deploy' }}</code>,
                        <span class="{{ $agentAgreed ? 'text-success-700' : 'text-danger-700' }} font-medium">
                            {{ $agentAgreed ? 'which matches' : 'which does not match' }}
                        </span>
                    @endif
                </p>
            @endif
        </div>
    @endif

    {{-- Detection: what tripped, measured, before any model was involved --}}
    <x-filament::section heading="Detection" icon="heroicon-o-signal">
        <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
            @foreach ([
                'Severity'     => strtoupper($record->severity),
                'Baseline p95' => ($signal['baseline']['p95'] ?? '—') . 'ms',
                'Spike p95'    => ($signal['spike']['p95'] ?? '—') . 'ms',
                'Ratio'        => ($signal['latency_ratio'] ?? '—') . '×',
                'Trigger'      => str_replace('_', ' ', $signal['reason'] ?? $record->trigger),
            ] as $label => $value)
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- The evidence itself, before any of the agent's conclusions about it --}}
    <x-filament::section heading="Timeline" icon="heroicon-o-chart-bar">
        <div class="h-72">
            @livewire(\App\Filament\Widgets\IncidentTimeline::class, ['record' => $record], key('timeline-'.$record->id))
        </div>
    </x-filament::section>

    @php $history = $this->investigationHistory(); @endphp

    @if ($history->count() > 1)
        <x-filament::section heading="Runs" icon="heroicon-o-clock" collapsible>
            <x-slot name="description">
                This incident has been investigated {{ $history->count() }} times. Select a run to inspect it.
            </x-slot>

            <div class="space-y-2">
                @foreach ($history as $run)
                    @php $isShown = $investigation && $run->id === $investigation->id; @endphp

                    <button
                        type="button"
                        wire:click="selectInvestigation({{ $run->id }})"
                        @class([
                            'flex w-full flex-wrap items-center justify-between gap-3 rounded-lg border p-3 text-left transition',
                            'border-primary-500 bg-primary-50' => $isShown,
                            'border-gray-200 hover:bg-gray-50' => ! $isShown,
                        ])
                    >
                        <div class="flex items-center gap-2">
                            @if ($isShown)
                                <x-filament::badge color="primary" size="xs">showing</x-filament::badge>
                            @endif

                            <span class="text-sm font-medium text-gray-950">
                                {{ $run->created_at->diffForHumans() }}
                            </span>

                            <x-filament::badge size="xs" :color="match ($run->status) {
                                'completed' => 'success',
                                'failed' => 'danger',
                                default => 'warning',
                            }">{{ $run->status }}</x-filament::badge>
                        </div>

                        <div class="flex items-center gap-3 text-xs text-gray-500">
                            <code>{{ $run->model }}</code>
                            <span>{{ $run->hypotheses_count }} claims</span>
                            <span>{{ $run->evidence_count }} evidence</span>
                            @if ($run->duration_ms)
                                <span>{{ number_format($run->duration_ms / 1000, 1) }}s</span>
                            @endif
                        </div>
                    </button>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if (! $investigation)
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No investigation yet. Use <strong>Investigate</strong> above to run the agent.
            </p>
        </x-filament::section>
    @else
        @php $v = $report->verification(); @endphp

        {{-- The claim this project makes, stated as numbers --}}
        <x-filament::section heading="Verification" icon="heroicon-o-shield-check">
            <x-slot name="description">
                Every citation below was checked against the evidence actually gathered.
            </x-slot>

            <div class="grid grid-cols-2 gap-4 md:grid-cols-6">
                @foreach ([
                    ['Claims generated', $v['generated'], 'text-gray-950 dark:text-white'],
                    ['Accepted', $v['accepted'], 'text-success-600 dark:text-success-400'],
                    ['Discarded', $v['discarded'], $v['discarded'] > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-950 dark:text-white'],
                    ['Citations checked', $v['citations'], 'text-gray-950 dark:text-white'],
                    ['Fabricated', $v['fabricated'], $v['fabricated'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400'],
                    ['Evidence on file', $v['evidence'], 'text-gray-950 dark:text-white'],
                ] as [$label, $value, $class])
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        <div class="mt-1 text-2xl font-bold {{ $class }}">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap gap-4 border-t border-gray-200 pt-4 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                <span>model <code>{{ $investigation->model }}</code></span>
                <span>{{ $investigation->prompt_tokens }} in / {{ $investigation->completion_tokens }} out</span>
                <span>{{ number_format(($investigation->duration_ms ?? 0) / 1000, 1) }}s</span>
                <span>{{ $investigation->tool_iterations }} tool iterations</span>
                <span>status <strong>{{ $investigation->status }}</strong></span>
            </div>
        </x-filament::section>

        {{-- Hypotheses, each traceable to its sources --}}
        <x-filament::section heading="Hypotheses" icon="heroicon-o-light-bulb">
            <div class="space-y-4">
                @forelse ($investigation->hypotheses as $h)
                    @php
                        $rejected = $h->status !== 'accepted';
                        $ruledOut = $h->stance === 'ruled_out';
                    @endphp

                    <div @class([
                        'rounded-xl border p-4',
                        'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5' => ! $rejected && ! $ruledOut,
                        'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => $ruledOut && ! $rejected,
                        'border-danger-300 bg-danger-50 dark:border-danger-500/40 dark:bg-danger-500/10' => $rejected,
                    ])>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">#{{ $h->rank }}</span>
                                @if ($rejected)
                                    <x-filament::badge color="danger">Discarded — unsupported</x-filament::badge>
                                @elseif ($ruledOut)
                                    <x-filament::badge color="gray">Ruled out</x-filament::badge>
                                @else
                                    <x-filament::badge color="success">Cause</x-filament::badge>
                                @endif
                            </div>

                            <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                <span>grounding {{ number_format((float) $h->grounding_score, 2) }}</span>
                                <span class="font-semibold text-gray-950 dark:text-white">
                                    {{ number_format($h->confidence * 100) }}% likely
                                </span>
                            </div>
                        </div>

                        {{-- Confidence as a bar, so an overconfident wrong answer is visible at a glance --}}
                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                            <div class="h-full rounded-full {{ $rejected ? 'bg-danger-500' : ($ruledOut ? 'bg-gray-400' : 'bg-primary-500') }}"
                                 style="width: {{ max(2, $h->confidence * 100) }}%"></div>
                        </div>

                        <p class="mt-3 text-sm text-gray-950 dark:text-white">{{ $h->statement }}</p>

                        @if ($h->mechanism)
                            <p class="mt-2 border-l-2 border-gray-200 pl-3 text-xs leading-relaxed text-gray-600">
                                <span class="font-medium text-gray-500">Mechanism</span> — {{ $h->mechanism }}
                            </p>
                        @endif

                        @if ($h->remediation && ! $ruledOut)
                            <div class="mt-2 rounded-lg bg-primary-50 px-3 py-2 text-xs text-primary-900">
                                <span class="font-semibold">Suggested next step</span> — {{ $h->remediation }}
                            </div>
                        @endif

                        <div class="mt-3 flex flex-wrap items-center gap-1.5">
                            <span class="text-xs text-gray-500 dark:text-gray-400">evidence</span>
                            @forelse ($h->evidence as $e)
                                <span title="{{ $e->summary }}"
                                      class="inline-flex items-center rounded-md bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-600/20 dark:bg-primary-500/10 dark:text-primary-300">
                                    {{ $e->public_id }}
                                    @if ($e->pivot->relation === 'contradicts')
                                        <span class="ml-1 text-danger-500">contradicts</span>
                                    @endif
                                </span>
                            @empty
                                <span class="text-xs text-gray-400">none</span>
                            @endforelse
                        </div>

                        @if ($fabricated = ($h->validation_report['fabricated'] ?? []))
                            <p class="mt-2 text-xs font-medium text-danger-600 dark:text-danger-400">
                                Rejected citations (no such evidence): {{ implode(', ', $fabricated) }}
                            </p>
                        @endif

                        @if ($ungrounded = ($h->validation_report['ungrounded_tokens'] ?? []))
                            <p class="mt-1 text-xs text-warning-600 dark:text-warning-400">
                                Figures not found in the cited evidence: {{ implode(', ', $ungrounded) }}
                            </p>
                        @endif

                        @if ($h->root_cause_sha)
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                blames deploy <code>{{ substr($h->root_cause_sha, 0, 7) }}</code>
                            </p>
                        @endif

                        {{-- Human verdict: the only source of accuracy that is not
                             measured against an answer we planted ourselves. --}}
                        @unless ($rejected)
                            <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                                <span class="text-xs text-gray-500">Was this right?</span>

                                <x-filament::button
                                    size="xs"
                                    :color="$h->isConfirmed() ? 'success' : 'gray'"
                                    :outlined="! $h->isConfirmed()"
                                    wire:click="recordVerdict({{ $h->id }}, 'confirmed')"
                                >
                                    Confirmed
                                </x-filament::button>

                                <x-filament::button
                                    size="xs"
                                    :color="$h->isRefuted() ? 'danger' : 'gray'"
                                    :outlined="! $h->isRefuted()"
                                    wire:click="recordVerdict({{ $h->id }}, 'refuted')"
                                >
                                    Refuted
                                </x-filament::button>

                                @if ($h->verdict_at)
                                    <span class="text-[11px] text-gray-400">
                                        {{ $h->verdict_by }}, {{ $h->verdict_at->diffForHumans() }}
                                    </span>
                                @else
                                    <span class="text-[11px] text-gray-400">not yet judged</span>
                                @endif
                            </div>
                        @endunless
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">No hypotheses were produced.</p>
                @endforelse
            </div>
        </x-filament::section>

        {{-- Write actions: nothing leaves the system without a decision here --}}
        @if ($investigation->actions->where('was_write', true)->isNotEmpty())
            <x-filament::section heading="Write actions" icon="heroicon-o-paper-airplane">
                <x-slot name="description">
                    These reach real Slack, Jira and Notion workspaces. They stay staged until approved.
                </x-slot>

                <div class="space-y-2">
                    @foreach ($investigation->actions->where('was_write', true) as $a)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <code class="text-xs">{{ $a->tool }}</code>
                                    <x-filament::badge :color="match ($a->status) {
                                        'succeeded' => 'success',
                                        'failed' => 'danger',
                                        'rejected' => 'gray',
                                        default => 'warning',
                                    }">{{ str_replace('_', ' ', $a->status) }}</x-filament::badge>
                                </div>
                                <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
                                    {{ $a->result_summary ?? $a->error ?? collect($a->arguments)->flatten()->implode(' · ') }}
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                @if ($a->external_url)
                                    <a href="{{ $a->external_url }}" target="_blank" rel="noopener"
                                       class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                        open ↗
                                    </a>
                                @endif

                                @if ($a->status === 'pending_approval')
                                    <x-filament::button size="xs" wire:click="approveAction({{ $a->id }})">
                                        Approve
                                    </x-filament::button>
                                    <x-filament::button size="xs" color="gray" wire:click="rejectAction({{ $a->id }})">
                                        Reject
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- Evidence ledger --}}
        <x-filament::section heading="Evidence ledger" icon="heroicon-o-document-magnifying-glass" collapsible>
            <x-slot name="description">
                Recorded before the model was consulted. These IDs are the only citations that can be valid.
            </x-slot>

            <div class="space-y-2">
                @foreach ($investigation->evidence as $e)
                    <details class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <summary class="cursor-pointer text-sm">
                            <span class="font-mono font-semibold text-primary-600 dark:text-primary-400">{{ $e->public_id }}</span>
                            <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">{{ $e->source }}/{{ $e->kind }}</span>
                            <span class="ml-2 text-gray-950 dark:text-white">{{ $e->summary }}</span>
                        </summary>

                        <pre class="mt-3 overflow-x-auto rounded bg-gray-50 p-3 text-xs dark:bg-white/5">{{ json_encode($e->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>

                        @if ($e->source_url)
                            <a href="{{ $e->source_url }}" target="_blank" rel="noopener"
                               class="mt-2 inline-block text-xs text-primary-600 hover:underline dark:text-primary-400">
                                view source ↗
                            </a>
                        @endif
                    </details>
                @endforeach
            </div>
        </x-filament::section>

        {{-- What the agent actually did --}}
        <x-filament::section heading="Action log" icon="heroicon-o-list-bullet" collapsible collapsed>
            <div class="space-y-1">
                @foreach ($investigation->actions as $a)
                    <div class="flex items-start gap-3 rounded px-2 py-1.5 text-xs hover:bg-gray-50 dark:hover:bg-white/5">
                        <span class="w-6 shrink-0 text-right text-gray-400">{{ $a->sequence }}</span>
                        <span class="w-20 shrink-0 text-gray-500 dark:text-gray-400">{{ $a->phase }}</span>
                        <code class="w-56 shrink-0 truncate">{{ $a->tool }}</code>
                        <span class="min-w-0 flex-1 text-gray-600 dark:text-gray-300">
                            {{ \Illuminate\Support\Str::limit($a->result_summary ?? $a->error ?? '', 110) }}
                        </span>
                        <span class="w-14 shrink-0 text-right text-gray-400">
                            {{ $a->duration_ms !== null ? $a->duration_ms.'ms' : '' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>

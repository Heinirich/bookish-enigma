<div
    @if ($this->open && $this->isRunning)
        wire:poll.1500ms
    @endif
>
    @if ($this->open)
        @php $investigation = $this->investigation; @endphp

        {{-- Scrim: dismisses on click, but stays translucent so the page behind
             remains readable while the agent works. --}}
        <div
            class="fixed inset-0 z-40 bg-gray-950/20 backdrop-blur-[1px] dark:bg-gray-950/50"
            wire:click="close"
        ></div>

        <aside
            class="fi-investigation-stream fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col
                   border-l border-gray-200 bg-white shadow-2xl
                   dark:border-white/10 dark:bg-gray-900"
            x-data
            x-on:keydown.escape.window="$wire.close()"
        >
            {{-- Header --}}
            <div class="flex items-start justify-between gap-3 border-b border-gray-200 p-4 dark:border-white/10">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        @if ($this->isRunning)
                            <span class="relative flex h-2 w-2">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary-400 opacity-75"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full bg-primary-500"></span>
                            </span>
                        @endif

                        <h2 class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $investigation?->incident?->reference ?? 'Investigation' }}
                        </h2>

                        @if ($investigation)
                            <x-filament::badge size="xs" :color="match ($investigation->status) {
                                'completed' => 'success',
                                'failed' => 'danger',
                                'queued' => 'gray',
                                default => 'warning',
                            }">{{ $investigation->status }}</x-filament::badge>
                        @endif
                    </div>

                    <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                        {{ $investigation?->incident?->title }}
                    </p>
                </div>

                <button
                    type="button"
                    wire:click="close"
                    aria-label="Close"
                    class="shrink-0 rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700
                           dark:hover:bg-white/5 dark:hover:text-gray-200"
                >
                    <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                </button>
            </div>

            @if (! $investigation)
                <div class="p-4 text-sm text-gray-500 dark:text-gray-400">Investigation not found.</div>
            @else
                {{-- Phase stepper --}}
                <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <div class="flex items-center gap-1">
                        @foreach ($this->phases as $phase)
                            <div class="flex flex-1 flex-col gap-1.5">
                                <div @class([
                                    'h-1 rounded-full',
                                    'bg-primary-500' => $phase['state'] === 'done',
                                    'bg-primary-500 animate-pulse' => $phase['state'] === 'active',
                                    'bg-danger-500' => $phase['state'] === 'failed',
                                    'bg-gray-200 dark:bg-white/10' => $phase['state'] === 'pending',
                                ])></div>

                                <span @class([
                                    'text-[10px] leading-tight',
                                    'text-gray-950 dark:text-white font-medium' => $phase['state'] === 'active',
                                    'text-gray-500 dark:text-gray-400' => $phase['state'] === 'done',
                                    'text-danger-600 dark:text-danger-400' => $phase['state'] === 'failed',
                                    'text-gray-300 dark:text-gray-600' => $phase['state'] === 'pending',
                                ])>{{ $phase['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- No worker is the likeliest reason nothing moves; say so instead of spinning --}}
                @if ($this->isStalled)
                    <div class="m-4 rounded-lg border border-warning-300 bg-warning-50 p-3 text-xs dark:border-warning-500/40 dark:bg-warning-500/10">
                        <p class="font-medium text-warning-800 dark:text-warning-200">Nothing is consuming the queue.</p>
                        <p class="mt-1 text-warning-700 dark:text-warning-300">
                            {{ $this->pendingJobs }} job(s) waiting. Start a worker:
                        </p>
                        <code class="mt-1.5 block rounded bg-warning-100 px-2 py-1 text-warning-900 dark:bg-warning-500/20 dark:text-warning-100">php artisan queue:work</code>
                    </div>
                @endif

                @if ($investigation->status === 'failed')
                    <div class="m-4 rounded-lg border border-danger-300 bg-danger-50 p-3 text-xs dark:border-danger-500/40 dark:bg-danger-500/10">
                        <p class="font-medium text-danger-800 dark:text-danger-200">Investigation failed</p>
                        <p class="mt-1 text-danger-700 dark:text-danger-300">{{ $investigation->failure_reason }}</p>
                    </div>
                @endif

                {{-- Scrolling body --}}
                <div class="flex-1 space-y-4 overflow-y-auto p-4">

                    {{-- Action log, newest last, appearing as the agent writes them --}}
                    <div>
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Actions
                        </h3>

                        <div class="space-y-1.5">
                            @forelse ($this->actions as $action)
                                <div class="flex items-start gap-2 rounded-lg border border-gray-100 px-2.5 py-2 text-xs dark:border-white/5">
                                    <span @class([
                                        'mt-0.5 shrink-0',
                                        'text-success-500' => $action->status === 'succeeded',
                                        'text-danger-500' => $action->status === 'failed',
                                        'text-warning-500' => $action->status === 'pending_approval',
                                        'text-gray-400' => ! in_array($action->status, ['succeeded', 'failed', 'pending_approval']),
                                    ])>
                                        @if ($action->status === 'succeeded') &check;
                                        @elseif ($action->status === 'failed') &times;
                                        @else &bull;
                                        @endif
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-1.5">
                                            <code class="text-[11px] text-gray-950 dark:text-white">{{ $action->tool }}</code>
                                            <span class="text-[10px] text-gray-400">{{ $action->phase }}</span>
                                            @if ($action->was_write)
                                                <x-filament::badge size="xs" color="warning">write</x-filament::badge>
                                            @endif
                                        </div>

                                        @if ($action->result_summary || $action->error)
                                            <p class="mt-0.5 text-gray-500 dark:text-gray-400">
                                                {{ \Illuminate\Support\Str::limit($action->error ?? $action->result_summary, 120) }}
                                            </p>
                                        @endif
                                    </div>

                                    @if ($action->duration_ms !== null)
                                        <span class="shrink-0 text-[10px] text-gray-400">{{ $action->duration_ms }}ms</span>
                                    @endif
                                </div>
                            @empty
                                <p class="text-xs text-gray-400">
                                    {{ $this->isRunning ? 'Waiting for the agent to start…' : 'No actions recorded.' }}
                                </p>
                            @endforelse
                        </div>
                    </div>

                    {{-- Verification appears once synthesis has produced something to check --}}
                    @php $v = $this->verification; @endphp
                    @if ($v['generated'] > 0)
                        <div>
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Verification
                            </h3>

                            <div class="grid grid-cols-3 gap-2">
                                @foreach ([
                                    ['accepted', $v['accepted'], 'text-success-600 dark:text-success-400'],
                                    ['discarded', $v['discarded'], $v['discarded'] > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-950 dark:text-white'],
                                    ['fabricated', $v['fabricated'], $v['fabricated'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400'],
                                ] as [$label, $value, $class])
                                    <div class="rounded-lg border border-gray-100 p-2 text-center dark:border-white/5">
                                        <div class="text-lg font-bold {{ $class }}">{{ $value }}</div>
                                        <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ $label }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Hypotheses
                            </h3>

                            <div class="space-y-2">
                                @foreach ($investigation->hypotheses as $h)
                                    <div @class([
                                        'rounded-lg border p-2.5',
                                        'border-gray-200 dark:border-white/10' => $h->status === 'accepted',
                                        'border-danger-300 bg-danger-50 dark:border-danger-500/40 dark:bg-danger-500/10' => $h->status !== 'accepted',
                                    ])>
                                        <div class="flex items-center justify-between gap-2">
                                            @if ($h->status !== 'accepted')
                                                <x-filament::badge size="xs" color="danger">discarded</x-filament::badge>
                                            @elseif ($h->stance === 'ruled_out')
                                                <x-filament::badge size="xs" color="gray">ruled out</x-filament::badge>
                                            @else
                                                <x-filament::badge size="xs" color="success">cause</x-filament::badge>
                                            @endif

                                            <span class="text-[11px] font-semibold text-gray-950 dark:text-white">
                                                {{ number_format($h->confidence * 100) }}%
                                            </span>
                                        </div>

                                        <p class="mt-1.5 text-xs text-gray-950 dark:text-white">{{ $h->statement }}</p>

                                        @if ($h->remediation && $h->stance !== 'ruled_out')
                                            <p class="mt-1.5 rounded bg-primary-50 px-2 py-1 text-[11px] text-primary-900 dark:bg-primary-500/10 dark:text-primary-200">
                                                <span class="font-semibold">Next step</span> — {{ $h->remediation }}
                                            </p>
                                        @endif

                                        <div class="mt-1.5 flex flex-wrap gap-1">
                                            @foreach ($h->evidence as $e)
                                                <span class="rounded bg-primary-50 px-1.5 py-0.5 text-[10px] font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">
                                                    {{ $e->public_id }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Footer --}}
                <div class="flex items-center justify-between gap-2 border-t border-gray-200 p-3 dark:border-white/10">
                    <span class="text-[11px] text-gray-400">
                        @if ($investigation->duration_ms)
                            {{ number_format($investigation->duration_ms / 1000, 1) }}s ·
                        @endif
                        {{ $investigation->prompt_tokens }} in / {{ $investigation->completion_tokens }} out
                    </span>

                    <div class="flex items-center gap-2">
                        @if ($investigation->status === 'completed')
                            <x-filament::button
                                size="xs"
                                color="gray"
                                tag="a"
                                target="_blank"
                                icon="heroicon-o-arrow-down-tray"
                                :href="route('incidents.report.pdf', ['incident' => $investigation->incident_id])"
                            >
                                PDF
                            </x-filament::button>
                        @endif

                        @if ($investigation->incident)
                            <x-filament::button
                                size="xs"
                                color="gray"
                                tag="a"
                                :href="route('filament.admin.resources.incidents.view', ['record' => $investigation->incident_id])"
                            >
                                Full report
                            </x-filament::button>
                        @endif

                        <x-filament::button size="xs" wire:click="close">Close</x-filament::button>
                    </div>
                </div>
            @endif
        </aside>
    @endif
</div>

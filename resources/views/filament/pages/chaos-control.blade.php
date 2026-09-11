<x-filament-panels::page>
    @php $profile = $this->currentProfile(); @endphp

    <x-filament::section
        :heading="$profile->isHealthy() ? 'Currently healthy' : 'Degradation active: ' . $profile->label"
        :icon="$profile->isHealthy() ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'">

        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            @foreach ([
                'Base latency' => $profile->baseLatencyMs . 'ms',
                'Jitter'       => '± ' . $profile->jitterMs . 'ms',
                'Error rate'   => round($profile->errorRate * 100) . '%',
                'Live deploy'  => $profile->deploySha ? substr($profile->deploySha, 0, 7) : '—',
            ] as $label => $value)
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
            Applies to <code>GET /healthz</code> immediately — no restart, no deploy.
        </p>
    </x-filament::section>

    <x-filament::section heading="Scenarios" icon="heroicon-o-beaker">
        <x-slot name="description">
            Each writes a culprit deploy plus decoys, then backfills ~35 minutes of samples so the
            detector has a baseline to compare against.
        </x-slot>

        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($this->scenarios() as $key => $scenario)
                @php $active = $profile->scenarioKey === $key; @endphp

                <div @class([
                    'rounded-xl border p-4',
                    'border-primary-500 bg-primary-50 dark:bg-primary-500/10' => $active,
                    'border-gray-200 dark:border-white/10' => ! $active,
                ])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="font-semibold text-gray-950 dark:text-white">{{ $scenario['label'] }}</h3>
                                <x-filament::badge :color="$scenario['severity'] === 'sev1' ? 'danger' : 'warning'" size="xs">
                                    {{ strtoupper($scenario['severity']) }}
                                </x-filament::badge>
                                @if ($scenario['expects_no_deploy_cause'] ?? false)
                                    <x-filament::badge color="gray" size="xs">no deploy cause</x-filament::badge>
                                @endif
                            </div>

                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $scenario['description'] }}</p>

                            <div class="mt-2 flex flex-wrap gap-3 text-xs text-gray-400">
                                <span>{{ $scenario['profile']['base_latency_ms'] }}ms base</span>
                                <span>{{ round($scenario['profile']['error_rate'] * 100) }}% errors</span>
                                <span>{{ count($scenario['decoys']) }} decoy deploy(s)</span>
                            </div>
                        </div>

                        <x-filament::button
                            size="sm"
                            :color="$active ? 'gray' : 'primary'"
                            wire:click="stage('{{ $key }}')"
                            wire:loading.attr="disabled">
                            {{ $active ? 'Restage' : 'Stage' }}
                        </x-filament::button>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>

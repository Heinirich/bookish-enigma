<x-filament-panels::page>
    @php
        $settings = $this->settings();
        $observed = $this->observed();
        $histogram = $this->hourlyHistogram();
        $peak = max(1, collect($histogram)->max('count'));
    @endphp

    {{-- Configured cadence: what the system has been told to do --}}
    <x-filament::section heading="Current cadence" icon="heroicon-o-clock">
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            @foreach ([
                ['Polling', $settings->monitoring_enabled ? 'every ' . $settings->ping_interval_seconds . 's' : 'paused',
                    $settings->monitoring_enabled ? $settings->pingsPerHour() . ' samples/hour' : 'monitoring is off'],
                ['Detection', 'every ' . $settings->detect_interval_minutes . ' min',
                    'cooldown ' . $settings->incident_cooldown_minutes . ' min'],
                ['Auto-investigate', $settings->auto_investigate ? 'on' : 'off',
                    $settings->auto_investigate ? 'max ' . $settings->max_investigations_per_hour . '/hour' : 'manual only'],
                ['Quiet hours', $settings->quiet_hours_start !== null
                    ? sprintf('%02d:00–%02d:00', $settings->quiet_hours_start, $settings->quiet_hours_end)
                    : 'none',
                    $settings->inQuietHours() ? 'active right now' : 'UTC'],
            ] as [$label, $value, $note])
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
                    <div class="mt-1 text-lg font-semibold text-gray-950">{{ $value }}</div>
                    <div class="text-[11px] text-gray-400">{{ $note }}</div>
                </div>
            @endforeach
        </div>

        @unless ($settings->monitoring_enabled)
            <div class="mt-4 rounded-lg border border-warning-300 bg-warning-50 p-3 text-xs text-warning-800">
                Monitoring is paused. No samples are recorded and no incidents will be opened.
            </div>
        @endunless
    </x-filament::section>

    {{-- Observed rate: what actually happened --}}
    <x-filament::section heading="How often it actually runs" icon="heroicon-o-chart-bar">
        <x-slot name="description">
            Configured cadence is an intention; this is the measured rate.
        </x-slot>

        <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
            @foreach ([
                ['This hour', $observed['last_hour'] . ' / ' . $observed['cap'], $observed['at_cap'] ? 'at the cap' : 'within cap', $observed['at_cap']],
                ['Last 24 hours', $observed['last_24h'], $observed['incidents_24h'] . ' incidents opened', false],
                ['Last 7 days', $observed['last_7d'], 'investigations run', false],
                ['Average gap', $observed['mean_gap_minutes'] !== null ? $observed['mean_gap_minutes'] . ' min' : '—', 'between runs', false],
                ['Average run', $observed['mean_duration_s'] !== null ? $observed['mean_duration_s'] . 's' : '—', 'wall clock', false],
            ] as [$label, $value, $note, $warn])
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-bold {{ $warn ? 'text-warning-600' : 'text-gray-950' }}">{{ $value }}</div>
                    <div class="text-[11px] text-gray-400">{{ $note }}</div>
                </div>
            @endforeach
        </div>

        @if ($observed['at_cap'])
            <div class="mt-4 rounded-lg border border-warning-300 bg-warning-50 p-3 text-xs text-warning-800">
                <strong>The hourly cap is being hit.</strong> Incidents are still being opened, but
                automated investigation is being skipped until the hour rolls over. Raise the cap, or
                investigate those incidents by hand.
            </div>
        @endif

        {{-- Runs per hour over the last day --}}
        <div class="mt-5">
            <div class="mb-2 text-xs uppercase tracking-wide text-gray-500">Investigations per hour, last 24h</div>

            <div class="flex h-20 items-end gap-[3px]">
                @foreach ($histogram as $bucket)
                    <div class="group relative flex-1" title="{{ $bucket['label'] }} — {{ $bucket['count'] }} run(s)">
                        <div
                            class="w-full rounded-t {{ $bucket['count'] > 0 ? 'bg-primary-500' : 'bg-gray-200' }}"
                            style="height: {{ $bucket['count'] > 0 ? max(6, ($bucket['count'] / $peak) * 76) : 3 }}px"
                        ></div>
                    </div>
                @endforeach
            </div>

            <div class="mt-1 flex justify-between text-[10px] text-gray-400">
                <span>{{ $histogram[0]['label'] }}</span>
                <span>{{ $histogram[count($histogram) - 1]['label'] }}</span>
            </div>
        </div>

        <div class="mt-4 border-t border-gray-200 pt-3 text-xs text-gray-500">
            Last investigation
            {{ $observed['latest_at'] ? $observed['latest_at']->diffForHumans() : 'never' }}.
            @if ($settings->last_detect_at)
                Detector last evaluated {{ $settings->last_detect_at->diffForHumans() }}.
            @else
                <span class="text-warning-600">The scheduler has not run yet — start it with
                <code>php artisan schedule:work</code>.</span>
            @endif
        </div>
    </x-filament::section>

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-3">
            <x-filament::button type="submit" wire:loading.attr="disabled">Save schedule</x-filament::button>
            <span wire:loading wire:target="save" class="text-xs text-gray-500">saving…</span>
        </div>
    </form>

    <x-filament::section heading="What has to be running" icon="heroicon-o-command-line" collapsible collapsed>
        <p class="mb-2 text-sm text-gray-600">
            These settings only take effect while the scheduler and a queue worker are up. Both are
            separate processes from <code>php artisan serve</code>.
        </p>
        <pre class="overflow-x-auto rounded bg-gray-50 p-3 text-xs">php artisan schedule:work   # ticks every minute; enforces the cadence above
php artisan queue:work      # runs the investigations the detector queues</pre>
        <p class="mt-2 text-xs text-gray-500">
            Laravel's scheduler is fixed at boot, so both commands are scheduled every minute and the
            interval you set here is enforced inside them. That is what lets the cadence change from
            this page without a restart.
        </p>
    </x-filament::section>
</x-filament-panels::page>

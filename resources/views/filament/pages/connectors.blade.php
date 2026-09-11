<x-filament-panels::page>

    {{-- At-a-glance state, so it is obvious which connectors would actually write --}}
    <x-filament::section heading="Status" icon="heroicon-o-signal">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($this->connectorList() as $key => $connector)
                @php $status = $this->statusFor($key); @endphp

                <div @class([
                    'rounded-xl border p-3',
                    'border-success-300 dark:border-success-500/40' => $status['driver'] === 'api' && $status['ready'],
                    'border-warning-300 dark:border-warning-500/40' => $status['driver'] === 'api' && ! $status['ready'],
                    'border-gray-200 dark:border-white/10' => $status['driver'] !== 'api',
                ])>
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-medium text-gray-950 dark:text-white">{{ $connector['label'] }}</span>

                        @if ($status['driver'] !== 'api')
                            <x-filament::badge color="gray" size="xs">fake</x-filament::badge>
                        @elseif ($status['ready'])
                            <x-filament::badge color="success" size="xs">live</x-filament::badge>
                        @else
                            <x-filament::badge color="warning" size="xs">incomplete</x-filament::badge>
                        @endif
                    </div>

                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                        @if ($status['driver'] !== 'api')
                            Records calls locally. Nothing leaves this machine.
                        @elseif (! $status['ready'])
                            Missing {{ implode(', ', $status['missing']) }} — falls back to the fake.
                        @elseif ($status['last_status'] === 'ok')
                            {{ \Illuminate\Support\Str::limit($status['last_message'], 70) }}
                        @elseif ($status['last_status'] === 'failed')
                            <span class="text-danger-600 dark:text-danger-400">
                                {{ \Illuminate\Support\Str::limit($status['last_message'], 70) }}
                            </span>
                        @else
                            Configured but never tested.
                        @endif
                    </p>

                    @if ($status['last_tested_at'])
                        <p class="mt-1 text-[11px] text-gray-400">
                            tested {{ $status['last_tested_at']->diffForHumans() }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="mt-4 border-t border-gray-200 pt-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
            Write actions stay behind approval regardless of driver — a live connector still never
            posts without you releasing it on the incident page.
        </p>
    </x-filament::section>

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-3">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                Save connectors
            </x-filament::button>

            <span wire:loading wire:target="save" class="text-xs text-gray-500">saving…</span>
        </div>
    </form>

    <x-filament::section heading="Where credentials live" icon="heroicon-o-lock-closed" collapsible collapsed>
        <ul class="list-disc space-y-1.5 pl-5 text-sm text-gray-600 dark:text-gray-300">
            <li>Stored in <code>connector_settings</code>, encrypted with the app key — not readable
                from a database dump alone.</li>
            <li>Never rendered back to the browser. A saved secret shows as a masked placeholder, and
                submitting it blank keeps the stored value.</li>
            <li>Database values override <code>.env</code>. A field left blank falls back to the
                environment, so an existing deployment keeps working unchanged.</li>
            <li><strong>Test connection</strong> only performs read-only identity checks. It never
                creates a channel, ticket or page.</li>
            <li>Rotating <code>APP_KEY</code> makes stored credentials unreadable and they must be
                re-entered.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>

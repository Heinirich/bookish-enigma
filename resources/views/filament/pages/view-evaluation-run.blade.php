<x-filament-panels::page>
    @php $run = $this->record; @endphp

    <x-filament::section heading="Summary" icon="heroicon-o-chart-bar">
        <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
            @foreach ([
                ['Root cause hit', $run->root_cause_hit_rate, 'higher', 'leading hypothesis named the real deploy'],
                ['Evidence real', $run->evidence_real_rate, 'higher', 'citations that refer to real evidence'],
                ['Grounding', $run->grounding_rate, 'higher', 'figures traceable to cited evidence'],
                ['Brier score', $run->brier_score, 'lower', 'confidence calibration, 0 is perfect'],
                ['Actions staged', $run->action_completeness, 'higher', 'Slack, Jira and Notion all staged'],
            ] as [$label, $value, $direction, $note])
                @php
                    $isBrier = $direction === 'lower';
                    $display = $value === null
                        ? '—'
                        : ($isBrier ? number_format($value, 3) : number_format($value * 100, 1) . '%');
                    $tone = match (true) {
                        $value === null => 'text-gray-400',
                        $isBrier => $value <= 0.1 ? 'text-success-600' : ($value <= 0.25 ? 'text-warning-600' : 'text-danger-600'),
                        $value >= 0.9 => 'text-success-600',
                        $value >= 0.6 => 'text-warning-600',
                        default => 'text-danger-600',
                    };
                @endphp
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-bold {{ $tone }}">{{ $display }}</div>
                    <div class="mt-0.5 text-[11px] leading-tight text-gray-400">{{ $note }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Per scenario" icon="heroicon-o-beaker">
        <x-slot name="description">
            Each row is one seeded incident with a known cause. A miss is more informative than a hit —
            it says where the approach actually breaks.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                        <th class="py-2 pr-3">Scenario</th>
                        <th class="py-2 pr-3">Result</th>
                        <th class="py-2 pr-3">Expected</th>
                        <th class="py-2 pr-3">Predicted</th>
                        <th class="py-2 pr-3 text-right">Confidence</th>
                        <th class="py-2 pr-3 text-right">Cited</th>
                        <th class="py-2 pr-3 text-right">Fabricated</th>
                        <th class="py-2 pr-3 text-right">Grounding</th>
                        <th class="py-2 text-right">Brier</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($run->scores as $score)
                        <tr class="border-b border-gray-100 last:border-0">
                            <td class="py-2 pr-3">
                                <code class="text-xs">{{ $score->scenario_key }}</code>
                                @if ($score->notes)
                                    <div class="text-[11px] text-warning-600">{{ $score->notes }}</div>
                                @endif
                            </td>
                            <td class="py-2 pr-3">
                                @if ($score->root_cause_hit)
                                    <x-filament::badge color="success" size="xs">hit</x-filament::badge>
                                @else
                                    <x-filament::badge color="danger" size="xs">miss</x-filament::badge>
                                @endif
                            </td>
                            <td class="py-2 pr-3 font-mono text-xs text-gray-500">
                                {{ $score->expected_sha ? substr($score->expected_sha, 0, 7) : 'none' }}
                            </td>
                            <td class="py-2 pr-3 font-mono text-xs {{ $score->root_cause_hit ? 'text-gray-500' : 'text-danger-600' }}">
                                {{ $score->predicted_sha ? substr($score->predicted_sha, 0, 7) : 'none' }}
                            </td>
                            <td class="py-2 pr-3 text-right">{{ number_format(($score->top_confidence ?? 0) * 100) }}%</td>
                            <td class="py-2 pr-3 text-right">{{ $score->cited_count }}</td>
                            <td class="py-2 pr-3 text-right {{ $score->fabricated_count > 0 ? 'font-semibold text-danger-600' : 'text-success-600' }}">
                                {{ $score->fabricated_count }}
                            </td>
                            <td class="py-2 pr-3 text-right">{{ number_format(($score->grounding_rate ?? 0) * 100) }}%</td>
                            <td class="py-2 text-right">{{ number_format((float) $score->brier, 3) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="How to read this" icon="heroicon-o-information-circle" collapsible collapsed>
        <ul class="list-disc space-y-1.5 pl-5 text-sm text-gray-600">
            <li><strong>Root cause hit</strong> — the leading accepted hypothesis named the deploy that
                actually caused the degradation. For <code>no-deploy-cause</code>, a hit means it
                correctly implicated <em>no</em> deployment.</li>
            <li><strong>Evidence real</strong> — the share of citations pointing at evidence that exists.
                Below 100% means a fabricated citation survived validation, which is a defect, not a score.</li>
            <li><strong>Brier</strong> — mean squared error between stated confidence and correctness.
                A confident wrong answer is punished far harder than a hedged one. Lower is better.</li>
            <li>A run where every case is a hit at ~100% confidence is a sign the scenarios are too easy,
                not that the agent is calibrated. <code>ambiguous-tie</code> and <code>no-deploy-cause</code>
                exist to prevent that reading.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>

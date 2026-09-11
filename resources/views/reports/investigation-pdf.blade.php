{{--
    Print layout for dompdf, which supports neither flexbox nor grid — hence
    tables for structure and inline-ish CSS rather than the panel's utilities.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $incident->reference }} — investigation report</title>
    <style>
        @page { margin: 34px 40px 56px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            line-height: 1.45;
            color: #1f2430;
        }

        h1 { font-size: 17px; margin: 0 0 3px; }
        h2 {
            font-size: 11px; text-transform: uppercase; letter-spacing: .07em;
            color: #6b7280; margin: 20px 0 7px;
            border-bottom: 1px solid #e5e7eb; padding-bottom: 4px;
        }

        .muted { color: #6b7280; }
        .mono  { font-family: DejaVu Sans Mono, monospace; font-size: 9px; }
        .right { text-align: right; }
        .center{ text-align: center; }

        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }

        .meta td { padding: 2px 0; }
        .meta .label { color: #6b7280; width: 88px; }

        .stats td {
            border: 1px solid #e5e7eb; padding: 7px 4px; text-align: center; width: 16.6%;
        }
        .stats .value { font-size: 15px; font-weight: bold; }
        .stats .label { font-size: 7.5px; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; }

        .ok   { color: #15803d; }
        .bad  { color: #b91c1c; }
        .warn { color: #b45309; }

        .hyp {
            border: 1px solid #e5e7eb; border-left-width: 3px; border-left-color: #9ca3af;
            padding: 8px 10px; margin-bottom: 8px;
        }
        .hyp.accepted  { border-left-color: #16a34a; }
        .hyp.discarded { border-left-color: #dc2626; background: #fef2f2; }
        .hyp.ruledout  { border-left-color: #9ca3af; background: #fafafa; }

        .badge {
            display: inline-block; padding: 1px 5px; font-size: 7.5px; font-weight: bold;
            text-transform: uppercase; letter-spacing: .04em; border-radius: 3px;
        }
        .badge.accepted  { background: #dcfce7; color: #166534; }
        .badge.discarded { background: #fee2e2; color: #991b1b; }
        .badge.ruledout  { background: #f3f4f6; color: #4b5563; }

        .chip {
            display: inline-block; padding: 1px 4px; margin-right: 3px;
            background: #eef2ff; color: #3730a3; border-radius: 3px;
            font-family: DejaVu Sans Mono, monospace; font-size: 8px;
        }

        .evidence-row { border-bottom: 1px solid #f3f4f6; padding: 4px 0; }
        .log td { border-bottom: 1px solid #f3f4f6; padding: 3px 4px; font-size: 8.5px; }

        .footer {
            position: fixed; bottom: -34px; left: 0; right: 0;
            font-size: 7.5px; color: #9ca3af;
            border-top: 1px solid #e5e7eb; padding-top: 5px;
        }
    </style>
</head>
<body>

@php
    $v = $report->verification();
    $signal = $incident->detection_signal ?? [];
@endphp

<div class="footer">
    <table>
        <tr>
            <td>{{ $incident->reference }} — generated {{ now()->toDayDateTimeString() }} UTC by Connector</td>
            <td class="right">Model {{ $investigation->model }}</td>
        </tr>
    </table>
</div>

<h1>{{ $incident->reference }} — {{ $incident->title }}</h1>
<div class="muted">Incident investigation report</div>

<h2>Incident</h2>
<table class="meta">
    <tr><td class="label">Severity</td><td><strong>{{ strtoupper($incident->severity) }}</strong></td>
        <td class="label">Detected</td><td>{{ $incident->detected_at->toDayDateTimeString() }} UTC</td></tr>
    <tr><td class="label">Service</td><td>{{ $incident->monitoredEndpoint?->service ?? '—' }}</td>
        <td class="label">Window</td><td>{{ $incident->window_start->toTimeString() }} – {{ $incident->window_end->toTimeString() }} UTC</td></tr>
    <tr><td class="label">Trigger</td><td>{{ str_replace('_', ' ', $signal['reason'] ?? $incident->trigger) }}</td>
        <td class="label">Baseline p95</td><td>{{ $signal['baseline']['p95'] ?? '—' }}ms → {{ $signal['spike']['p95'] ?? '—' }}ms ({{ $signal['latency_ratio'] ?? '—' }}×)</td></tr>
</table>

<h2>Verification</h2>
<table class="stats">
    <tr>
        <td><div class="value">{{ $v['generated'] }}</div><div class="label">Claims</div></td>
        <td><div class="value ok">{{ $v['accepted'] }}</div><div class="label">Accepted</div></td>
        <td><div class="value {{ $v['discarded'] > 0 ? 'warn' : '' }}">{{ $v['discarded'] }}</div><div class="label">Discarded</div></td>
        <td><div class="value">{{ $v['citations'] }}</div><div class="label">Citations</div></td>
        <td><div class="value {{ $v['fabricated'] > 0 ? 'bad' : 'ok' }}">{{ $v['fabricated'] }}</div><div class="label">Fabricated</div></td>
        <td><div class="value">{{ $v['evidence'] }}</div><div class="label">Evidence</div></td>
    </tr>
</table>
<p class="muted" style="margin-top:6px">
    Every citation below was checked against the evidence gathered during this investigation.
    Claims citing evidence that does not exist are retained and marked discarded rather than removed.
</p>

<h2>Findings</h2>
@forelse ($investigation->hypotheses as $h)
    @php
        $accepted = $h->status === 'accepted';
        $ruledOut = $h->stance === 'ruled_out';
    @endphp
    <div class="hyp {{ $accepted ? ($ruledOut ? 'ruledout' : 'accepted') : 'discarded' }}">
        <table>
            <tr>
                <td>
                    <span class="badge {{ $accepted ? ($ruledOut ? 'ruledout' : 'accepted') : 'discarded' }}">
                        {{ $accepted ? ($ruledOut ? 'Ruled out' : 'Cause') : 'Discarded — unsupported' }}
                    </span>
                    <span class="muted mono">#{{ $h->rank }}</span>
                </td>
                <td class="right muted mono">
                    grounding {{ number_format((float) $h->grounding_score, 2) }} ·
                    <strong style="color:#1f2430">{{ number_format($h->confidence * 100) }}% likely</strong>
                </td>
            </tr>
        </table>

        <p style="margin:6px 0 5px">{{ $h->statement }}</p>

        @if ($h->mechanism)
            <p style="margin:0 0 5px; color:#4b5563; border-left:2px solid #e5e7eb; padding-left:8px">
                <strong>Mechanism.</strong> {{ $h->mechanism }}
            </p>
        @endif

        @if ($h->remediation && ! $ruledOut)
            <p style="margin:0 0 5px; background:#eef2ff; padding:5px 8px">
                <strong>Suggested next step.</strong> {{ $h->remediation }}
            </p>
        @endif

        <div>
            <span class="muted" style="font-size:8px">Evidence:</span>
            @forelse ($h->evidence as $e)
                <span class="chip">{{ $e->public_id }}{{ $e->pivot->relation === 'contradicts' ? ' ✕' : '' }}</span>
            @empty
                <span class="muted">none</span>
            @endforelse
        </div>

        @if ($fabricated = ($h->validation_report['fabricated'] ?? []))
            <div class="bad" style="margin-top:4px; font-size:8.5px">
                Rejected citations (no such evidence): {{ implode(', ', $fabricated) }}
            </div>
        @endif

        @if ($ungrounded = ($h->validation_report['ungrounded_tokens'] ?? []))
            <div class="warn" style="margin-top:3px; font-size:8.5px">
                Figures absent from the cited evidence: {{ implode(', ', $ungrounded) }}
            </div>
        @endif

        @if ($h->root_cause_sha)
            <div class="muted mono" style="margin-top:4px">
                {{ $ruledOut ? 'Excludes' : 'Implicates' }} deploy {{ substr($h->root_cause_sha, 0, 7) }}
            </div>
        @endif
    </div>
@empty
    <p class="muted">No hypotheses were produced.</p>
@endforelse

<h2>Evidence ledger</h2>
<p class="muted" style="margin:0 0 6px">
    Recorded before the model was consulted. These identifiers are the only citations that can be valid.
</p>
@foreach ($investigation->evidence as $e)
    <div class="evidence-row">
        <table>
            <tr>
                <td style="width:46px" class="mono"><strong>{{ $e->public_id }}</strong></td>
                <td>
                    {{ $e->summary }}
                    <div class="muted mono">{{ $e->source }}/{{ $e->kind }}{{ $e->source_url ? ' · '.$e->source_url : '' }}</div>
                </td>
            </tr>
        </table>
    </div>
@endforeach

<h2>Actions taken</h2>
<table class="log">
    <tr class="muted">
        <th style="width:20px" class="right">#</th>
        <th style="width:62px">Phase</th>
        <th style="width:132px">Tool</th>
        <th>Result</th>
        <th style="width:46px" class="right">Time</th>
    </tr>
    @foreach ($investigation->actions as $a)
        <tr>
            <td class="right muted">{{ $a->sequence }}</td>
            <td class="muted">{{ $a->phase }}</td>
            <td class="mono">{{ $a->tool }}{{ $a->was_write ? ' *' : '' }}</td>
            <td>
                {{ \Illuminate\Support\Str::limit($a->result_summary ?? $a->error ?? $a->status, 96) }}
                @if ($a->external_url)
                    <div class="muted mono">{{ $a->external_url }}</div>
                @endif
            </td>
            <td class="right muted">{{ $a->duration_ms !== null ? $a->duration_ms.'ms' : '—' }}</td>
        </tr>
    @endforeach
</table>
<p class="muted" style="margin-top:5px; font-size:8px">
    * Write action — reaches an external service only after explicit approval.
</p>

<h2>Run</h2>
<table class="meta">
    <tr><td class="label">Status</td><td>{{ $investigation->status }}</td>
        <td class="label">Duration</td><td>{{ number_format(($investigation->duration_ms ?? 0) / 1000, 1) }}s</td></tr>
    <tr><td class="label">Tokens</td><td>{{ $investigation->prompt_tokens }} in / {{ $investigation->completion_tokens }} out</td>
        <td class="label">Tool loops</td><td>{{ $investigation->tool_iterations }}</td></tr>
</table>

</body>
</html>

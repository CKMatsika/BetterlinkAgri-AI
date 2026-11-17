<!DOCTYPE html>
<html lang="{{ $language->value }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1c1917; line-height: 1.45; }
        h1 { font-size: 18px; color: #065f46; margin-bottom: 4px; }
        h2 { font-size: 13px; color: #065f46; border-bottom: 1px solid #d6d3d1; padding-bottom: 3px; margin-top: 16px; }
        .meta { color: #57534e; font-size: 10px; margin-bottom: 12px; }
        .badge { display: inline-block; padding: 2px 8px; background: #e7e5e4; border-radius: 10px; font-size: 10px; font-weight: bold; }
        .notice { padding: 8px 10px; background: #fffbeb; border: 1px solid #f59e0b; border-radius: 4px; color: #78350f; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d6d3d1; padding: 5px 7px; text-align: left; font-size: 10px; }
        th { background: #f5f5f4; font-weight: bold; }
        ul { margin: 4px 0 0 16px; padding: 0; }
    </style>
</head>
<body>
    <h1>BetterlinkAgr AI — {{ $labels['title'] }}</h1>
    <div class="meta">
        <strong>{{ $labels['plan'] }}:</strong> {{ $plan->title }} |
        <strong>{{ $labels['enterprise'] }}:</strong> {{ $enterpriseName }} |
        <strong>{{ $labels['scale'] }}:</strong> {{ $plan->scale_value }} {{ $plan->scale_unit }} |
        <strong>{{ $labels['status'] }}:</strong> {{ $plan->state?->value ?? $plan->state }} |
        <strong>{{ $labels['start_date'] }}:</strong> {{ $plan->planned_start_date?->toDateString() ?? 'TBD' }}
    </div>

    <h2>1. {{ $labels['verdict'] }}</h2>
    <p>
        <strong>{{ strtoupper((string) ($farmerPayload['viability_verdict'] ?? 'PENDING')) }}</strong>
        @if(!empty($farmerPayload['verdict_label']))
            <span class="badge">{{ $farmerPayload['verdict_label'] }}</span>
        @endif
    </p>
    @if(!empty($farmerPayload['verdict_reasons']))
        <ul>
            @foreach($farmerPayload['verdict_reasons'] as $reason)
                <li>{{ $reason }}</li>
            @endforeach
        </ul>
    @endif

    <h2>2. {{ $labels['financials'] }}</h2>
    @if($farmerPayload['numbers_hidden_pending_review'] ?? false)
        <div class="notice">{{ $labels['hidden_notice'] }}</div>
    @elseif(!empty($farmerPayload['projection']))
        @php($p = $farmerPayload['projection'])
        @php($cur = $p['currency'] instanceof \BackedEnum ? $p['currency']->value : (string) $p['currency'])
        <table>
            <thead>
                <tr>
                    <th>Metric</th>
                    <th>Amount / Value</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>{{ $labels['total_cost'] }}</td><td>{{ $cur }} {{ number_format($p['total_cost_minor'] / 100, 2) }}</td></tr>
                <tr><td>{{ $labels['total_revenue'] }}</td><td>{{ $cur }} {{ number_format($p['total_revenue_minor'] / 100, 2) }}</td></tr>
                <tr><td>{{ $labels['net_margin'] }}</td><td><strong>{{ $cur }} {{ number_format($p['net_margin_minor'] / 100, 2) }}</strong></td></tr>
                <tr><td>{{ $labels['peak_funding'] }}</td><td>{{ $cur }} {{ number_format($p['peak_funding_need_minor'] / 100, 2) }}</td></tr>
                <tr><td>{{ $labels['break_even'] }}</td><td>{{ $cur }} {{ number_format($p['break_even_price_minor'] / 100, 2) }} / unit</td></tr>
                <tr><td>ROI</td><td>{{ number_format($p['roi_pct_scaled'] / 100, 2) }}%</td></tr>
            </tbody>
        </table>
    @else
        <p>Pending projection calculation.</p>
    @endif

    <h2>3. {{ $labels['recommendations'] }}</h2>
    @if($plan->riskFlags->isNotEmpty())
        <table>
            <thead>
                <tr>
                    <th>Severity</th>
                    <th>Risk</th>
                    <th>Mitigation</th>
                </tr>
            </thead>
            <tbody>
                @foreach($plan->riskFlags as $flag)
                    <tr>
                        <td>{{ strtoupper((string) $flag->severity) }}</td>
                        <td>{{ $flag->description }}</td>
                        <td>{{ $flag->mitigation_note }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($plan->checklistItems->isNotEmpty())
        <ul>
            @foreach($plan->checklistItems as $item)
                <li><strong>{{ $item->title }}</strong> ({{ $item->authority_or_body }}) — {{ $item->description }}</li>
            @endforeach
        </ul>
    @endif
</body>
</html>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Partner statement {{ $from->format('F Y') }}</title>
    @php $n = fn ($v) => number_format((float) $v); @endphp
    <style>
        @page { margin: 30px 34px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #1c2230; }
        table { border-collapse: collapse; width: 100%; }
        .header td { vertical-align: top; }
        .brand { font-size: 16px; font-weight: bold; color: #c1704a; }
        .muted { color: #666; }
        h1 { margin: 0; font-size: 15px; text-align: right; letter-spacing: 1px; }
        .box { margin: 14px 0; background: #faf6f3; border: 1px solid #efe2d8; }
        .box td { padding: 8px 10px; }
        .lines th { text-align: left; font-size: 8px; text-transform: uppercase; color: #555; border-bottom: 1px solid #1c2230; padding: 5px 4px; }
        .lines td { padding: 5px 4px; border-bottom: 1px solid #eee; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 1px solid #1c2230; border-bottom: none; }
        .note { margin-top: 14px; font-size: 8px; color: #666; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="brand">{{ config('app.name') }}</div>
                <div class="muted">Partner statement</div>
            </td>
            <td>
                <h1>{{ $from->format('F Y') }}</h1>
                <div class="muted" style="text-align: right">{{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}</div>
            </td>
        </tr>
    </table>

    <table class="box">
        <tr>
            <td><strong>{{ $partner->name }}</strong><br><span class="muted">Agent number {{ $partner->number }}{{ $partner->tin ? ' · TIN '.$partner->tin : '' }}</span></td>
            <td class="num">Opening: <strong>UGX {{ $n($opening) }}</strong><br>This month: <strong>UGX {{ $n($closing - $opening) }}</strong><br>Closing: <strong>UGX {{ $n($closing) }}</strong></td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr><th>Date</th><th>Description</th><th class="num">Earned</th><th class="num">Tax withheld</th><th class="num">You get</th></tr>
        </thead>
        <tbody>
            @forelse($lines as $l)
                <tr>
                    <td>{{ $l->earned_at->format('d M Y') }}</td>
                    <td>{{ $l->typeLabel() }}<br><span class="muted">{{ $l->description }}@if($l->rate_percent) · {{ rtrim(rtrim(number_format($l->rate_percent, 2), '0'), '.') }}% of {{ $n($l->base_amount) }}@endif</span></td>
                    <td class="num">{{ $n($l->gross) }}</td>
                    <td class="num">{{ $l->wht ? $n($l->wht) : '' }}</td>
                    <td class="num">{{ $n($l->net) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Nothing earned this month.</td></tr>
            @endforelse
            <tr class="total"><td colspan="2">Total</td><td class="num">{{ $n($lines->sum('gross')) }}</td><td class="num">{{ $n($lines->sum('wht')) }}</td><td class="num">{{ $n($lines->sum('net')) }}</td></tr>
        </tbody>
    </table>

    <p class="note">
        Today: on hold UGX {{ $n($balances['on_hold']) }} · available UGX {{ $n($balances['available']) }} · paid out UGX {{ $n($balances['paid']) }}.
        @if($lines->sum('wht') != 0)Tax withheld is paid to the Uganda Revenue Authority on your behalf. @endif Generated {{ now()->format('d M Y, H:i') }}.
    </p>
</body>
</html>

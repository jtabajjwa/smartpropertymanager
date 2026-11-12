<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $payment->number }}</title>
    @php
        $brand = $landlord?->brand_color ?: '#c1704a';
        $ugx = fn ($v) => 'UGX '.number_format((float) $v);
        $cancelled = $payment->status === 'cancelled';
    @endphp
    <style>
        @page { margin: 26px 28px 30px 28px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1c2230; }
        table { border-collapse: collapse; }
        .header { width: 100%; border-bottom: 2px solid #1c2230; padding-bottom: 10px; }
        .header td { vertical-align: top; }
        .logo { max-height: 40px; max-width: 140px; margin-bottom: 4px; }
        .landlord { font-size: 14px; font-weight: bold; }
        .meta-small { font-size: 8.5px; color: #666; margin-top: 1px; }
        .title { text-align: right; }
        .title h1 { margin: 0; font-size: 18px; letter-spacing: 2px; color: {{ $brand }}; }
        .title .no { font-size: 10px; font-weight: bold; margin-top: 2px; }
        .title .date { font-size: 9px; color: #555; margin-top: 1px; }

        .amount { margin: 14px 0 12px; width: 100%; background: #faf6f3; border: 1px solid #efe2d8; }
        .amount td { padding: 10px 12px; vertical-align: middle; }
        .amount .label { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #8a6a55; }
        .amount .figure { font-size: 20px; font-weight: bold; color: {{ $brand }}; margin-top: 2px; }
        .amount .words { font-size: 9.5px; font-style: italic; color: #1c2230; }

        .details { width: 100%; margin-bottom: 12px; }
        .details td { padding: 2.5px 0; vertical-align: top; }
        .details .k { color: #777; width: 92px; }
        .details .v { font-weight: bold; }

        .section { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #777; margin: 4px 0 4px; }
        .lines { width: 100%; margin-bottom: 10px; }
        .lines th { background: #f4f5f7; text-align: left; padding: 5px 6px; font-size: 7.5px; text-transform: uppercase; color: #555; border-bottom: 1px solid #ddd; }
        .lines td { padding: 6px; border-bottom: 1px solid #eee; vertical-align: top; }
        .lines .r { text-align: right; white-space: nowrap; }
        .lines .sub { font-size: 8px; color: #888; }
        .lines .total td { border-top: 2px solid #1c2230; border-bottom: none; font-weight: bold; }
        .zero { color: #2e7d32; }
        .owing { color: #c0392b; font-weight: bold; }

        .balance { width: 100%; margin: 4px 0 14px; }
        .balance td { padding: 8px 10px; }
        .balance-due { background: #fdecea; color: #a93226; }
        .balance-clear { background: #e8f5e9; color: #2e7d32; }
        .balance .big { font-size: 13px; font-weight: bold; }

        .sign { width: 100%; margin-top: 18px; }
        .sign td { width: 50%; padding-top: 22px; vertical-align: bottom; font-size: 8.5px; color: #555; }
        .sign .line { border-top: 1px solid #999; padding-top: 3px; margin-right: 18px; }
        .note { margin-top: 12px; padding-top: 8px; border-top: 1px solid #eee; font-size: 8.5px; color: #555; white-space: pre-line; }
        .footer { margin-top: 10px; font-size: 7.5px; color: #999; text-align: center; }
        .stamp { position: absolute; top: 210px; left: 40px; width: 420px; text-align: center; font-size: 46px; font-weight: bold; color: rgba(192, 57, 43, 0.18); transform: rotate(-24deg); letter-spacing: 6px; }
    </style>
</head>
<body>
    @if($cancelled)
        <div class="stamp">CANCELLED</div>
    @endif

    <table class="header">
        <tr>
            <td style="width: 62%;">
                @if($landlord?->logoDataUri())
                    <img class="logo" src="{{ $landlord->logoDataUri() }}" alt="{{ $landlord->name }}"><br>
                @endif
                <div class="landlord">{{ $landlord->name ?? config('app.name') }}</div>
                @if($landlord?->address)<div class="meta-small">{{ $landlord->address }}</div>@endif
                @if($landlord?->contact_phone || $landlord?->contact_email)
                    <div class="meta-small">{{ $landlord->contact_phone }}{{ $landlord->contact_phone && $landlord->contact_email ? ' · ' : '' }}{{ $landlord->contact_email }}</div>
                @endif
            </td>
            <td style="width: 38%;" class="title">
                <h1>RECEIPT</h1>
                <div class="no">No. {{ $payment->number }}</div>
                <div class="date">{{ $payment->payment_date->format('d M Y') }}</div>
            </td>
        </tr>
    </table>

    <table class="amount">
        <tr>
            <td style="width: 42%;">
                <div class="label">Amount received</div>
                <div class="figure">{{ $ugx($payment->amount) }}</div>
            </td>
            <td class="words">{{ $inWords }}</td>
        </tr>
    </table>

    <table class="details">
        <tr><td class="k">Received from</td><td class="v">{{ $payer }}@if($payerPhone) <span style="font-weight:normal;color:#777;">· {{ $payerPhone }}</span>@endif</td></tr>
        @if($units->isNotEmpty())
            <tr><td class="k">Property</td><td>{{ $units->join(', ') }}</td></tr>
        @endif
        <tr><td class="k">Paid by</td><td>{{ $method }}@if($online) <span style="color:#777;">(through {{ config('app.name') }})</span>@endif</td></tr>
        @if($payment->reference)
            <tr><td class="k">Reference</td><td>{{ $payment->reference }}</td></tr>
        @endif
    </table>

    <div class="section">Being payment for</div>
    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th class="r">Invoice</th>
                <th class="r">Paid now</th>
                <th class="r">Still owed</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $line)
                <tr>
                    <td>{{ $line['title'] }}<div class="sub">{{ $line['number'] }}@if($line['unit'] && $units->count() > 1) · {{ $line['unit'] }}@endif</div></td>
                    <td class="r">{{ number_format($line['invoice_amount']) }}</td>
                    <td class="r">{{ number_format($line['paid_now']) }}</td>
                    <td class="r {{ $line['balance'] > 0 ? 'owing' : 'zero' }}">{{ $line['balance'] > 0 ? number_format($line['balance']) : 'Paid' }}</td>
                </tr>
            @endforeach
            @if($advance > 0)
                <tr>
                    <td>{{ $lines->isEmpty() ? 'Rent / advance payment' : 'Paid in advance' }}<div class="sub">Kept on account; it pays the next invoice.</div></td>
                    <td class="r">—</td>
                    <td class="r">{{ number_format($advance) }}</td>
                    <td class="r">—</td>
                </tr>
            @endif
            <tr class="total">
                <td colspan="2">Total received</td>
                <td class="r">{{ $ugx($payment->amount) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    @if($owed !== null && !$cancelled)
        <table class="balance">
            <tr>
                @if($owed > 0)
                    <td class="balance-due">Balance outstanding as at {{ now()->format('d M Y') }}</td>
                    <td class="balance-due big" style="text-align:right;">{{ $ugx($owed) }}</td>
                @elseif($credit > 0)
                    <td class="balance-clear">Fully paid up · in advance as at {{ now()->format('d M Y') }}</td>
                    <td class="balance-clear big" style="text-align:right;">{{ $ugx($credit) }}</td>
                @else
                    <td class="balance-clear">Fully paid up as at {{ now()->format('d M Y') }}</td>
                    <td class="balance-clear big" style="text-align:right;">Nothing owed</td>
                @endif
            </tr>
        </table>
    @endif

    <table class="sign">
        <tr>
            <td><div class="line">Received by{{ $receivedBy ? ': '.$receivedBy : '' }}</div></td>
            <td><div class="line">Signature &amp; stamp</div></td>
        </tr>
    </table>

    @if($payment->notes && !$online)
        <div class="note"><strong>Note:</strong> {{ $payment->notes }}</div>
    @endif
    @if($landlord?->invoice_footer_note)
        <div class="note">{{ $landlord->invoice_footer_note }}</div>
    @endif

    <div class="footer">Generated by {{ config('app.name') }} on {{ now()->format('d M Y, H:i') }} · Receipt {{ $payment->number }}</div>
</body>
</html>

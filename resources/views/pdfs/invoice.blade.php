<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    @php
        $brandColor = $invoice->landlord?->brand_color ?: '#c1704a';
    @endphp
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #1c2230; }
        .header { width: 100%; margin-bottom: 24px; border-bottom: 2px solid #1c2230; padding-bottom: 14px; }
        .header td { vertical-align: top; }
        .header .logo { max-height: 50px; max-width: 180px; margin-bottom: 6px; }
        .header .landlord { font-size: 18px; font-weight: bold; color: #1c2230; }
        .header .landlord-meta { font-size: 10px; color: #666; margin-top: 2px; }
        .title { text-align: right; }
        .title h1 { font-size: 20px; margin: 0; color: {{ $brandColor }}; }
        .title p { margin: 2px 0 0 0; font-size: 11px; color: #555; }
        table.meta { width: 100%; margin-bottom: 24px; border-collapse: collapse; }
        table.meta td { padding: 3px 0; font-size: 11px; vertical-align: top; }
        table.meta td.label { color: #666; width: 120px; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        table.lines th { background: #f4f5f7; text-align: left; padding: 8px 10px; font-size: 10px; text-transform: uppercase; color: #555; border-bottom: 1px solid #ddd; }
        table.lines td { padding: 10px; font-size: 12px; border-bottom: 1px solid #eee; }
        table.lines td.amount, table.lines th.amount { text-align: right; }
        .total-row td { font-weight: bold; font-size: 13px; border-top: 2px solid #1c2230; }
        .status { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .status-unpaid { background: #fdecea; color: #c0392b; }
        .status-partial { background: #fff4e5; color: #b8720a; }
        .status-paid { background: #e8f5e9; color: #2e7d32; }
        .status-cancelled, .status-written_off { background: #f0f0f0; color: #666; }
        .notes { margin-top: 24px; font-size: 11px; color: #555; }
        .footer-note { margin-top: 30px; padding-top: 14px; border-top: 1px solid #eee; font-size: 10px; color: #555; white-space: pre-line; }
        .footer { margin-top: 30px; font-size: 10px; color: #888; text-align: center; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 60%;">
                @if($invoice->landlord?->logoDataUri())
                    <img class="logo" src="{{ $invoice->landlord->logoDataUri() }}" alt="{{ $invoice->landlord->name }}">
                @endif
                <div class="landlord">{{ $invoice->landlord->name ?? config('app.name') }}</div>
                @if($invoice->landlord?->address)
                    <div class="landlord-meta">{{ $invoice->landlord->address }}</div>
                @endif
                @if($invoice->landlord?->contact_phone || $invoice->landlord?->contact_email)
                    <div class="landlord-meta">{{ $invoice->landlord->contact_phone }} {{ $invoice->landlord?->contact_email ? '· ' . $invoice->landlord->contact_email : '' }}</div>
                @endif
            </td>
            <td style="width: 40%;" class="title">
                <h1>INVOICE</h1>
                <p>{{ $invoice->number }}</p>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="label">Billed To</td>
            <td>{{ $invoice->display_payer_name }}</td>
            <td class="label">Invoice Date</td>
            <td>{{ $invoice->invoice_date->format('d M Y') }}</td>
        </tr>
        <tr>
            <td class="label">Status</td>
            <td><span class="status status-{{ $invoice->status }}">{{ str_replace('_', ' ', $invoice->status) }}</span></td>
            <td class="label">Due Date</td>
            <td>{{ $invoice->due_date->format('d M Y') }}</td>
        </tr>
        @if($invoice->lease?->unit)
            <tr>
                <td class="label">Property</td>
                <td colspan="3">{{ $invoice->lease->unit->estate?->name }} — {{ $invoice->lease->unit->name }}</td>
            </tr>
        @endif
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $invoice->title }}</td>
                <td class="amount">UGX {{ number_format($invoice->amount, 2) }}</td>
            </tr>
            <tr class="total-row">
                <td>Total</td>
                <td class="amount">UGX {{ number_format($invoice->amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    @if($invoice->notes)
        <div class="notes"><strong>Notes:</strong> {{ $invoice->notes }}</div>
    @endif

    @if($invoice->landlord?->invoice_footer_note)
        <div class="footer-note">{{ $invoice->landlord->invoice_footer_note }}</div>
    @endif

    <div class="footer">Generated by {{ config('app.name') }} on {{ now()->format('d M Y') }}</div>
</body>
</html>

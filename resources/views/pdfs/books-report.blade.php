<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @php
        $reports = $reports ?? [$report];
        $first = $reports[0];
        $entity = $first->entity;
        $mark = 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" fill="none"><path d="M15 55 L60 15 L105 55" stroke="#1c2230" stroke-width="11" stroke-linecap="square" stroke-linejoin="miter"/><path d="M35 72 L60 50 L85 72" stroke="#c1704a" stroke-width="10" stroke-linecap="square" stroke-linejoin="miter"/><rect x="54" y="83" width="12" height="17" rx="1" fill="#1c2230"/></svg>');
        $n = fn ($v) => abs((float) $v) < 0.005 ? '–' : (($v < 0 ? '(' : '').number_format(abs((float) $v), 2).($v < 0 ? ')' : ''));
    @endphp
    <title>{{ $packTitle ?? $first->title.' · '.$first->period }}</title>
    <style>
        @page { margin: 26mm 16mm 20mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1f2937; }
        header { position: fixed; top: -18mm; left: 0; right: 0; height: 14mm; border-bottom: 1px solid #e5e7eb; }
        header table { width: 100%; border-collapse: collapse; }
        header img { width: 22px; height: 22px; vertical-align: middle; }
        .brand { font-weight: bold; font-size: 12pt; color: #1c2230; vertical-align: middle; padding-left: 6px; }
        .brand small { display: block; font-size: 6.5pt; letter-spacing: 2px; color: #c1704a; font-weight: bold; }
        .meta { text-align: right; font-size: 8pt; color: #6b7280; }
        footer { position: fixed; bottom: -13mm; left: 0; right: 0; height: 8mm; border-top: 1px solid #e5e7eb; font-size: 7.5pt; color: #9ca3af; }
        h1 { font-size: 17pt; color: #1c2230; margin: 0 0 2px 0; }
        .period { color: #6b7280; margin: 0 0 14px 0; }
        table.report { width: 100%; border-collapse: collapse; }
        table.report th { background: #1c2230; color: #fff; font-weight: bold; padding: 6px 8px; text-align: left; font-size: 8.5pt; }
        table.report th.num { text-align: right; }
        table.report td { padding: 4px 8px; border-bottom: 1px solid #f1f2f4; }
        td.num { text-align: right; white-space: nowrap; }
        td.code { color: #9ca3af; width: 40px; }
        tr.section td { background: #f3f4f6; font-weight: bold; color: #1c2230; padding-top: 7px; border-bottom: none; }
        tr.subtotal td { font-weight: bold; border-top: 1px solid #9ca3af; border-bottom: none; }
        tr.spacer td { border: none; padding: 3px; }
        tr.total td { font-weight: bold; background: #faf3ee; color: #1c2230; border-bottom: none; }
        tr.grand td { font-size: 10.5pt; border-top: 1px solid #1c2230; border-bottom: 3px double #1c2230; }
        .note { font-size: 7.5pt; color: #6b7280; margin: 3px 0; }
        .check { margin-top: 12px; font-weight: bold; }
        .ok { color: #15803d; } .bad { color: #dc2626; }
        .sub { display: block; font-size: 7pt; color: #9ca3af; font-weight: normal; }
    </style>
</head>
<body>
    <header>
        <table>
            <tr>
                @if($entity)
                    <td style="width: 60%"><span class="brand" style="padding-left: 0">{{ $entity }}<small style="letter-spacing: 0; font-weight: normal; color: #6b7280">{{ $first->entityLine }}</small></span></td>
                @else
                    <td style="width: 60%"><img src="{{ $mark }}" alt=""><span class="brand">{{ config('app.name') }}<small>PROPTECH</small></span></td>
                @endif
                <td class="meta">{{ $packTitle ?? $first->title }}<br>{{ $packTitle ? $first->entityLine : $first->period }}</td>
            </tr>
        </table>
    </header>
    <footer>
        <table style="width: 100%"><tr>
            <td>Generated {{ now()->format('d M Y, H:i') }} · {{ $entity ? 'prepared with '.config('app.name') : config('app.name').' books' }}</td>
        </tr></table>
    </footer>

    @foreach($reports as $report)
    @php $cols = count($report->columns); @endphp
    <div style="{{ $loop->last ? '' : 'page-break-after: always;' }}">
    <h1>{{ $report->title }}</h1>
    <p class="period">{{ $report->period }}</p>

    <table class="report">
        <thead>
            <tr>
                @if($report->showCodes)<th style="width: 40px">Code</th>@endif
                <th>Account</th>
                @foreach($report->columns as $heading)<th class="num" style="width: 22%">{{ $heading }}</th>@endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($report->sections as $section)
                @if($section['title'])
                    <tr class="section"><td colspan="{{ $cols + ($report->showCodes ? 2 : 1) }}">{{ $section['title'] }}</td></tr>
                @endif
                @foreach($section['rows'] as $line)
                    <tr>
                        @if($report->showCodes)<td class="code">{{ $line['code'] }}</td>@endif
                        <td>{{ $line['name'] }}@if(!empty($line['note']) && $report->slug !== 'trial-balance')<span class="sub">{{ $line['note'] }}</span>@endif</td>
                        @foreach($line['values'] as $v)<td class="num">{{ $n($v) }}</td>@endforeach
                    </tr>
                @endforeach
                @if($section['total_label'])
                    <tr class="subtotal">
                        @if($report->showCodes)<td></td>@endif
                        <td>{{ $section['total_label'] }}</td>
                        @foreach($report->columns as $i => $_)<td class="num">{{ $n($report->sectionTotal($section['key'], $i)) }}</td>@endforeach
                    </tr>
                @endif
                <tr class="spacer"><td colspan="{{ $cols + ($report->showCodes ? 2 : 1) }}"></td></tr>
            @endforeach
            @foreach($report->totals as $total)
                <tr class="total {{ $total['grand'] ? 'grand' : '' }}">
                    @if($report->showCodes)<td></td>@endif
                    <td>{{ $total['label'] }}</td>
                    @foreach($report->columns as $i => $_)<td class="num">{{ $n($report->totalValue($total, $i)) }}</td>@endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($report->check)
        <p class="check {{ $report->check[1] ? 'ok' : 'bad' }}">{{ $report->check[1] ? '✓' : '✗' }} {{ $report->check[0] }}</p>
    @endif
    <div style="margin-top: 14px">
        @foreach($report->notes as $note)
            <p class="note">{{ $note }}</p>
        @endforeach
    </div>
    </div>
    @endforeach
</body>
</html>

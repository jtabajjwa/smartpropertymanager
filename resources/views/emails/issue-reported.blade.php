<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $issue->title }}</title>
</head>
@php
    $brandColor = $issue->landlord?->brand_color ?: '#c1704a';
    $unit = $issue->unit ? $issue->unit->name.($issue->unit->estate ? ', '.$issue->unit->estate->name : '') : null;
@endphp
<body style="margin:0; padding:0; background-color:#f9fafb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; border:1px solid #e5e7eb; overflow:hidden;">
                    <tr>
                        <td style="padding:28px 32px 8px 32px;">
                            <p style="margin:0; font-size:12px; letter-spacing:0.05em; text-transform:uppercase; color:{{ $brandColor }}; font-weight:700;">{{ $issue->categoryLabel() }} · {{ $issue->number }}</p>
                            <p style="margin:6px 0 0 0; font-size:20px; font-weight:700; color:#1c2230;">{{ $issue->title }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px 32px 8px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; color:#374151;">
                                <tr><td style="padding:3px 0; color:#6b7280; width:90px;">Tenant</td><td style="padding:3px 0;">{{ $issue->tenant->name }} · {{ $issue->tenant->primary_phone }}</td></tr>
                                @if($unit)<tr><td style="padding:3px 0; color:#6b7280;">Unit</td><td style="padding:3px 0;">{{ $unit }}</td></tr>@endif
                                <tr><td style="padding:3px 0; color:#6b7280;">Reported</td><td style="padding:3px 0;">{{ $issue->created_at->format('d M Y, H:i') }}</td></tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px 32px 8px 32px;">
                            <div style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:16px 20px; font-size:14px; line-height:1.6; color:#111827; white-space:pre-wrap;">{{ $issue->description }}</div>
                            @if($issue->photo)
                                <p style="margin:10px 0 0 0; font-size:13px; color:#6b7280;">The tenant attached a photo (see attachment).</p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 28px 32px;">
                            <a href="{{ route('issues.show', $issue) }}" style="display:inline-block; background-color:{{ $brandColor }}; color:#ffffff; text-decoration:none; font-size:14px; font-weight:600; padding:10px 18px; border-radius:10px;">Open in {{ config('app.name') }}</a>
                            <p style="margin:12px 0 0 0; font-size:12px; color:#9ca3af;">Update the status there and the tenant will see it in their portal.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

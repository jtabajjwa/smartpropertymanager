<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f9fafb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; border:1px solid #e5e7eb; overflow:hidden;">
                    <tr>
                        <td style="padding:28px 32px 4px 32px;">
                            <p style="margin:0; font-size:12px; letter-spacing:0.2em; text-transform:uppercase; color:#c1704a; font-weight:700;">{{ config('app.name', 'Primora') }}</p>
                            <p style="margin:8px 0 0 0; font-size:20px; font-weight:700; color:#1c2230;">{{ $heading }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px 32px 8px 32px; font-size:14px; line-height:1.6; color:#374151;">
                            @foreach($lines as $line)
                                <p style="margin:0 0 10px 0;">{{ $line }}</p>
                            @endforeach
                        </td>
                    </tr>
                    @if($actionUrl)
                        <tr>
                            <td style="padding:8px 32px 28px 32px;">
                                <a href="{{ $actionUrl }}" style="display:inline-block; background-color:#c1704a; color:#ffffff; text-decoration:none; font-size:14px; font-weight:600; padding:10px 18px; border-radius:10px;">{{ $actionText ?? 'Open' }}</a>
                            </td>
                        </tr>
                    @endif
                </table>
                <p style="margin:16px 0 0 0; font-size:11px; color:#9ca3af;">© {{ now()->year }} {{ config('app.name', 'Primora') }}. All rights reserved.</p>
            </td>
        </tr>
    </table>
</body>
</html>

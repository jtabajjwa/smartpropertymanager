<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Password Reset Code</title>
</head>
<body style="margin:0; padding:0; background-color:#f9fafb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; border:1px solid #e5e7eb; overflow:hidden;">
                    <tr>
                        <td style="padding:32px 32px 8px 32px;">
                            <p style="margin:0; font-size:18px; font-weight:700; color:#1c2230;">{{ config('app.name', 'Primora') }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 8px 32px;">
                            <p style="margin:0 0 8px 0; font-size:16px; color:#111827;">Hi {{ $recipientName }},</p>
                            <p style="margin:0 0 24px 0; font-size:14px; color:#4b5563; line-height:1.5;">
                                Use the code below to reset your password. It expires in 10 minutes.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:0 32px 24px 32px;">
                            <div style="display:inline-block; background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:16px 32px;">
                                <span style="font-size:32px; font-weight:700; letter-spacing:0.3em; color:#c1704a;">{{ $code }}</span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 32px 32px;">
                            <p style="margin:0; font-size:13px; color:#9ca3af; line-height:1.5;">
                                If you didn't request a password reset, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

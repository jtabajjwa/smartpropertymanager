<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->number }}</title>
</head>
@php
    $brandColor = $invoice->landlord?->brand_color ?: '#c1704a';
@endphp
<body style="margin:0; padding:0; background-color:#f9fafb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; border:1px solid #e5e7eb; overflow:hidden;">
                    <tr>
                        <td style="padding:32px 32px 8px 32px;">
                            @if($invoice->landlord?->logoUrl())
                                <img src="{{ $invoice->landlord->logoUrl() }}" alt="{{ $invoice->landlord->name }}" style="max-height:40px; max-width:160px; display:block; margin-bottom:8px;">
                            @endif
                            <p style="margin:0; font-size:18px; font-weight:700; color:#1c2230;">{{ $invoice->landlord->name ?? config('app.name') }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 8px 32px;">
                            <p style="margin:0 0 8px 0; font-size:16px; color:#111827;">Hi {{ $payerName }},</p>
                            <p style="margin:0 0 16px 0; font-size:14px; color:#4b5563; line-height:1.5;">
                                A new invoice has been issued for {{ $invoice->title }}. The PDF is attached to this email for your records.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 24px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <p style="margin:0 0 6px 0; font-size:13px; color:#6b7280;">Invoice {{ $invoice->number }}</p>
                                        <p style="margin:0 0 6px 0; font-size:22px; font-weight:700; color:{{ $brandColor }};">UGX {{ number_format($invoice->amount, 2) }}</p>
                                        <p style="margin:0; font-size:13px; color:#6b7280;">Due {{ $invoice->due_date->format('d M Y') }}</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @if($invoice->landlord?->invoice_footer_note)
                        <tr>
                            <td style="padding:0 32px 16px 32px;">
                                <p style="margin:0; font-size:12px; color:#6b7280; line-height:1.5; white-space:pre-line;">{{ $invoice->landlord->invoice_footer_note }}</p>
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td style="padding:0 32px 32px 32px;">
                            <p style="margin:0; font-size:13px; color:#9ca3af; line-height:1.5;">
                                If you've already paid or have any questions, please contact us directly.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

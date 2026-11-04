<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @media only screen and (max-width: 600px) {
            td[style*="40px"] {
                padding-left: 20px !important;
                padding-right: 20px !important;
            }
            span[style*="font-size:28px"] {
                font-size: 22px !important;
                line-height: 30px !important;
            }
        }
    </style>
</head>

<body style="margin:0;padding:32px 16px;background-color:#edf2f7;">
    <center>
        <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%"
            style="border-collapse:collapse;width:100%;margin:0;padding:0;">
            <tbody>
                <tr>
                    <td align="center" valign="top" style="margin:0;padding:0;">
                        <table border="0" cellpadding="0" cellspacing="0" width="100%"
                            style="border-collapse:collapse;width:100%;max-width:640px;background-color:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 48px rgba(15, 23, 42, 0.12);">
                            <tbody>

                                {{-- LOGO HEADER --}}
                                <tr>
                                    <td align="center" valign="top"
                                        style="background:#245ee0;padding:44px 32px 36px;border-bottom:1px solid #1f478b;">
                                        <img align="center" alt="Filipino Homes"
                                            src="https://api2.filipinohomes.com/fh-logo-white.png"
                                            width="350"
                                            style="max-width:350px;width:100%;display:inline !important;border:0;height:auto;" />
                                    </td>
                                </tr>

                                {{-- RIBBON --}}
                                <tr>
                                    <td align="center" valign="middle"
                                        style="background:{{ $kind === 'seller' ? '#b45309' : '#0f766e' }};padding:14px 24px;">
                                        <span style="font-size:13px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;">
                                            {{ $kind === 'seller' ? '🏠 New seller brief' : '🔎 New buyer brief' }}
                                        </span>
                                        <br />
                                        <span style="font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:rgba(255,255,255,0.85);">
                                            Sent through your {{ $kind === 'seller' ? 'Sellers' : 'Buyers' }} Link{{ $receivedAt ? ' · '.$receivedAt : '' }}
                                        </span>
                                    </td>
                                </tr>

                                {{-- TITLE --}}
                                <tr>
                                    <td align="center" valign="top" style="background-color:#ffffff;padding:36px 32px 8px;">
                                        <span style="font-size:28px;line-height:36px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#162033;display:block;">
                                            {{ $clientName }}
                                        </span>
                                        @if ($gradeLabel)
                                        <span style="display:inline-block;margin-top:10px;padding:4px 12px;border-radius:999px;background:#eef3ff;color:#1d4ed8;font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;">
                                            {{ $gradeLabel }}
                                        </span>
                                        <span style="display:block;margin-top:6px;font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;">
                                            {{ $gradeWhy }}
                                        </span>
                                        @endif
                                    </td>
                                </tr>

                                {{-- GREETING --}}
                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:12px 40px 20px;">
                                        <span style="font-size:16px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Hello {{ $agentName }},
                                            <br /><br />
                                            @if ($kind === 'seller')
                                            {{ $clientFirst }} wants to sell a property through you and has sent the details below. Reply while it's fresh to talk price and next steps.
                                            @else
                                            {{ $clientFirst }} is looking for a property and has sent you a brief of what they want. Reply while it's fresh with options that fit.
                                            @endif
                                        </span>
                                    </td>
                                </tr>

                                {{-- SECTIONS --}}
                                @foreach ($sections as $section)
                                @if (!empty($section['rows']))
                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:0 40px 18px;">
                                        <div style="font-size:11px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;letter-spacing:0.06em;text-transform:uppercase;font-weight:700;margin-bottom:8px;">
                                            {{ $section['title'] }}
                                        </div>
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border:1px solid #d6dee8;border-radius:14px;border-collapse:separate;overflow:hidden;">
                                            <tbody>
                                                @foreach ($section['rows'] as $i => [$label, $value])
                                                <tr>
                                                    <td valign="top" width="150" style="width:150px;padding:10px 14px;background:#f8fafc;border-top:{{ $i === 0 ? 'none' : '1px solid #e6ebf2' }};font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;font-weight:700;">
                                                        {{ $label }}
                                                    </td>
                                                    <td valign="top" style="padding:10px 14px;border-top:{{ $i === 0 ? 'none' : '1px solid #e6ebf2' }};font-size:14px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#162033;white-space:pre-line;">{{ $value }}</td>
                                                </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                @endforeach

                                {{-- BUTTONS --}}
                                <tr>
                                    <td align="center" valign="top" style="background-color:#ffffff;padding:10px 40px 36px;">
                                        <a href="{{ $dashboardUrl }}" target="_blank"
                                            style="display:inline-block;margin:6px 4px;padding:14px 26px;border-radius:12px;background:#245ee0;color:#ffffff;font-size:14px;line-height:18px;font-family:Helvetica,Arial,sans-serif;font-weight:700;text-decoration:none;">
                                            Open in my dashboard
                                        </a>
                                        @if ($whatsappUrl)
                                        <a href="{{ $whatsappUrl }}" target="_blank"
                                            style="display:inline-block;margin:6px 4px;padding:14px 26px;border-radius:12px;background:#25d366;color:#ffffff;font-size:14px;line-height:18px;font-family:Helvetica,Arial,sans-serif;font-weight:700;text-decoration:none;">
                                            Reply on WhatsApp
                                        </a>
                                        @endif
                                        @if ($viberUrl)
                                        <a href="{{ $viberUrl }}"
                                            style="display:inline-block;margin:6px 4px;padding:14px 26px;border-radius:12px;background:#7360f2;color:#ffffff;font-size:14px;line-height:18px;font-family:Helvetica,Arial,sans-serif;font-weight:700;text-decoration:none;">
                                            Reply on Viber
                                        </a>
                                        @endif
                                    </td>
                                </tr>

                                {{-- FOOTER --}}
                                <tr>
                                    <td align="center" valign="top" style="background:#f8fafc;padding:22px 32px;border-top:1px solid #e6ebf2;">
                                        <span style="font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;display:block;">
                                            Replying to this email writes to {{ $clientName }} directly. This brief was sent through your Filipino Homes Buyers &amp; Sellers Link.
                                        </span>
                                    </td>
                                </tr>

                            </tbody>
                        </table>
                    </td>
                </tr>
            </tbody>
        </table>
    </center>
</body>

</html>

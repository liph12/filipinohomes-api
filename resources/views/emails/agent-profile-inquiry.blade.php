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

@php
    $avatarUrl = function (?string $url, ?string $name, string $bg = '245ee0') {
        if (!empty($url)) {
            return $url;
        }
        $seed = trim((string) $name) !== '' ? $name : 'FH';
        return 'https://ui-avatars.com/api/?name='
            . urlencode($seed)
            . '&background=' . $bg
            . '&color=fff&size=128&bold=true&rounded=true';
    };
@endphp

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

                                {{-- RIBBON — direct message to the agent, no moderation step. --}}
                                <tr>
                                    <td align="center" valign="middle"
                                        style="background:#0f766e;padding:14px 24px;">
                                        <span style="font-size:13px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;">
                                            💬 New Inquiry From Your Profile
                                        </span>
                                        <br />
                                        <span style="font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#d1fae5;">
                                            A visitor reached out through your public agent profile
                                        </span>
                                    </td>
                                </tr>

                                {{-- TITLE --}}
                                <tr>
                                    <td align="center" valign="top"
                                        style="background-color:#ffffff;padding:36px 32px 8px;">
                                        <span
                                            style="font-size:28px;line-height:36px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#162033;display:block;">
                                            You Have A New Inquiry
                                        </span>
                                    </td>
                                </tr>

                                {{-- GREETING --}}
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:12px 40px 24px;">
                                        <span
                                            style="font-size:16px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Hello {{ ucwords(strtolower($greetingName)) }},
                                            <br /><br />
                                            A visitor sent you a message from your Filipino Homes agent profile. Reply at your earliest convenience to keep the conversation moving.
                                        </span>
                                    </td>
                                </tr>

                                {{-- MESSAGE BODY --}}
                                @if (!empty($message))
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:0 40px 20px;">
                                        <div style="font-size:11px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;letter-spacing:0.06em;text-transform:uppercase;font-weight:700;margin-bottom:8px;">
                                            Message
                                        </div>
                                        <div style="padding:20px 24px;width:100%;background:linear-gradient(180deg,#f8fafc 0%,#e9eef5 100%);border:1px solid #d6dee8;border-radius:18px;box-sizing:border-box;">
                                            <span style="font-size:15px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#162033;display:block;white-space:pre-line;">{{ $message }}</span>
                                        </div>
                                    </td>
                                </tr>
                                @endif

                                {{-- FROM (the prospective client) --}}
                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:0 40px 16px;">
                                        <div style="font-size:11px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;letter-spacing:0.06em;text-transform:uppercase;font-weight:700;margin-bottom:10px;">
                                            From
                                        </div>
                                        <table border="0" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                            <tbody>
                                                <tr>
                                                    <td valign="top" width="64" style="width:64px;padding-right:14px;">
                                                        <img src="{{ $avatarUrl($senderAvatar, $senderName, '245ee0') }}"
                                                            alt="{{ $senderName }}"
                                                            width="56" height="56"
                                                            style="display:block;width:56px;height:56px;border-radius:50%;border:0;outline:none;text-decoration:none;background:#e5e7eb;" />
                                                    </td>
                                                    <td valign="top">
                                                        <div style="font-size:15px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#162033;font-weight:700;margin-bottom:4px;">
                                                            {{ ucwords(strtolower($senderName)) }}
                                                        </div>
                                                        <div style="font-size:13px;line-height:22px;font-family:Helvetica,Arial,sans-serif;color:#475467;">
                                                            <a href="mailto:{{ $senderEmail }}" style="color:#1d4ed8;text-decoration:none;">✉️ {{ $senderEmail }}</a>
                                                            @if (!empty($senderMobile))
                                                            <br /><a href="tel:{{ $senderMobile }}" style="color:#1d4ed8;text-decoration:none;">📞 {{ $senderMobile }}</a>
                                                            @endif
                                                            @if (!empty($senderWhatsapp))
                                                            <br /><a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $senderWhatsapp) }}" target="_blank" style="color:#1d4ed8;text-decoration:none;">💬 WhatsApp: {{ $senderWhatsapp }}</a>
                                                            @endif
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>

                                {{-- CTA --}}
                                <tr>
                                    <td align="center" style="padding:28px 40px 36px;background:#f8fafc;border-top:1px solid #e5e7eb;">
                                        <a href="{{ $frontendUrl }}/inbox/{{ $slug }}"
                                            style="display:inline-block;padding:14px 28px;font-size:15px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;background-color:#0f766e;border-radius:999px;text-decoration:none;font-weight:600;line-height:1;">
                                            Reply To Inquiry
                                        </a>
                                    </td>
                                </tr>

                                {{-- FOOTER --}}
                                <tr>
                                    <td align="center" style="padding:18px 40px 28px;background:#f8fafc;">
                                        <span style="font-size:11px;line-height:16px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;">
                                            You're receiving this because a visitor messaged you through your Filipino Homes agent profile.
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

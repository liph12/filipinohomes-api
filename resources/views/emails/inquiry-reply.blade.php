<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

                                {{-- RIBBON: clearly marks this as an outbound reply, not a
                                     marketing email — so the recipient knows a human at
                                     Filipino Homes responded to their inquiry. --}}
                                <tr>
                                    <td align="center" valign="middle"
                                        style="background:#0f766e;padding:14px 24px;">
                                        <span style="font-size:13px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;">
                                            💬 Reply from Filipino Homes
                                        </span>
                                        <br />
                                        <span style="font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#d1fae5;">
                                            In response to your recent inquiry
                                        </span>
                                    </td>
                                </tr>

                                {{-- GREETING --}}
                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:36px 40px 8px;">
                                        <span style="font-size:18px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#162033;font-weight:700;display:block;">
                                            Hi {{ ucwords(strtolower($inquiry->name)) }},
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:8px 40px 20px;">
                                        <span style="font-size:15px;line-height:24px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Thank you for reaching out to Filipino Homes. {{ ucwords(strtolower($adminName)) }} from our team has responded to your inquiry below.
                                        </span>
                                    </td>
                                </tr>

                                {{-- REPLY BODY --}}
                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:0 40px 20px;">
                                        <div style="padding:22px 24px;width:100%;background:linear-gradient(180deg,#f8fafc 0%,#e9eef5 100%);border:1px solid #d6dee8;border-radius:18px;box-sizing:border-box;">
                                            <span style="font-size:15px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#162033;display:block;white-space:pre-line;">{{ $reply->body }}</span>
                                        </div>
                                    </td>
                                </tr>

                                {{-- REPLIER (admin) with avatar --}}
                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:0 40px 20px;">
                                        <table border="0" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                            <tbody>
                                                <tr>
                                                    <td valign="top" width="64" style="width:64px;padding-right:14px;">
                                                        <img src="{{ $avatarUrl($adminAvatar, $adminName, '245ee0') }}"
                                                            alt="{{ $adminName }}"
                                                            width="56" height="56"
                                                            style="display:block;width:56px;height:56px;border-radius:50%;border:0;outline:none;text-decoration:none;background:#e5e7eb;" />
                                                    </td>
                                                    <td valign="top">
                                                        <div style="font-size:15px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#162033;font-weight:700;margin-bottom:4px;">
                                                            {{ ucwords(strtolower($adminName)) }}
                                                        </div>
                                                        <div style="font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;">
                                                            Filipino Homes Support Team
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>

                                {{-- ORIGINAL MESSAGE QUOTE — light gray so it doesn't compete
                                     with the reply but is there for context. --}}
                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:8px 40px 28px;">
                                        <div style="font-size:11px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;letter-spacing:0.06em;text-transform:uppercase;font-weight:700;margin-bottom:8px;">
                                            Your original message
                                        </div>
                                        <div style="padding:14px 18px;background:#f8fafc;border-left:3px solid #cbd5e1;border-radius:6px;">
                                            <span style="font-size:13px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#64748b;display:block;white-space:pre-line;">{{ $inquiry->message }}</span>
                                            <div style="font-size:11px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;margin-top:8px;">
                                                Sent {{ $inquiry->created_at?->format('M j, Y') ?? '' }}
                                            </div>
                                        </div>
                                    </td>
                                </tr>

                                {{-- FOOTER --}}
                                <tr>
                                    <td align="center" style="padding:22px 40px 32px;background:#f8fafc;border-top:1px solid #e5e7eb;">
                                        <span style="font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#475467;">
                                            Need to follow up? Just reply to this email — it goes straight to our team.
                                        </span>
                                        <br />
                                        <span style="font-size:11px;line-height:16px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;margin-top:6px;display:inline-block;">
                                            Filipino Homes · Buy · Sell · Rent · Foreclosure
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

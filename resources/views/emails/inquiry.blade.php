<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

@php
    // Map the frontend source key to a display config: human label, ribbon
    // background, contrast color, icon, and a one-line subtitle. Keep keys in
    // sync with the frontend POST payloads (GetInTouchSection.tsx →
    // 'home_get_in_touch'; maintenance page → 'maintenance_page').
    $sources = [
        'home_get_in_touch' => [
            'label'    => 'Get In Touch',
            'ribbon'   => '#1d4ed8',
            'subtitle' => 'Submitted from the homepage Get In Touch widget',
            'icon'     => '📨',
        ],
        'maintenance_page'  => [
            'label'    => 'Maintenance Request',
            'ribbon'   => '#b45309',
            'subtitle' => 'Submitted from the maintenance page',
            'icon'     => '🔧',
        ],
        'contact_page'      => [
            'label'    => 'Contact Us',
            'ribbon'   => '#1d4ed8',
            'subtitle' => 'Submitted from the Contact Us page',
            'icon'     => '📞',
        ],
    ];

    $cfg = $sources[$source ?? ''] ?? [
        'label'    => !empty($source) ? ucwords(str_replace('_', ' ', $source)) : 'Website Inquiry',
        'ribbon'   => '#1d4ed8',
        'subtitle' => 'Submitted through Filipino Homes',
        'icon'     => '📨',
    ];

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
                                            style="max-width:350px;width:100%;padding-bottom:0;display:inline !important;vertical-align:bottom;border:0;height:auto;outline:none;text-decoration:none;" />
                                    </td>
                                </tr>

                                {{-- SOURCE RIBBON — full-width colored band so admins instantly
                                     see which form this came from. --}}
                                <tr>
                                    <td align="center" valign="middle"
                                        style="background:{{ $cfg['ribbon'] }};padding:14px 24px;">
                                        <span style="font-size:13px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;">
                                            {{ $cfg['icon'] }} {{ $cfg['label'] }}
                                        </span>
                                        <br />
                                        <span style="font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#dbeafe;">
                                            {{ $cfg['subtitle'] }}
                                        </span>
                                    </td>
                                </tr>

                                {{-- TITLE --}}
                                <tr>
                                    <td align="center" valign="top"
                                        style="background-color:#ffffff;padding:36px 32px 8px;">
                                        <span style="font-size:28px;line-height:36px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#162033;display:block;">
                                            New Inquiry Received
                                        </span>
                                    </td>
                                </tr>

                                {{-- GREETING --}}
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:12px 40px 24px;">
                                        <span style="font-size:16px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Hello Administrator,
                                            <br /><br />
                                            A new <strong style="color:#162033;">{{ $cfg['label'] }}</strong> submission has come in through Filipino Homes. Please review and follow up with the client at your earliest convenience.
                                        </span>
                                    </td>
                                </tr>

                                {{-- MESSAGE BODY --}}
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:0 40px 20px;">
                                        <div style="font-size:11px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;letter-spacing:0.06em;text-transform:uppercase;font-weight:700;margin-bottom:8px;">
                                            Message
                                        </div>
                                        <div style="padding:20px 24px;width:100%;background:linear-gradient(180deg,#f8fafc 0%,#e9eef5 100%);border:1px solid #d6dee8;border-radius:18px;box-sizing:border-box;">
                                            <span style="font-size:15px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#162033;display:block;white-space:pre-line;">{{ $clientMessage }}</span>
                                        </div>
                                    </td>
                                </tr>

                                {{-- FROM (sender) with avatar --}}
                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:0 40px 16px;">
                                        <div style="font-size:11px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;letter-spacing:0.06em;text-transform:uppercase;font-weight:700;margin-bottom:10px;">
                                            From
                                        </div>
                                        <table border="0" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                            <tbody>
                                                <tr>
                                                    <td valign="top" width="64" style="width:64px;padding-right:14px;">
                                                        <img src="{{ $avatarUrl($clientAvatar ?? null, $clientName, ltrim($cfg['ribbon'], '#')) }}"
                                                            alt="{{ $clientName }}"
                                                            width="56" height="56"
                                                            style="display:block;width:56px;height:56px;border-radius:50%;border:0;outline:none;text-decoration:none;background:#e5e7eb;" />
                                                    </td>
                                                    <td valign="top">
                                                        <div style="font-size:15px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#162033;font-weight:700;margin-bottom:4px;">
                                                            {{ ucwords(strtolower($clientName)) }}
                                                        </div>
                                                        <div style="font-size:13px;line-height:22px;font-family:Helvetica,Arial,sans-serif;color:#475467;">
                                                            <a href="mailto:{{ $clientEmail }}" style="color:#1d4ed8;text-decoration:none;">✉️ {{ $clientEmail }}</a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>

                                {{-- FOOTER --}}
                                <tr>
                                    <td align="center" style="padding:24px 40px 32px;background:#f8fafc;border-top:1px solid #e5e7eb;">
                                        <span style="font-size:11px;line-height:16px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;">
                                            You're receiving this because you're an administrator at Filipino Homes.
                                            <br />Reply directly to this email to respond to the client.
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

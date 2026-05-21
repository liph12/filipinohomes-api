<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Mobile fit (viewports <=600px) — see message-notification.blade for
           the rationale; this block is identical across the four inquiry
           templates so the rendering stays consistent on phones. */
        @media only screen and (max-width: 600px) {
            td[style*="40px"] {
                padding-left: 20px !important;
                padding-right: 20px !important;
            }
            td[style*="width:160px;height:160px"] {
                display: block !important;
                width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                padding: 0 !important;
            }
            img[width="160"][height="160"] {
                display: block !important;
                width: 100% !important;
                height: auto !important;
                max-height: 240px !important;
            }
            td[style*="border-top-right-radius:14px"][style*="border-bottom-right-radius:14px"] {
                display: block !important;
                width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                padding: 18px 20px !important;
                border-top: none !important;
                border-top-right-radius: 0 !important;
                border-bottom-left-radius: 14px !important;
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
    $resolvedTeamName = !empty($teamName) ? $teamName : 'your team';
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

                                {{-- RIBBON — purple tone differentiates the team-leader cut
                                     from the amber admin variant so leaders can spot their
                                     own moderation queue at a glance. --}}
                                <tr>
                                    <td align="center" valign="middle"
                                        style="background:#6d28d9;padding:14px 24px;">
                                        <span style="font-size:13px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;">
                                            👥 Inquiry For Your Team — Pending Review
                                        </span>
                                        <br />
                                        <span style="font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#ede9fe;">
                                            One of your agents received a new inquiry
                                        </span>
                                    </td>
                                </tr>

                                {{-- TITLE --}}
                                <tr>
                                    <td align="center" valign="top"
                                        style="background-color:#ffffff;padding:36px 32px 8px;">
                                        <span
                                            style="font-size:28px;line-height:36px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#162033;display:block;">
                                            New Inquiry On Your Team's Listing
                                        </span>
                                    </td>
                                </tr>

                                {{-- GREETING — personalized with the team leader's name. Safe
                                     to do here because this email goes to exactly one BCC
                                     recipient (the leader); we never address it to multiple
                                     people via this blade. --}}
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:12px 40px 24px;">
                                        <span
                                            style="font-size:16px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Hello {{ ucwords(strtolower($receiverName)) }},
                                            <br /><br />
                                            As the team leader of <strong style="color:#162033;">{{ $resolvedTeamName }}</strong>, you're being notified because one of your agents just received a new property inquiry. Please review it alongside the admin team and accept or reject it from the inbox.
                                        </span>
                                    </td>
                                </tr>

                                {{-- TEAM CALLOUT --}}
                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:0 40px 18px;">
                                        <div style="padding:14px 18px;background:#ede9fe;border-left:3px solid #6d28d9;border-radius:6px;">
                                            <span style="font-size:11px;line-height:16px;font-family:Helvetica,Arial,sans-serif;color:#5b21b6;letter-spacing:0.06em;text-transform:uppercase;font-weight:700;display:block;margin-bottom:4px;">
                                                Your Team
                                            </span>
                                            <span style="font-size:15px;line-height:22px;font-family:Helvetica,Arial,sans-serif;color:#1f2937;font-weight:600;">
                                                {{ $resolvedTeamName }}
                                            </span>
                                        </div>
                                    </td>
                                </tr>

                                {{-- PROPERTY CARD --}}
                                @if (!empty($listing))
                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:0 40px 18px;">
                                        <div style="font-size:11px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;letter-spacing:0.06em;text-transform:uppercase;font-weight:700;margin-bottom:8px;">
                                            Inquiry is about
                                        </div>
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;min-height:160px;border-radius:14px;overflow:hidden;background:#ffffff;">
                                            <tbody>
                                                <tr style="height:160px;">
                                                    @if (!empty($listing['image']))
                                                    <td valign="middle" align="center" width="160" height="160"
                                                        style="width:160px;height:160px;min-height:160px;padding:0;background:#f1f5f9;">
                                                        <a href="{{ $listing['public_url'] ?? '#' }}" target="_blank" style="display:block;line-height:0;margin:0;padding:0;">
                                                            <img src="{{ $listing['image'] }}"
                                                                alt="{{ $listing['name'] ?? 'Listing' }}"
                                                                width="160" height="160"
                                                                style="display:block;width:160px;height:160px;object-fit:cover;object-position:center;border:0;outline:none;text-decoration:none;" />
                                                        </a>
                                                    </td>
                                                    @endif
                                                    <td valign="middle" style="padding:18px 20px;min-height:160px;border-color:#3a88e7;border-width:1px;border-style:solid;border-top-left-radius:0;border-bottom-left-radius:0;border-top-right-radius:14px;border-bottom-right-radius:14px;">
                                                        @if (!empty($listing['category']) || !empty($listing['subtype']))
                                                        <div style="font-size:11px;line-height:16px;font-family:Helvetica,Arial,sans-serif;color:#1d4ed8;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:6px;">
                                                            {{ trim(($listing['category'] ?? '') . (!empty($listing['category']) && !empty($listing['subtype']) ? ' · ' : '') . ($listing['subtype'] ?? '')) }}
                                                        </div>
                                                        @endif
                                                        @if (!empty($listing['name']))
                                                        <a href="{{ $listing['public_url'] ?? '#' }}" target="_blank" style="font-size:16px;line-height:22px;font-family:Helvetica,Arial,sans-serif;color:#162033;font-weight:700;text-decoration:none;display:block;margin-bottom:6px;">{{ $listing['name'] }}</a>
                                                        @endif
                                                        @if (!empty($listing['location']))
                                                        <div style="font-size:13px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;margin-bottom:6px;">📍 {{ $listing['location'] }}</div>
                                                        @endif
                                                        @if (!empty($listing['price']))
                                                        <div style="font-size:15px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#0f766e;font-weight:700;">{{ $listing['price'] }}</div>
                                                        @endif
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                                @endif

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

                                {{-- FROM (inquirer) --}}
                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:0 40px 16px;">
                                        <div style="font-size:11px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;letter-spacing:0.06em;text-transform:uppercase;font-weight:700;margin-bottom:10px;">
                                            From (inquirer)
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

                                {{-- LISTING OWNER — your team member. Helps the leader tell at
                                     a glance which agent on their team is on the hook. --}}
                                @if (!empty($listing) && (!empty($listing['owner_name']) || !empty($listing['owner_mobile']) || !empty($listing['owner_whatsapp']) || !empty($listing['owner_email'])))
                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:0 40px 16px;">
                                        <div style="font-size:11px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;letter-spacing:0.06em;text-transform:uppercase;font-weight:700;margin-bottom:10px;">
                                            Listing Owner (your team member)
                                        </div>
                                        <table border="0" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                            <tbody>
                                                <tr>
                                                    <td valign="top" width="64" style="width:64px;padding-right:14px;">
                                                        <img src="{{ $avatarUrl($listing['owner_avatar'] ?? null, $listing['owner_name'] ?? null, '6d28d9') }}"
                                                            alt="{{ $listing['owner_name'] ?? 'Listing Owner' }}"
                                                            width="56" height="56"
                                                            style="display:block;width:56px;height:56px;border-radius:50%;border:0;outline:none;text-decoration:none;background:#e5e7eb;" />
                                                    </td>
                                                    <td valign="top">
                                                        @if (!empty($listing['owner_name']))
                                                        <div style="font-size:15px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#162033;font-weight:700;margin-bottom:4px;">
                                                            {{ ucwords(strtolower($listing['owner_name'])) }}
                                                        </div>
                                                        @endif
                                                        <div style="font-size:13px;line-height:22px;font-family:Helvetica,Arial,sans-serif;color:#475467;">
                                                            @if (!empty($listing['owner_email']))
                                                            <a href="mailto:{{ $listing['owner_email'] }}" style="color:#1d4ed8;text-decoration:none;">✉️ {{ $listing['owner_email'] }}</a>
                                                            @endif
                                                            @if (!empty($listing['owner_mobile']))
                                                            <br /><a href="tel:{{ $listing['owner_mobile'] }}" style="color:#1d4ed8;text-decoration:none;">📞 {{ $listing['owner_mobile'] }}</a>
                                                            @endif
                                                            @if (!empty($listing['owner_whatsapp']))
                                                            <br /><a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $listing['owner_whatsapp']) }}" target="_blank" style="color:#1d4ed8;text-decoration:none;">💬 WhatsApp: {{ $listing['owner_whatsapp'] }}</a>
                                                            @endif
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                                @endif

                                {{-- CTA BUTTONS --}}
                                <tr>
                                    <td align="center" style="padding:28px 40px 36px;background:#f8fafc;border-top:1px solid #e5e7eb;">
                                        <table border="0" cellpadding="0" cellspacing="0" align="center" style="border-collapse:collapse;margin:0 auto;">
                                            <tbody>
                                                <tr>
                                                    @if (!empty($listing['public_url']))
                                                    <td align="center" valign="middle" style="padding:0 6px;">
                                                        <a href="{{ $listing['public_url'] }}" target="_blank"
                                                            style="display:inline-block;padding:14px 28px;font-size:15px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;background-color:#245ee0;border-radius:999px;text-decoration:none;font-weight:600;line-height:1;">
                                                            View Listing
                                                        </a>
                                                    </td>
                                                    @endif
                                                    <td align="center" valign="middle" style="padding:0 6px;">
                                                        <a href="{{ $frontendUrl }}/inbox/{{ $slug }}"
                                                            style="display:inline-block;padding:14px 28px;font-size:15px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;background-color:#6d28d9;border-radius:999px;text-decoration:none;font-weight:600;line-height:1;">
                                                            Review Inquiry
                                                        </a>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>

                                {{-- FOOTER --}}
                                <tr>
                                    <td align="center" style="padding:18px 40px 28px;background:#f8fafc;">
                                        <span style="font-size:11px;line-height:16px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;">
                                            You're receiving this because you're the team leader of {{ $resolvedTeamName }} at Filipino Homes.
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

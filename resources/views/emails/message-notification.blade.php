<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

                                <tr>
                                    <td align="center" valign="top"
                                        style="background:#245ee0;padding:44px 32px 36px;border-bottom:1px solid #1f478b;">
                                        <img align="center" alt="Filipino Homes"
                                            src="https://api2.filipinohomes.com/fh-logo-white.png"
                                            width="350"
                                            style="max-width:350px;width:100%;display:inline !important;border:0;height:auto;" />
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" valign="top"
                                        style="background-color:#ffffff;padding:40px 32px 8px;">
                                        <span
                                            style="font-size:30px;line-height:38px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#162033;display:block;">
                                            New Message Received
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:12px 40px 24px;">
                                        <span
                                            style="font-size:16px;line-height:28px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Hello {{ ucwords(strtolower($greetingName)) }},
                                            <br /><br />
                                            A new message has been submitted through Filipino Homes. Please review below and respond at your earliest convenience.
                                        </span>
                                    </td>
                                </tr>

                                @if (!empty($listing))
                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:0 40px 18px;">
                                        <div style="font-size:11px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#667085;letter-spacing:0.06em;text-transform:uppercase;font-weight:700;margin-bottom:8px;">
                                            Inquiry is about
                                        </div>
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;width:100%;min-height:160px;border:1px solid #a3c3ea;border-radius:14px;overflow:hidden;background:#ffffff;">
                                            <tbody>
                                                <tr style="height:160px;">
                                                    @if (!empty($listing['image']))
                                                    {{-- Fixed-width image cell. valign=middle keeps the photo
                                                         centered when the right column wraps taller than 160px.
                                                         `height` attribute is what gives the row its min-height
                                                         in Outlook (CSS min-height is stripped there). --}}
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
                                                    <td valign="middle" style="padding:18px 20px;min-height:160px;">
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

                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:0 40px 16px;">
                                        <div style="padding:20px 24px;width:100%;background:linear-gradient(180deg,#f8fafc 0%,#e9eef5 100%);border:1px solid #d6dee8;border-radius:18px;box-sizing:border-box;">
                                            <span style="font-size:15px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#162033;display:block;white-space:pre-line;">{{ $message }}</span>
                                </div>
                    </td>
                </tr>

                <tr>
                    <td align="left" valign="top"
                        style="background-color:#ffffff;padding:8px 40px 16px;">
                        <span style="font-size:13px;line-height:22px;font-family:Helvetica,Arial,sans-serif;color:#667085;display:block;">
                            From:<br />
                            <strong style="color:#162033;">{{ ucwords(strtolower($senderName)) }}</strong><br />
                            <a href="mailto:{{ $senderEmail }}" style="color:#1d4ed8;text-decoration:none;">✉️ {{ $senderEmail }}</a>
                            @if (!empty($senderMobile))
                            <br /><a href="tel:{{ $senderMobile }}" style="color:#1d4ed8;text-decoration:none;">📞 {{ $senderMobile }}</a>
                            @endif
                            @if (!empty($senderWhatsapp))
                            <br /><a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $senderWhatsapp) }}" target="_blank" style="color:#1d4ed8;text-decoration:none;">💬 WhatsApp: {{ $senderWhatsapp }}</a>
                            @endif
                        </span>
                    </td>
                </tr>

                @if (!empty($listing) && (!empty($listing['owner_name']) || !empty($listing['owner_mobile']) || !empty($listing['owner_whatsapp'])))
                {{-- Owning-agent contact card. Mirrors the "From:" block so BCC'd
                     admins / team-leaders can reach the responsible agent without
                     digging through the dashboard. Only rendered when at least
                     one owner field is present. --}}
                <tr>
                    <td align="left" valign="top"
                        style="background-color:#ffffff;padding:0 40px 16px;">
                        <span style="font-size:13px;line-height:22px;font-family:Helvetica,Arial,sans-serif;color:#667085;display:block;">
                            Listing Owner:<br />
                            @if (!empty($listing['owner_name']))

                            <strong style="color:#162033;">{{ ucwords(strtolower($listing['owner_name'])) }}</strong>
                            @endif
                            @if (!empty($listing['owner_mobile']))
                            <br /><a href="tel:{{ $listing['owner_mobile'] }}" style="color:#1d4ed8;text-decoration:none;">📞 {{ $listing['owner_mobile'] }}</a>
                            @endif
                            @if (!empty($listing['owner_whatsapp']))
                            <br /><a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $listing['owner_whatsapp']) }}" target="_blank" style="color:#1d4ed8;text-decoration:none;">💬 WhatsApp: {{ $listing['owner_whatsapp'] }}</a>
                            @endif
                        </span>
                    </td>
                </tr>
                @endif

                <!-- BUTTON SECTION -->
                <tr>
                    <td align="center" style="padding:24px 40px 40px;">
                        <table border="0" cellpadding="0" cellspacing="0" align="center" style="border-collapse:collapse;margin:0 auto;">
                            <tbody>
                                <tr>
                                    @if (!empty($listing['public_url']))
                                    <td align="center" valign="middle" style="padding:0 6px;">
                                        <a href="{{ $listing['public_url'] }}" target="_blank"
                                            style="display:inline-block;padding:14px 28px;font-size:16px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;background-color:#245ee0;border-radius:999px;text-decoration:none;font-weight:600;line-height:1;">
                                            View Listing
                                        </a>
                                    </td>
                                    @endif
                                    <td align="center" valign="middle" style="padding:0 6px;">
                                        {{-- Role-aware inbox link. Matches the actual frontend route:
                                             {frontendUrl}/{role}/main-dashboard/{slug}
                                             $roleName resolves to admin/agent/client at send-time and
                                             $frontendUrl is driven by FRONTEND_URL env so the host adapts
                                             to local/staging/prod without code changes. --}}
                                        <a href="{{ $frontendUrl }}/{{ $roleName }}/main-dashboard/{{ $slug }}"
                                            style="display:inline-block;padding:14px 28px;font-size:16px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;background-color:#245ee0;border-radius:999px;text-decoration:none;font-weight:600;line-height:1;">
                                            View Message
                                        </a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
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
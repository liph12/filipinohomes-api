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
                                            Hello {{ ucwords(strtolower($receiverName)) }},
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
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;width:100%;border:1px solid #d6dee8;border-radius:14px;overflow:hidden;background:#ffffff;">
                                            <tbody>
                                                <tr>
                                                    @if (!empty($listing['image']))
                                                    <td valign="top" width="160" height="100%" ; style="width:160px;padding:0;background:#f1f5f9;">
                                                        <a href="{{ $listing['public_url'] ?? '#' }}" target="_blank" style="display:block; line-height:0; margin:0; padding:0;">
                                                            <img src="{{ $listing['image'] }}"
                                                                alt="{{ $listing['name'] ?? 'Listing' }}"
                                                                width="160"
                                                                style="display:block; width:160px;Height:160px;  object-fit:cover; border:0; border-bottom-left-radius: 12px; border-top-left-radius: 12px;" />
                                                        </a>
                                                    </td>
                                                    @endif
                                                    <td valign="top" style="padding:14px 16px;">
                                                        @if (!empty($listing['category']) || !empty($listing['subtype']))
                                                        <div style="font-size:11px;line-height:16px;font-family:Helvetica,Arial,sans-serif;color:#1d4ed8;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">
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
                            <strong style="color:#162033;">{{ $senderName }}</strong><br />
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
                                        {{-- Role-aware redirect handled by the frontend at /inbox/[slug].
                                             $frontendUrl is driven by FRONTEND_URL env so the host adapts
                                             to local/staging/prod, and the redirect page resolves each
                                             viewer to /{role}/listing-inquiries/{slug} per their auth —
                                             so BCC admins/team-leaders land on the right path too. --}}
                                        <a href="{{ $frontendUrl }}/inbox/{{ $slug }}"
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
@php
    $key = strtolower($atsStatus);
    // Per-status accent + short explainer. Approved is the only "all good"
    // state; the rest need the agent's attention.
    $theme = match ($key) {
        'approved' => ['bg' => '#f0fdf4', 'border' => '#bbf7d0', 'color' => '#15803d', 'icon' => 'circle-check', 'glyph' => '✓',
                       'blurb' => 'Your Authority to Sell has been approved. Your listing is cleared on the ATS side.'],
        'pending'  => ['bg' => '#fffbeb', 'border' => '#fde68a', 'color' => '#b45309', 'icon' => 'clock', 'glyph' => '◔',
                       'blurb' => 'Your Authority to Sell is now pending review. No action is needed yet — we will update you once it is reviewed.'],
        'expired'  => ['bg' => '#fef2f2', 'border' => '#fecaca', 'color' => '#b91c1c', 'icon' => 'triangle-alert', 'glyph' => '!',
                       'blurb' => 'Your Authority to Sell has expired. Please re-upload a valid ATS document so your listing stays compliant.'],
        'rejected' => ['bg' => '#fef2f2', 'border' => '#fecaca', 'color' => '#b91c1c', 'icon' => 'circle-x', 'glyph' => '✕',
                       'blurb' => 'Your Authority to Sell was rejected. Please review the remarks below and re-submit a valid ATS document.'],
        default    => ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'color' => '#1d4ed8', 'icon' => 'info', 'glyph' => 'i',
                       'blurb' => 'The Authority to Sell status on your listing has been updated.'],
    };
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @media only screen and (max-width: 600px) {
            td[style*=" 40px"] { padding-left: 20px !important; padding-right: 20px !important; }
            /* Shorter photo banner on small screens. */
            img.ats-photo { height: 220px !important; }
            /* Stack the Expiration | Status columns vertically. */
            td.ats-col { display: block !important; width: 100% !important; box-sizing: border-box !important; }
            td.ats-vdiv { display: none !important; }
            td.ats-col2 { border-top: 1px solid #eef2f7 !important; }
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

                                <tr>
                                    <td align="center" valign="top" style="background:#ffffff;padding:22px 40px 0;">
                                        <img align="center" alt="Filipino Homes" src="https://filipinohomes.com/images/fh-logo-black.png"
                                            width="300" style="max-width:300px;width:100%;display:inline !important;border:0;height:auto;" />
                                        <div style="height:1px;line-height:1px;font-size:0;background:#e9eef5;margin:20px 0 0;">&nbsp;</div>
                                    </td>
                                </tr>

                                {{-- Status banner (color-coded per ATS status) --}}
                                <tr>
                                    <td align="center" valign="top" style="background-color:#ffffff;padding:24px 40px 0;">
                                        <span style="display:inline-block;font-size:22px;line-height:28px;font-family:Helvetica,Arial,sans-serif;font-weight:800;color:{{ $theme['color'] }};">ATS {{ $atsStatus }}</span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:26px 40px 16px;">
                                        <div style="font-size:19px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#0f172a;font-weight:700;margin:0 0 6px;">
                                            Hello {{ ucwords(strtolower($agentName)) }},
                                        </div>
                                        <span style="font-size:16px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            {{ $theme['blurb'] }}
                                        </span>
                                    </td>
                                </tr>

                                {{-- Property card — featured photo on top, details below --}}
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:12px 40px 14px;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%"
                                            style="width:100%;table-layout:fixed;border:1px solid #e6ebf1;border-radius:18px;overflow:hidden;background:#ffffff;">
                                            <tbody>
                                                @if(!empty($featuredPhoto))
                                                <tr>
                                                    <td valign="top" align="center"
                                                        style="padding:0;background:#eef2f7;line-height:0;">
                                                        <a href="{{ $listingUrl }}" target="_blank" style="display:block;line-height:0;">
                                                            <img class="ats-photo" src="{{ $featuredPhoto }}" alt="{{ $listingTitle }}" width="600"
                                                                style="display:block;width:100%;max-width:100%;height:300px;object-fit:cover;object-position:center;border:0;outline:none;text-decoration:none;" />
                                                        </a>
                                                    </td>
                                                </tr>
                                                @endif
                                                <tr>
                                                    <td valign="top" style="padding:18px 22px 16px;">
                                                        <a href="{{ $listingUrl }}" target="_blank"
                                                            style="font-size:17px;line-height:23px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#162033;text-decoration:none;display:block;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $listingTitle }}</a>
                                                        {{-- Listing code as a pill under the title — reliable everywhere.
                                                             (An on-photo overlay gets stripped by Gmail/Outlook.) --}}
                                                        <span style="display:inline-block;margin-top:12px;font-size:14px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#475467;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:999px;padding:9px 18px;letter-spacing:0.03em;">{{ $listingCode }}</span>
                                                    </td>
                                                </tr>
                                                {{-- Expiration | Status (inside the property card) --}}
                                                <tr>
                                                    <td style="padding:0;">
                                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;">
                                                            <tbody>
                                                                <tr>
                                                                    <td class="ats-col" valign="middle" width="49%" style="width:49%;padding:16px 20px;">
                                                                        <table border="0" cellpadding="0" cellspacing="0"><tbody><tr>
                                                                            <td valign="middle" width="52" style="padding-right:12px;">
                                                                                <table border="0" cellpadding="0" cellspacing="0"><tbody><tr>
                                                                                    <td width="40" height="40" align="center" valign="middle" style="width:40px;height:40px;border-radius:12px;background:#eff6ff;">
                                                                                        <span style="font-size:20px;line-height:1;color:#3b82f6;font-family:Helvetica,Arial,sans-serif;">◷</span>
                                                                                    </td>
                                                                                </tr></tbody></table>
                                                                            </td>
                                                                            <td valign="middle">
                                                                                <span style="font-size:11px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;display:block;">Expiration</span>
                                                                                <span style="font-size:15px;font-family:Helvetica,Arial,sans-serif;color:#162033;font-weight:700;display:block;margin-top:2px;">{{ $atsExpiration ?? 'Not set' }}</span>
                                                                            </td>
                                                                        </tr></tbody></table>
                                                                    </td>
                                                                    {{-- Short, centered vertical divider (hidden when the columns stack on mobile). --}}
                                                                    <td class="ats-vdiv" valign="middle" width="1" style="width:1px;padding:0;font-size:0;line-height:0;">
                                                                        <div style="width:1px;height:34px;background:#e2e8f0;font-size:0;line-height:0;">&nbsp;</div>
                                                                    </td>
                                                                    <td class="ats-col ats-col2" valign="middle" width="49%" style="width:49%;padding:16px 20px;">
                                                                        <table border="0" cellpadding="0" cellspacing="0"><tbody><tr>
                                                                            <td valign="middle" width="52" style="padding-right:12px;">
                                                                                <table border="0" cellpadding="0" cellspacing="0"><tbody><tr>
                                                                                    <td width="40" height="40" align="center" valign="middle" style="width:40px;height:40px;border-radius:12px;background:{{ $theme['bg'] }};">
                                                                                        <span style="font-size:20px;line-height:1;font-weight:700;color:{{ $theme['color'] }};font-family:Helvetica,Arial,sans-serif;">{{ $theme['glyph'] }}</span>
                                                                                    </td>
                                                                                </tr></tbody></table>
                                                                            </td>
                                                                            <td valign="middle">
                                                                                <span style="font-size:11px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;display:block;">Status</span>
                                                                                <span style="font-size:15px;font-family:Helvetica,Arial,sans-serif;color:{{ $theme['color'] }};font-weight:700;display:block;margin-top:2px;">{{ $atsStatus }}</span>
                                                                            </td>
                                                                        </tr></tbody></table>
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </td>
                                                </tr>
                                                {{-- Remarks (inside the property card, below) --}}
                                                @if($atsRemarks)
                                                <tr>
                                                    {{-- Side padding on the cell so the divider (on the inner wrapper) stays inset. --}}
                                                    <td valign="top" style="padding:0 20px;">
                                                        <div style="border-top:1px solid #eef2f7;padding:16px 0;">
                                                            <table border="0" cellpadding="0" cellspacing="0"><tbody><tr>
                                                                <td valign="top" width="52" style="padding-right:12px;">
                                                                    <table border="0" cellpadding="0" cellspacing="0"><tbody><tr>
                                                                        <td width="40" height="40" align="center" valign="middle" style="width:40px;height:40px;border-radius:12px;background:#f5f3ff;">
                                                                            <span style="font-size:20px;line-height:1;color:#8b5cf6;font-family:Helvetica,Arial,sans-serif;">✎</span>
                                                                        </td>
                                                                    </tr></tbody></table>
                                                                </td>
                                                                <td valign="top">
                                                                    <span style="font-size:11px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:3px;">Remarks</span>
                                                                    <span style="font-size:14px;line-height:22px;font-family:Helvetica,Arial,sans-serif;color:#334155;display:block;">{{ $atsRemarks }}</span>
                                                                </td>
                                                            </tr></tbody></table>
                                                        </div>
                                                    </td>
                                                </tr>
                                                @endif
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>

                                {{-- CTA --}}
                                <tr>
                                    <td align="center" valign="top" style="background-color:#ffffff;padding:16px 40px 40px;">
                                        <a href="{{ $listingUrl }}" target="_blank"
                                            style="display:inline-block;background:#245ee0;color:#ffffff;font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:700;text-decoration:none;padding:14px 32px;border-radius:12px;">
                                            View Listing
                                        </a>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" style="padding:14px 40px 18px;background:#ffffff;border-top:1px solid #eef2f6;">
                                        <span style="font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;">This is an automated message from Filipinohomes. Please do not reply to this email.</span>
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

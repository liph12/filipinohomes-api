@php
    // Two modes: "soon" (expiring in ~1 week) and "expired" (already lapsed).
    $theme = $mode === 'expired'
        ? ['bg' => '#fef2f2', 'border' => '#fecaca', 'color' => '#b91c1c', 'glyph' => '!',
           'banner' => 'ATS Expired',
           'blurb'  => 'Your Authority to Sell has expired. Please upload a valid ATS document to keep your listing active and visible.']
        : ['bg' => '#fffbeb', 'border' => '#fde68a', 'color' => '#b45309', 'glyph' => '◔',
           'banner' => 'ATS Expiring Soon',
           'blurb'  => 'Your Authority to Sell (ATS) is about to expire. Please renew or upload a valid ATS document before it expires to keep your listing valid'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @media only screen and (max-width: 600px) {
            td[style*=" 40px"] { padding-left: 20px !important; padding-right: 20px !important; }
            img.ats-photo { height: 220px !important; }
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

                                {{-- Banner (mode-colored) --}}
                                <tr>
                                    <td align="center" valign="top" style="background-color:#ffffff;padding:24px 40px 0;">
                                        <span style="display:inline-block;font-size:22px;line-height:28px;font-family:Helvetica,Arial,sans-serif;font-weight:800;color:{{ $theme['color'] }};">{{ $theme['banner'] }}</span>
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
                                                        <span style="display:inline-block;margin-top:12px;font-size:14px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#475467;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:999px;padding:9px 18px;letter-spacing:0.03em;">{{ $listingCode }}</span>
                                                    </td>
                                                </tr>
                                                {{-- Expiration highlight --}}
                                                <tr>
                                                    <td valign="top" style="padding:0 20px;">
                                                        <div style="border-top:1px solid #eef2f7;padding:16px 0;">
                                                            <table border="0" cellpadding="0" cellspacing="0"><tbody><tr>
                                                                <td valign="middle" width="52" style="padding-right:12px;">
                                                                    <table border="0" cellpadding="0" cellspacing="0"><tbody><tr>
                                                                        <td width="40" height="40" align="center" valign="middle" style="width:40px;height:40px;border-radius:12px;background:{{ $theme['bg'] }};">
                                                                            <span style="font-size:20px;line-height:1;color:{{ $theme['color'] }};font-family:Helvetica,Arial,sans-serif;">◷</span>
                                                                        </td>
                                                                    </tr></tbody></table>
                                                                </td>
                                                                <td valign="middle">
                                                                    <span style="font-size:11px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;display:block;">{{ $mode === 'expired' ? 'Expired On' : 'Expires On' }}</span>
                                                                    <span style="font-size:16px;font-family:Helvetica,Arial,sans-serif;color:{{ $theme['color'] }};font-weight:700;display:block;margin-top:2px;">{{ $atsExpiration ?? 'Not set' }}</span>
                                                                </td>
                                                            </tr></tbody></table>
                                                        </div>
                                                    </td>
                                                </tr>
                                                {{-- Remarks --}}
                                                @if($atsRemarks)
                                                <tr>
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
                                            Update ATS Document
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

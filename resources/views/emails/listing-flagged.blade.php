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

                                <!-- Red flag banner -->
                                <tr>
                                    <td align="center" valign="top"
                                        style="background-color:#fff5f5;padding:28px 32px 20px;border-bottom:1px solid #fed7d7;">
                                        <span style="font-size:28px;line-height:36px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#c53030;display:block;">
                                            ⚑ Listing Flagged for Update
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:32px 40px 16px;">
                                        <span style="font-size:16px;line-height:28px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Hello {{ ucwords(strtolower($agentName)) }},
                                            <br /><br />
                                            Our team has reviewed your listing and found that some information needs to be updated before it can be verified. Please log in and make the necessary corrections as soon as possible.
                                        </span>
                                    </td>
                                </tr>

                                <!-- Listing info box -->
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:8px 40px 16px;">
                                        <div style="padding:20px 24px;background:#f8fafc;border:1px solid #d6dee8;border-radius:16px;box-sizing:border-box;">
                                            <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#667085;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.05em;">Listing</span>
                                            <span style="font-size:17px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#162033;display:block;">{{ $listingTitle }}</span>
                                            <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#667085;display:block;margin-top:4px;">Code: {{ $listingCode }}</span>
                                        </div>
                                    </td>
                                </tr>

                                @php
                                    // Map checklist keys to human-readable labels (mirrors frontend CHECKLIST_ITEMS)
                                    $checklistLabels = [
                                        'details_correct'    => 'Details are good',
                                        'ats_correct'        => 'ATS is correct',
                                        'attributes_correct' => 'Attributes are good',
                                        'price_realistic'    => 'Price is realistic',
                                        'amenities'          => 'Amenities are good',
                                        'location_accurate'  => 'Location / address is correct',
                                        'nearby_facilities'  => 'Nearby facilities are present',
                                        'photos'             => 'Photos are good',
                                        'title_seo'          => 'Title is already good for SEO',
                                        'description'        => 'Description good for SEO',
                                        'agent_verified'     => 'Agent contact (email, mobile & whatsapp)',
                                    ];
                                    // Land listings have no amenities — drop the row so the
                                    // checklist matches what the audit panel actually showed.
                                    if (!empty($isLand)) {
                                        unset($checklistLabels['amenities']);
                                    }
                                    $passingItems = [];
                                    $failingItems = [];
                                    if (is_array($auditChecklist)) {
                                        foreach ($checklistLabels as $key => $label) {
                                            if (!empty($auditChecklist[$key])) {
                                                $passingItems[] = $label;
                                            } else {
                                                $failingItems[] = $label;
                                            }
                                        }
                                    }
                                @endphp
                                @if(!empty($failingItems) || !empty($passingItems))
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:8px 40px 16px;">
                                        <div style="padding:20px 24px;background:#f8fafc;border:1px solid #d6dee8;border-radius:16px;box-sizing:border-box;">
                                            <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#334155;display:block;font-weight:700;margin-bottom:12px;text-transform:uppercase;letter-spacing:0.05em;">Audit Checklist</span>
                                            @foreach($failingItems as $item)
                                            <div style="display:flex;align-items:flex-start;margin-bottom:8px;">
                                                <span style="color:#c53030;font-size:14px;margin-right:8px;line-height:20px;flex-shrink:0;">✗</span>
                                                <span style="font-size:14px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#7f1d1d;font-weight:600;">{{ $item }}</span>
                                            </div>
                                            @endforeach
                                            @foreach($passingItems as $item)
                                            <div style="display:flex;align-items:flex-start;margin-bottom:8px;">
                                                <span style="color:#16a34a;font-size:14px;margin-right:8px;line-height:20px;flex-shrink:0;">✓</span>
                                                <span style="font-size:14px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#166534;">{{ $item }}</span>
                                            </div>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                                @endif

                                @if($auditNotes)
                                <!-- Audit notes box -->
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:8px 40px 16px;">
                                        <div style="padding:20px 24px;background:linear-gradient(180deg,#fffbeb 0%,#fef3c7 100%);border:1px solid #fde68a;border-radius:16px;box-sizing:border-box;">
                                            <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#92400e;display:block;font-weight:700;margin-bottom:8px;">Notes from our team:</span>
                                            <span style="font-size:15px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#78350f;display:block;white-space:pre-line;">{{ $auditNotes }}</span>
                                        </div>
                                    </td>
                                </tr>
                                @endif

                                @if(!empty($editedFields))
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:8px 40px 16px;">
                                        <div style="padding:20px 24px;background:#f0f7ff;border:1px solid #bfdbfe;border-radius:16px;box-sizing:border-box;">
                                            <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#1e40af;display:block;font-weight:700;margin-bottom:12px;text-transform:uppercase;letter-spacing:0.05em;">Changes Made by Admin</span>
                                            @foreach($editedFields as $field)
                                            <div style="margin-bottom:16px;">
                                                <span style="font-size:12px;font-family:Helvetica,Arial,sans-serif;color:#3b82f6;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">{{ $field['label'] ?? '' }}</span>
                                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin-top:6px;">
                                                    <tr>
                                                        <td width="48%" valign="top" style="padding:8px 10px;background:#fff;border:1px solid #fca5a5;border-radius:8px;">
                                                            <span style="font-size:11px;color:#9ca3af;display:block;margin-bottom:3px;">Before</span>
                                                            <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#374151;word-break:break-word;">{{ $field['original'] ?? '—' }}</span>
                                                        </td>
                                                        <td width="4%" style="padding:0;"></td>
                                                        <td width="48%" valign="top" style="padding:8px 10px;background:#fff;border:1px solid #86efac;border-radius:8px;">
                                                            <span style="font-size:11px;color:#9ca3af;display:block;margin-bottom:3px;">After</span>
                                                            <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#374151;word-break:break-word;">{{ $field['current'] ?? '—' }}</span>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                                @endif

                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:8px 40px 16px;">
                                        <span style="font-size:15px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Once you've made the necessary updates, our team will re-review your listing. If you have any questions, feel free to contact us.
                                        </span>
                                    </td>
                                </tr>

                                <!-- CTA button -->
                                <tr>
                                    <td align="center" style="padding:24px 40px 40px;">
                                        <a href="{{ $listingUrl }}"
                                           style="display:inline-block;padding:14px 28px;font-size:16px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;background-color:#c53030;border-radius:999px;text-decoration:none;font-weight:600;">
                                            Update My Listing
                                        </a>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" style="padding:0 40px 32px;">
                                        <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;display:block;">
                                            This is an automated message from Filipino Homes. Please do not reply to this email.
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

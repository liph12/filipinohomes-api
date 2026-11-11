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
                                    <td align="center" valign="top" style="background:#ffffff;padding:22px 40px 0;">
                                        <img align="center" alt="Filipino Homes" src="https://filipinohomes.com/images/fh-logo-black.png"
                                            width="300" style="max-width:300px;width:100%;display:inline !important;border:0;height:auto;" />
                                        <div style="height:1px;line-height:1px;font-size:0;background:#e9eef5;margin:20px 0 0;">&nbsp;</div>
                                    </td>
                                </tr>

                                {{-- Green verified banner --}}
                                <tr>
                                    <td align="center" valign="top" style="background-color:#ffffff;padding:24px 40px 0;">
                                        <span style="display:inline-block;font-size:22px;line-height:28px;font-family:Helvetica,Arial,sans-serif;font-weight:800;color:#15803d;">Listing Verified</span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:26px 40px 16px;">
                                        <div style="font-size:19px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#0f172a;font-weight:700;margin:0 0 6px;">
                                            Hello {{ ucwords(strtolower($agentName)) }},
                                        </div>
                                        <span style="font-size:16px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Good news — your listing has passed our initial audit and is now marked as <strong style="color:#15803d;">Verified</strong>. Buyers will see a verified badge on your listing.
                                        </span>
                                    </td>
                                </tr>

                                {{-- Listing info box --}}
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
                                    // Mirror frontend CHECKLIST_ITEMS labels. Items missing from
                                    // $auditChecklist (or false) are what's still needed for
                                    // FULLY VERIFIED status.
                                    $checklistLabels = [
                                        'details_correct'    => 'Details & Attributes are good',
                                        'ats_correct'        => 'ATS is correct',
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
                                    $pendingItems = [];
                                    foreach ($checklistLabels as $key => $label) {
                                        if (is_array($auditChecklist) && !empty($auditChecklist[$key])) {
                                            $passingItems[] = $label;
                                        } else {
                                            $pendingItems[] = $label;
                                        }
                                    }
                                @endphp

                                {{-- Path to Fully Verified --}}
                                @if(!empty($pendingItems))
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:8px 40px 16px;">
                                        <div style="padding:20px 24px;background:#fffbeb;border:1px solid #fd998a;border-radius:16px;box-sizing:border-box;">
                                            <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#92400e;display:block;font-weight:700;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.05em;">Still Needed for Fully Verified</span>
                                            <span style="font-size:13px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#78350f;display:block;margin-bottom:12px;">Complete the items below to upgrade your listing to <strong>Fully Verified.</strong> </br>Higher trust = better visibility.</span>
                                            @foreach($pendingItems as $item)
                                            <div style="display:flex;align-items:flex-start;margin-bottom:8px;">
                                                <span style="color:#dc2626;font-size:14px;margin-right:8px;line-height:20px;flex-shrink:0;">✗</span>
                                                <span style="font-size:14px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#dc2626;font-weight:600;">{{ $item }}</span>
                                            </div>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                                @endif

                                {{-- What you already passed --}}
                                @if(!empty($passingItems))
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:8px 40px 16px;">
                                        <div style="padding:20px 24px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:16px;box-sizing:border-box;">
                                            <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#166534;display:block;font-weight:700;margin-bottom:12px;text-transform:uppercase;letter-spacing:0.05em;">Already Passed</span>
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
                                {{-- Auditor notes --}}
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:8px 40px 16px;">
                                        <div style="padding:20px 24px;background:linear-gradient(180deg,#eff6ff 0%,#dbeafe 100%);border:1px solid #bfdbfe;border-radius:16px;box-sizing:border-box;">
                                            <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#1e40af;display:block;font-weight:700;margin-bottom:8px;">Notes from our team:</span>
                                            <span style="font-size:15px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#1e3a8a;display:block;white-space:pre-line;">{{ $auditNotes }}</span>
                                        </div>
                                    </td>
                                </tr>
                                @endif

                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:8px 40px 16px;">
                                        <span style="font-size:15px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Address the pending items at your convenience and our team will re-review the listing for the Fully Verified badge.
                                        </span>
                                    </td>
                                </tr>

                                {{-- CTA button --}}
                                <tr>
                                    <td align="center" style="padding:24px 40px 40px;">
                                        <a href="{{ $listingUrl }}"
                                           style="display:inline-block;padding:14px 28px;font-size:16px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;background-color:#15803d;border-radius:999px;text-decoration:none;font-weight:600;">
                                            View My Listing
                                        </a>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" style="padding:14px 40px 18px;background:#ffffff;border-top:1px solid #eef2f6;">
                                        <span style="font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;">This is an automated message from Filipino Homes. Please do not reply to this email.</span>
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

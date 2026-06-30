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

                                {{-- Green verified banner --}}
                                <tr>
                                    <td align="center" valign="top"
                                        style="background-color:#f0fdf4;padding:28px 32px 20px;border-bottom:1px solid #bbf7d0;">
                                        <span style="font-size:28px;line-height:36px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#15803d;display:block;">
                                            ✓ Listing Verified
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:32px 40px 16px;">
                                        <span style="font-size:16px;line-height:28px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Hello {{ ucwords(strtolower($agentName)) }},
                                            <br /><br />
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

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
                                        <span style="font-size:16px;line-height:28px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;text-transform:capitalize;">
                                            Hello <span style="text-transform:none !important;">{{ $agentName }}</span>,
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

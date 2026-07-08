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

                                {{-- Gold congratulations banner --}}
                                <tr>
                                    <td align="center" valign="top"
                                        style="background-color:#fffbeb;padding:28px 32px 20px;border-bottom:1px solid #fde68a;">
                                        <span style="font-size:28px;line-height:36px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#b45309;display:block;">
                                            🏆 Top Agent Award
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:32px 40px 16px;">
                                        <span style="font-size:16px;line-height:28px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Hello {{ ucwords(strtolower($agentName)) }},
                                            <br /><br />
                                            Congratulations! In recognition of your outstanding contributions to property listings for the month of
                                            <strong style="color:#b45309;">{{ $awardMonth }} {{ $awardYear }}</strong>,
                                            you have earned a spot among our <strong>Top 10 Agents</strong>.
                                            <br /><br />
                                            Your official certificate of recognition is attached to this email. Feel free to download, print, or share it.
                                        </span>
                                    </td>
                                </tr>

                                {{-- Award info box --}}
                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:8px 40px 16px;">
                                        <div style="padding:20px 24px;background:#fffbeb;border:1px solid #fde68a;border-radius:16px;box-sizing:border-box;">
                                            <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#92400e;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.05em;">Award</span>
                                            <span style="font-size:17px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#162033;display:block;">Top 10 Agent — {{ $awardMonth }} {{ $awardYear }}</span>
                                            <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#667085;display:block;margin-top:4px;">Certificate attached: {{ $certificateFilename }}</span>
                                        </div>
                                    </td>
                                </tr>


                                <tr>
                                    <td align="left" valign="top"
                                        style="background-color:#ffffff;padding:8px 40px 36px;">
                                        <span style="font-size:15px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Thank you for your dedication and hard work. Keep up the excellent momentum!
                                            <br /><br />
                                            — The Filipino Homes Team
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" valign="top"
                                        style="background-color:#f8fafc;padding:24px 32px;border-top:1px solid #e2e8f0;">
                                        <span style="font-size:12px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;display:block;">
                                            This is an automated message from Filipinohomes. Please do not reply directly to this email.
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

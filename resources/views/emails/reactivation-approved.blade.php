<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @media only screen and (max-width: 600px) {
            td[style*=" 40px"] { padding-left: 20px !important; padding-right: 20px !important; }
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

                                <tr>
                                    <td align="center" valign="top" style="background-color:#ffffff;padding:24px 40px 0;">
                                        <span style="display:inline-block;font-size:22px;line-height:28px;font-family:Helvetica,Arial,sans-serif;font-weight:800;color:#15803d;">Account Reactivated</span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="left" valign="top" style="background-color:#ffffff;padding:26px 40px 16px;">
                                        <div style="font-size:19px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#0f172a;font-weight:700;margin:0 0 6px;">
                                            Hello {{ ucwords(strtolower($agentName)) }},
                                        </div>
                                        <span style="font-size:16px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Your reactivation request was accepted. You can now access your Filipino Homes account again, and your listings are back on the site.
                                        </span>
                                        <span style="font-size:16px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;margin-top:12px;">
                                            Accounts that stay logged out for 45 days are deactivated, so please log in regularly to keep yours active.
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" valign="top" style="background-color:#ffffff;padding:16px 40px 40px;">
                                        <a href="{{ $loginUrl }}" target="_blank"
                                            style="display:inline-block;background:#245ee0;color:#ffffff;font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:700;text-decoration:none;padding:14px 32px;border-radius:12px;">
                                            Log In to Filipino Homes
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

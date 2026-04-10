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
                                            Hello {{ $receiverName }},
                                            <br /><br />
                                            A new message has been submitted through Filipino Homes. Please review below and follow up with the client at your earliest convenience.
                                        </span>
                                    </td>
                                </tr>

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
                                            From:<br/>
                                            <strong style="color:#162033;">{{ $senderName }}</strong><br/>
                                            <a href="mailto:{{ $senderEmail }}" style="color:#1d4ed8;text-decoration:none;">{{ $senderEmail }}</a>
                                        </span>
                                    </td>
                                </tr>

                                <!-- BUTTON SECTION -->
                                <tr>
                                    <td align="center" style="padding:24px 40px 40px;">
                                        <a href="https://filipinohomes.com/agent/listing-inquiries/{{ $slug }}"
                                           style="display:inline-block;padding:14px 28px;font-size:16px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;background-color:#245ee0;border-radius:999px;text-decoration:none;font-weight:600;">
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
    </center>
</body>
</html>
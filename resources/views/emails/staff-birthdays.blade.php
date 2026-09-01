<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:32px 12px;background-color:#edf2f7;">
    @php
        $sectionTitle = 'font-size:13px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:0.08em;display:block;margin-bottom:12px;';
        $listCard = 'padding:14px 20px;background:#f8fafc;border:1px solid #d6dee8;';
        $rowText = 'font-size:14px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#344054;display:block;';
        $muted = 'font-size:12px;font-family:Helvetica,Arial,sans-serif;color:#98a2b3;display:block;margin-top:8px;';
        $sectionPad = 'padding:24px 32px 0;';
    @endphp
    <center>
        <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;width:100%;margin:0;padding:0;">
            <tbody>
                <tr>
                    <td align="center" valign="top" style="margin:0;padding:0;">
                        <table border="0" cellpadding="0" cellspacing="0" width="100%"
                            style="border-collapse:collapse;width:100%;max-width:640px;background-color:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 48px rgba(15, 23, 42, 0.12);">
                            <tbody>

                                <tr>
                                    <td align="center" valign="top" style="background:#245ee0;padding:40px 24px 32px;border-bottom:1px solid #1f478b;">
                                        <img align="center" alt="Filipino Homes" src="https://api2.filipinohomes.com/fh-logo-white.png" width="320"
                                            style="max-width:320px;width:100%;display:inline !important;border:0;height:auto;" />
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" valign="top" style="background-color:#fffbeb;padding:24px 24px 20px;border-bottom:1px solid #fde68a;">
                                        <span style="font-size:24px;line-height:32px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#92400e;display:block;">
                                            🎂 Birthdays
                                        </span>
                                        <span style="font-size:14px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;margin-top:6px;">
                                            {{ $dateLabel }}
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="left" valign="top" style="padding:24px 32px 0;">
                                        <span style="font-size:15px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Hello {{ $recipientName }}, here are the birthdays to celebrate.
                                        </span>
                                    </td>
                                </tr>

                                @if (count($birthdays['today']) > 0)
                                    <tr>
                                        <td align="left" valign="top" style="{{ $sectionPad }}">
                                            <span style="{{ $sectionTitle }}">🎂 Today&rsquo;s Birthdays</span>
                                            <div style="{{ $listCard }} background:#fffbeb;border-color:#fde68a;">
                                                @foreach ($birthdays['today'] as $b)
                                                    <span style="{{ $rowText }}">
                                                        🎉 <strong style="color:#92400e;">{{ $b['name'] }}</strong>
                                                    </span>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endif

                                <tr>
                                    <td align="left" valign="top" style="{{ $sectionPad }}">
                                        <span style="{{ $sectionTitle }}">Upcoming Birthdays — Next 30 Days</span>
                                        <div style="{{ $listCard }}">
                                            @forelse ($birthdays['upcoming'] as $b)
                                                <span style="{{ $rowText }}">
                                                    🎈 <strong style="color:#162033;">{{ $b['name'] }}</strong>
                                                    <span style="color:#98a2b3;">— {{ $b['date'] }}</span>
                                                </span>
                                            @empty
                                                <span style="font-size:14px;font-family:Helvetica,Arial,sans-serif;color:#667085;">No birthdays in the next 30 days.</span>
                                            @endforelse
                                            @if ($birthdays['upcoming_total'] > count($birthdays['upcoming']))
                                                <span style="{{ $muted }} margin-top:6px;">
                                                    + {{ $birthdays['upcoming_total'] - count($birthdays['upcoming']) }} more within 30 days
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" valign="top" style="padding:28px 32px 32px;">
                                        <span style="font-size:12px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#98a2b3;display:block;border-top:1px solid #e7edf3;padding-top:20px;">
                                            Generated automatically by filipinohomes.com · Staff only (client accounts excluded).
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

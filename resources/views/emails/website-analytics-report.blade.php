<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:32px 12px;background-color:#edf2f7;">
    @php
        // Same email-safe idioms as admin-activity-report.blade.php: tables,
        // border-collapse, explicit spacer cells — no flex/margins.
        $fmt = fn ($n) => number_format((int) ($n ?? 0));
        $pct = function ($v) {
            if ($v === null) return '';
            $up = $v >= 0;
            $color = $up ? '#059669' : '#dc2626';
            $arrow = $up ? '&#9650;' : '&#9660;';
            return "<span style=\"font-size:12px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:{$color};\">{$arrow} ".($up ? '+' : '').$v.'%</span>';
        };
        $sectionTitle = 'font-size:13px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:0.08em;display:block;margin-bottom:12px;';
        $card = 'padding:16px 8px;background:#f8fafc;border:1px solid #d6dee8;border-radius:16px;';
        $statNum = 'font-size:24px;line-height:30px;font-family:Helvetica,Arial,sans-serif;font-weight:800;color:#162033;display:block;';
        $statLbl = 'font-size:12px;font-family:Helvetica,Arial,sans-serif;color:#667085;display:block;margin-top:4px;';
        $rowText = 'font-size:14px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#344054;';
        $muted = 'font-size:12px;font-family:Helvetica,Arial,sans-serif;color:#98a2b3;display:block;margin-top:8px;';
        $sectionPad = 'padding:24px 32px 0;';
        $gap = '<td width="12" style="font-size:0;line-height:0;">&nbsp;</td>';

        $daily = $report['daily'] ?? [];
        $totals = $daily['totals'] ?? [];
        $delta = $daily['change_vs_previous'] ?? [];
        $mtdTotals = $report['mtd']['totals'] ?? [];
        $sources = array_slice($daily['sources'] ?? [], 0, 6);
        $pages = array_slice($daily['top_pages'] ?? [], 0, 8);
        $countries = array_slice($daily['countries'] ?? [], 0, 5);
        $leads = $daily['lead_events'] ?? [];
        $leadLabels = [
            'submit_inquiry' => 'Inquiries submitted',
            'click_phone' => 'Phone clicks',
            'click_whatsapp' => 'WhatsApp clicks',
            'click_email' => 'Email clicks',
        ];
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
                                    <td align="center" valign="top" style="background-color:#f0f5ff;padding:24px 24px 20px;border-bottom:1px solid #d3e0fb;">
                                        <span style="font-size:24px;line-height:32px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#1d4fc4;display:block;">
                                            🌐 Website Analytics Report
                                        </span>
                                        <span style="font-size:14px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;margin-top:6px;">
                                            {{ $report['date_label'] }} · Google Analytics
                                        </span>
                                    </td>
                                </tr>

                                @if (!empty($report['ai_summary']))
                                <tr>
                                    <td valign="top" style="{{ $sectionPad }}">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;">
                                            <tr>
                                                <td style="padding:16px 20px;background:#fffbeb;border:1px solid #fde68a;border-radius:16px;">
                                                    <span style="font-size:12px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:0.06em;display:block;margin-bottom:6px;">Summary</span>
                                                    <span style="font-size:14px;line-height:22px;font-family:Helvetica,Arial,sans-serif;color:#344054;display:block;">{{ $report['ai_summary'] }}</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif

                                <tr>
                                    <td valign="top" style="{{ $sectionPad }}">
                                        <span style="{{ $sectionTitle }}">Yesterday at a glance</span>
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;">
                                            <tr>
                                                <td width="25%" align="center" style="{{ $card }}">
                                                    <span style="{{ $statNum }}">{{ $fmt($totals['active_users'] ?? null) }}</span>
                                                    <span style="{{ $statLbl }}">Visitors {!! $pct($delta['active_users_pct'] ?? null) !!}</span>
                                                </td>
                                                {!! $gap !!}
                                                <td width="25%" align="center" style="{{ $card }}">
                                                    <span style="{{ $statNum }}">{{ $fmt($totals['sessions'] ?? null) }}</span>
                                                    <span style="{{ $statLbl }}">Sessions {!! $pct($delta['sessions_pct'] ?? null) !!}</span>
                                                </td>
                                                {!! $gap !!}
                                                <td width="25%" align="center" style="{{ $card }}">
                                                    <span style="{{ $statNum }}">{{ $fmt($totals['pageviews'] ?? null) }}</span>
                                                    <span style="{{ $statLbl }}">Pageviews {!! $pct($delta['pageviews_pct'] ?? null) !!}</span>
                                                </td>
                                                {!! $gap !!}
                                                <td width="25%" align="center" style="{{ $card }}">
                                                    <span style="{{ $statNum }}">{{ $fmt($totals['new_users'] ?? null) }}</span>
                                                    <span style="{{ $statLbl }}">New visitors {!! $pct($delta['new_users_pct'] ?? null) !!}</span>
                                                </td>
                                            </tr>
                                        </table>
                                        <span style="{{ $muted }}">Change is vs the previous day.</span>
                                    </td>
                                </tr>

                                @if (count($sources))
                                <tr>
                                    <td valign="top" style="{{ $sectionPad }}">
                                        <span style="{{ $sectionTitle }}">Where visitors came from</span>
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;background:#f8fafc;border:1px solid #d6dee8;border-radius:16px;">
                                            @foreach ($sources as $i => $s)
                                            <tr>
                                                <td style="{{ $rowText }} padding:10px 20px;{{ $i < count($sources) - 1 ? 'border-bottom:1px solid #e7edf3;' : '' }}">
                                                    {{ $s['source'] === 'direct' ? 'Direct / typed-in' : $s['source'] }}
                                                </td>
                                                <td align="right" style="{{ $rowText }} font-weight:700;padding:10px 20px;{{ $i < count($sources) - 1 ? 'border-bottom:1px solid #e7edf3;' : '' }}">
                                                    {{ $fmt($s['sessions']) }} sessions
                                                </td>
                                            </tr>
                                            @endforeach
                                        </table>
                                    </td>
                                </tr>
                                @endif

                                @if (count($pages))
                                <tr>
                                    <td valign="top" style="{{ $sectionPad }}">
                                        <span style="{{ $sectionTitle }}">Most-viewed pages</span>
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;background:#f8fafc;border:1px solid #d6dee8;border-radius:16px;">
                                            @foreach ($pages as $i => $p)
                                            <tr>
                                                <td style="{{ $rowText }} padding:9px 20px;max-width:420px;word-break:break-all;{{ $i < count($pages) - 1 ? 'border-bottom:1px solid #e7edf3;' : '' }}">
                                                    {{ $p['path'] }}
                                                </td>
                                                <td align="right" style="{{ $rowText }} font-weight:700;padding:9px 20px;white-space:nowrap;{{ $i < count($pages) - 1 ? 'border-bottom:1px solid #e7edf3;' : '' }}">
                                                    {{ $fmt($p['views']) }}
                                                </td>
                                            </tr>
                                            @endforeach
                                        </table>
                                    </td>
                                </tr>
                                @endif

                                <tr>
                                    <td valign="top" style="{{ $sectionPad }}">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;">
                                            <tr>
                                                @if (count($countries))
                                                <td width="49%" valign="top" style="padding:14px 20px;background:#f8fafc;border:1px solid #d6dee8;border-radius:16px;">
                                                    <span style="{{ $sectionTitle }} margin-bottom:8px;">Top countries</span>
                                                    @foreach ($countries as $c)
                                                        <span style="{{ $rowText }} display:block;">{{ $c['country'] }} — <b>{{ $fmt($c['active_users']) }}</b></span>
                                                    @endforeach
                                                </td>
                                                {!! $gap !!}
                                                @endif
                                                <td width="49%" valign="top" style="padding:14px 20px;background:#f8fafc;border:1px solid #d6dee8;border-radius:16px;">
                                                    <span style="{{ $sectionTitle }} margin-bottom:8px;">Lead actions</span>
                                                    @foreach ($leadLabels as $key => $label)
                                                        <span style="{{ $rowText }} display:block;">{{ $label }} — <b>{{ $fmt($leads[$key] ?? 0) }}</b></span>
                                                    @endforeach
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <tr>
                                    <td valign="top" style="{{ $sectionPad }}">
                                        <span style="{{ $sectionTitle }}">Month to date ({{ $report['mtd_label'] }})</span>
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;">
                                            <tr>
                                                <td width="33%" align="center" style="{{ $card }}">
                                                    <span style="{{ $statNum }}">{{ $fmt($mtdTotals['active_users'] ?? null) }}</span>
                                                    <span style="{{ $statLbl }}">Visitors</span>
                                                </td>
                                                {!! $gap !!}
                                                <td width="33%" align="center" style="{{ $card }}">
                                                    <span style="{{ $statNum }}">{{ $fmt($mtdTotals['sessions'] ?? null) }}</span>
                                                    <span style="{{ $statLbl }}">Sessions</span>
                                                </td>
                                                {!! $gap !!}
                                                <td width="33%" align="center" style="{{ $card }}">
                                                    <span style="{{ $statNum }}">{{ $fmt($mtdTotals['pageviews'] ?? null) }}</span>
                                                    <span style="{{ $statLbl }}">Pageviews</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" valign="top" style="padding:28px 32px 32px;">
                                        <span style="font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#98a2b3;display:block;">
                                            Generated automatically from Google Analytics for filipinohomes.com.<br />
                                            Manage recipients and schedule in Admin → Insights &amp; Analytics → Website Analytics.
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

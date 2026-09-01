<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:32px 12px;background-color:#edf2f7;">
    @php
        $fmt = fn ($n) => number_format((int) $n);
        $channelLabel = fn ($c) => ucwords(str_replace(['_', '-'], ' ', (string) $c));
        // ── Shared styles. Card rows are tables with border-collapse:collapse
        //    and explicit SPACER cells for gaps (no border-spacing / negative
        //    margins: those add outer spacing the 640px shell can't absorb and
        //    clip the right edge on phones).
        $sectionTitle = 'font-size:13px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:0.08em;display:block;margin-bottom:12px;';
        $card = 'padding:16px 8px;background:#f8fafc;border:1px solid #d6dee8;border-radius:16px;';
        $listCard = 'padding:14px 20px;background:#f8fafc;border:1px solid #d6dee8;';
        $th = 'font-size:12px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:0.05em;padding:8px 10px;border-bottom:1px solid #d6dee8;';
        $td = 'font-size:14px;font-family:Helvetica,Arial,sans-serif;color:#344054;padding:8px 10px;border-bottom:1px solid #e7edf3;';
        $statNum = 'font-size:24px;line-height:30px;font-family:Helvetica,Arial,sans-serif;font-weight:800;color:#162033;display:block;';
        $statLbl = 'font-size:12px;font-family:Helvetica,Arial,sans-serif;color:#667085;display:block;margin-top:4px;';
        $rowText = 'font-size:14px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#344054;display:block;';
        $muted = 'font-size:12px;font-family:Helvetica,Arial,sans-serif;color:#98a2b3;display:block;margin-top:8px;';
        $sectionPad = 'padding:24px 32px 0;';
        $gap = '<td width="12" style="font-size:0;line-height:0;">&nbsp;</td>';
        $vgap = '<tr><td colspan="5" height="12" style="font-size:0;line-height:0;height:12px;">&nbsp;</td></tr>';
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
                                            📊 Site Activity Report
                                        </span>
                                        <span style="font-size:14px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;margin-top:6px;">
                                            {{ $periodLabel }}
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="left" valign="top" style="padding:24px 32px 0;">
                                        <span style="font-size:15px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Hello {{ $recipientName }}, here is the Filipino Homes activity summary for this period.
                                        </span>
                                    </td>
                                </tr>

                                {{-- ── Audience — 2×2 cards so nothing squeezes on phones ── --}}
                                <tr>
                                    <td align="left" valign="top" style="{{ $sectionPad }}">
                                        <span style="{{ $sectionTitle }}">Audience (including anonymous)</span>
                                        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                            <tr>
                                                <td width="48%" align="center" style="{{ $card }}">
                                                    <span style="{{ $statNum }}">{{ $fmt($report['audience']['unique_visitors']) }}</span>
                                                    <span style="{{ $statLbl }}">Unique visitors</span>
                                                </td>
                                                {!! $gap !!}
                                                <td width="48%" align="center" style="{{ $card }}">
                                                    <span style="{{ $statNum }} color:#15803d;">{{ $fmt($report['audience']['new_clients']) }}</span>
                                                    <span style="{{ $statLbl }}">New clients</span>
                                                </td>
                                            </tr>
                                            {!! $vgap !!}
                                            <tr>
                                                <td width="48%" align="center" style="{{ $card }}">
                                                    <span style="{{ $statNum }} color:#1d4fc4;">{{ $fmt($report['audience']['returning_clients']) }}</span>
                                                    <span style="{{ $statLbl }}">Returning clients</span>
                                                </td>
                                                {!! $gap !!}
                                                <td width="48%" align="center" style="{{ $card }}">
                                                    <span style="{{ $statNum }} color:#7c3aed;">{{ $fmt($report['audience']['new_agents']) }}</span>
                                                    <span style="{{ $statLbl }}">New agents</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                {{-- ── Traffic sources ── --}}
                                <tr>
                                    <td align="left" valign="top" style="{{ $sectionPad }}">
                                        <span style="{{ $sectionTitle }}">Traffic Sources</span>
                                        <div style="{{ $listCard }} padding:6px 12px 10px;">
                                            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                                <tr>
                                                    <td style="{{ $th }}">Source</td>
                                                    <td align="right" style="{{ $th }}">Visitors</td>
                                                    <td align="right" style="{{ $th }}">New</td>
                                                    <td align="right" style="{{ $th }}">Returning</td>
                                                </tr>
                                                @forelse ($report['traffic_channels'] as $ch)
                                                    <tr>
                                                        <td style="{{ $td }} font-weight:600;color:#162033;">{{ $channelLabel($ch['channel']) }}</td>
                                                        <td align="right" style="{{ $td }}">{{ $fmt($ch['value']) }}</td>
                                                        <td align="right" style="{{ $td }} color:#15803d;font-weight:600;">{{ $fmt($ch['new']) }}</td>
                                                        <td align="right" style="{{ $td }} color:#1d4fc4;font-weight:600;">{{ $fmt($ch['returning']) }}</td>
                                                    </tr>
                                                @empty
                                                    <tr><td colspan="4" style="{{ $td }}">No traffic recorded in this period.</td></tr>
                                                @endforelse
                                            </table>
                                        </div>
                                    </td>
                                </tr>

                                {{-- ── Audience geography (PH only) ── --}}
                                <tr>
                                    <td align="left" valign="top" style="{{ $sectionPad }}">
                                        <span style="{{ $sectionTitle }}">Audience Geography — Philippines 🇵🇭</span>
                                        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                            <tr>
                                                <td width="48%" valign="top" style="{{ $listCard }} padding:10px 14px;">
                                                    <span style="font-size:12px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#667085;text-transform:uppercase;display:block;padding:4px 0 6px;">Top Provinces</span>
                                                    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                                        @forelse ($report['geo_ph']['provinces'] as $row)
                                                            <tr>
                                                                <td style="{{ $td }} padding:6px 2px;">{{ $row['name'] }}</td>
                                                                <td align="right" style="{{ $td }} padding:6px 2px;font-weight:700;color:#162033;">{{ $fmt($row['value']) }}</td>
                                                            </tr>
                                                        @empty
                                                            <tr><td style="{{ $td }} padding:6px 2px;">No data.</td></tr>
                                                        @endforelse
                                                    </table>
                                                </td>
                                                {!! $gap !!}
                                                <td width="48%" valign="top" style="{{ $listCard }} padding:10px 14px;">
                                                    <span style="font-size:12px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#667085;text-transform:uppercase;display:block;padding:4px 0 6px;">Top Cities</span>
                                                    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                                        @forelse ($report['geo_ph']['cities'] as $row)
                                                            <tr>
                                                                <td style="{{ $td }} padding:6px 2px;">{{ $row['name'] }}</td>
                                                                <td align="right" style="{{ $td }} padding:6px 2px;font-weight:700;color:#162033;">{{ $fmt($row['value']) }}</td>
                                                            </tr>
                                                        @empty
                                                            <tr><td style="{{ $td }} padding:6px 2px;">No data.</td></tr>
                                                        @endforelse
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                {{-- ── Inquiries ── --}}
                                <tr>
                                    <td align="left" valign="top" style="{{ $sectionPad }}">
                                        <span style="{{ $sectionTitle }}">Inquiries</span>
                                        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                            <tr>
                                                <td width="31%" align="center" style="{{ $card }}">
                                                    <span style="{{ $statNum }} font-size:22px;">{{ $fmt($report['inquiries']['received']) }}</span>
                                                    <span style="{{ $statLbl }}">Received</span>
                                                </td>
                                                {!! $gap !!}
                                                <td width="31%" align="center" style="{{ $card }} background:#f0fdf4;border-color:#bbf7d0;">
                                                    <span style="{{ $statNum }} font-size:22px;color:#15803d;">{{ $fmt($report['inquiries']['approved']) }}</span>
                                                    <span style="{{ $statLbl }}">Forwarded to agent</span>
                                                </td>
                                                {!! $gap !!}
                                                <td width="31%" align="center" style="{{ $card }} background:#fffbeb;border-color:#fde68a;">
                                                    <span style="{{ $statNum }} font-size:22px;color:#b45309;">{{ $fmt($report['inquiries']['pending_now']) }}</span>
                                                    <span style="{{ $statLbl }}">To be approved</span>
                                                </td>
                                            </tr>
                                        </table>
                                        <span style="{{ $muted }}">
                                            Received &amp; Forwarded are within this period; &ldquo;To be approved&rdquo; is the current pending queue.
                                        </span>
                                    </td>
                                </tr>

                                {{-- ── Inquiry response (forwarded-to-agent only) ── --}}
                                <tr>
                                    <td align="left" valign="top" style="{{ $sectionPad }}">
                                        <span style="{{ $sectionTitle }}">Inquiry Response</span>
                                        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                            <tr>
                                                <td width="48%" align="center" style="{{ $card }} background:#f0fdf4;border-color:#bbf7d0;">
                                                    <span style="{{ $statNum }} color:#15803d;">{{ $fmt($report['inquiry_response']['answered']) }}</span>
                                                    <span style="{{ $statLbl }}">Answered</span>
                                                </td>
                                                {!! $gap !!}
                                                <td width="48%" align="center" style="{{ $card }} background:#fff7ed;border-color:#fed7aa;">
                                                    <span style="{{ $statNum }} color:#c2410c;">{{ $fmt($report['inquiry_response']['unanswered']) }}</span>
                                                    <span style="{{ $statLbl }}">Unanswered</span>
                                                </td>
                                            </tr>
                                        </table>
                                        <span style="{{ $muted }}">
                                            Counts only the inquiries forwarded to an agent this period — answered means the agent has replied.
                                        </span>
                                    </td>
                                </tr>

                                {{-- ── Created listings + transactions (transactions hidden when the period closed none) ── --}}
                                <tr>
                                    <td align="left" valign="top" style="{{ $sectionPad }}">
                                        <span style="{{ $sectionTitle }}">Created Listings</span>
                                        <div style="{{ $card }} text-align:center;padding:16px 8px;border-radius:0;">
                                            <span style="{{ $statNum }}">{{ $fmt($report['listings']['total']) }}</span>
                                            <span style="{{ $statLbl }}">New listings</span>
                                        </div>
                                    </td>
                                </tr>
                                @if ($report['transactions']['sold'] + $report['transactions']['rented'] + $report['transactions']['leased'] > 0)
                                <tr>
                                    <td align="left" valign="top" style="{{ $sectionPad }}">
                                        <span style="{{ $sectionTitle }}">Transactions</span>
                                        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                            <tr>
                                                <td width="31%" align="center" style="{{ $card }} background:#f0fdf4;border-color:#bbf7d0;">
                                                    <span style="{{ $statNum }} font-size:22px;color:#15803d;">{{ $fmt($report['transactions']['sold']) }}</span>
                                                    <span style="{{ $statLbl }}">Sold</span>
                                                </td>
                                                {!! $gap !!}
                                                <td width="31%" align="center" style="{{ $card }} background:#eff6ff;border-color:#bfdbfe;">
                                                    <span style="{{ $statNum }} font-size:22px;color:#1d4fc4;">{{ $fmt($report['transactions']['rented']) }}</span>
                                                    <span style="{{ $statLbl }}">Rented</span>
                                                </td>
                                                {!! $gap !!}
                                                <td width="31%" align="center" style="{{ $card }} background:#faf5ff;border-color:#e9d5ff;">
                                                    <span style="{{ $statNum }} font-size:22px;color:#7c3aed;">{{ $fmt($report['transactions']['leased']) }}</span>
                                                    <span style="{{ $statLbl }}">Leased</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif

                                {{-- ── New projects — hidden entirely when the period created none ── --}}
                                @if ($report['projects']['total'] > 0)
                                <tr>
                                    <td align="left" valign="top" style="{{ $sectionPad }}">
                                        <span style="{{ $sectionTitle }}">New Projects — {{ $fmt($report['projects']['total']) }}</span>
                                        <div style="{{ $listCard }}">
                                            @forelse ($report['projects']['names'] as $proj)
                                                <span style="{{ $rowText }}">
                                                    🏗️ <strong style="color:#162033;">{{ $proj['name'] }}</strong>
                                                    <span style="color:#98a2b3;">— {{ $proj['agent_email'] }}</span>
                                                </span>
                                            @empty
                                                <span style="font-size:14px;font-family:Helvetica,Arial,sans-serif;color:#667085;">No new projects in this period.</span>
                                            @endforelse
                                            @if ($report['projects']['total'] > count($report['projects']['names']))
                                                <span style="{{ $muted }} margin-top:6px;">
                                                    + {{ $report['projects']['total'] - count($report['projects']['names']) }} more
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @endif

                                {{-- ── Birthdays 🎂 — today only when there is one; upcoming = next 30 days.
                                     Celebration gold, deliberately not red/pink. ── --}}
                                @if (count($report['birthdays']['today']) > 0)
                                    <tr>
                                        <td align="left" valign="top" style="{{ $sectionPad }}">
                                            <span style="{{ $sectionTitle }}">🎂 Today&rsquo;s Birthdays</span>
                                            <div style="{{ $listCard }} background:#fffbeb;border-color:#fde68a;">
                                                @foreach ($report['birthdays']['today'] as $b)
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
                                            @forelse ($report['birthdays']['upcoming'] as $b)
                                                <span style="{{ $rowText }}">
                                                    🎈 <strong style="color:#162033;">{{ $b['name'] }}</strong>
                                                    <span style="color:#98a2b3;">— {{ $b['date'] }}</span>
                                                </span>
                                            @empty
                                                <span style="font-size:14px;font-family:Helvetica,Arial,sans-serif;color:#667085;">No birthdays in the next 30 days.</span>
                                            @endforelse
                                            @if ($report['birthdays']['upcoming_total'] > count($report['birthdays']['upcoming']))
                                                <span style="{{ $muted }} margin-top:6px;">
                                                    + {{ $report['birthdays']['upcoming_total'] - count($report['birthdays']['upcoming']) }} more within 30 days
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" valign="top" style="padding:28px 32px 32px;">
                                        <span style="font-size:12px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#98a2b3;display:block;border-top:1px solid #e7edf3;padding-top:20px;">
                                            Generated automatically by filipinohomes.com · Numbers match the admin Insights dashboards.
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

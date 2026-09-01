<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:32px 16px;background-color:#edf2f7;">
    @php
        $fmt = fn ($n) => number_format((int) $n);
        $channelLabel = fn ($c) => ucwords(str_replace(['_', '-'], ' ', (string) $c));
        $sectionTitle = 'font-size:13px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:0.08em;display:block;margin-bottom:12px;';
        $card = 'padding:20px 24px;background:#f8fafc;border:1px solid #d6dee8;border-radius:16px;box-sizing:border-box;';
        $th = 'font-size:12px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:0.05em;padding:8px 10px;border-bottom:1px solid #d6dee8;';
        $td = 'font-size:14px;font-family:Helvetica,Arial,sans-serif;color:#344054;padding:8px 10px;border-bottom:1px solid #e7edf3;';
        $statNum = 'font-size:26px;line-height:32px;font-family:Helvetica,Arial,sans-serif;font-weight:800;color:#162033;display:block;';
        $statLbl = 'font-size:12px;font-family:Helvetica,Arial,sans-serif;color:#667085;display:block;margin-top:2px;';
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
                                    <td align="center" valign="top" style="background:#245ee0;padding:44px 32px 36px;border-bottom:1px solid #1f478b;">
                                        <img align="center" alt="Filipino Homes" src="https://api2.filipinohomes.com/fh-logo-white.png" width="350"
                                            style="max-width:350px;width:100%;display:inline !important;border:0;height:auto;" />
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" valign="top" style="background-color:#f0f5ff;padding:26px 32px 20px;border-bottom:1px solid #d3e0fb;">
                                        <span style="font-size:26px;line-height:34px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#1d4fc4;display:block;">
                                            📊 Site Activity Report
                                        </span>
                                        <span style="font-size:14px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;margin-top:6px;">
                                            {{ $periodLabel }}
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="left" valign="top" style="padding:28px 40px 8px;">
                                        <span style="font-size:15px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">
                                            Hello {{ $recipientName }}, here is the Filipino Homes activity summary for this period.
                                        </span>
                                    </td>
                                </tr>

                                {{-- ── Audience headline ── --}}
                                <tr>
                                    <td align="left" valign="top" style="padding:20px 40px 8px;">
                                        <span style="{{ $sectionTitle }}">Audience (including anonymous)</span>
                                        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:8px 0;margin-left:-8px;">
                                            <tr>
                                                <td width="25%" align="center" style="{{ $card }} padding:20px 12px;">
                                                    <span style="{{ $statNum }}">{{ $fmt($report['audience']['unique_visitors']) }}</span>
                                                    <span style="{{ $statLbl }}">Unique visitors</span>
                                                </td>
                                                <td width="25%" align="center" style="{{ $card }} padding:20px 12px;">
                                                    <span style="{{ $statNum }} color:#15803d;">{{ $fmt($report['audience']['new_clients']) }}</span>
                                                    <span style="{{ $statLbl }}">New clients</span>
                                                </td>
                                                <td width="25%" align="center" style="{{ $card }} padding:20px 12px;">
                                                    <span style="{{ $statNum }} color:#1d4fc4;">{{ $fmt($report['audience']['returning_clients']) }}</span>
                                                    <span style="{{ $statLbl }}">Returning clients</span>
                                                </td>
                                                <td width="25%" align="center" style="{{ $card }} padding:20px 12px;">
                                                    <span style="{{ $statNum }} color:#7c3aed;">{{ $fmt($report['audience']['new_agents']) }}</span>
                                                    <span style="{{ $statLbl }}">New agents</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                {{-- ── Traffic sources ── --}}
                                <tr>
                                    <td align="left" valign="top" style="padding:24px 40px 8px;">
                                        <span style="{{ $sectionTitle }}">Traffic Sources</span>
                                        <div style="{{ $card }} padding:8px 16px 12px;">
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
                                    <td align="left" valign="top" style="padding:24px 40px 8px;">
                                        <span style="{{ $sectionTitle }}">Audience Geography — Philippines 🇵🇭</span>
                                        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:8px 0;margin-left:-8px;">
                                            <tr>
                                                <td width="50%" valign="top" style="{{ $card }} padding:12px 16px;">
                                                    <span style="font-size:12px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#667085;text-transform:uppercase;display:block;padding:4px 0 6px;">Top Provinces</span>
                                                    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                                        @forelse ($report['geo_ph']['provinces'] as $row)
                                                            <tr>
                                                                <td style="{{ $td }} padding:6px 4px;">{{ $row['name'] }}</td>
                                                                <td align="right" style="{{ $td }} padding:6px 4px;font-weight:700;color:#162033;">{{ $fmt($row['value']) }}</td>
                                                            </tr>
                                                        @empty
                                                            <tr><td style="{{ $td }} padding:6px 4px;">No data.</td></tr>
                                                        @endforelse
                                                    </table>
                                                </td>
                                                <td width="50%" valign="top" style="{{ $card }} padding:12px 16px;">
                                                    <span style="font-size:12px;font-family:Helvetica,Arial,sans-serif;font-weight:700;color:#667085;text-transform:uppercase;display:block;padding:4px 0 6px;">Top Cities</span>
                                                    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                                        @forelse ($report['geo_ph']['cities'] as $row)
                                                            <tr>
                                                                <td style="{{ $td }} padding:6px 4px;">{{ $row['name'] }}</td>
                                                                <td align="right" style="{{ $td }} padding:6px 4px;font-weight:700;color:#162033;">{{ $fmt($row['value']) }}</td>
                                                            </tr>
                                                        @empty
                                                            <tr><td style="{{ $td }} padding:6px 4px;">No data.</td></tr>
                                                        @endforelse
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                {{-- ── Inquiries ── --}}
                                <tr>
                                    <td align="left" valign="top" style="padding:24px 40px 8px;">
                                        <span style="{{ $sectionTitle }}">Inquiries</span>
                                        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:8px 0;margin-left:-8px;">
                                            <tr>
                                                <td width="33%" align="center" style="{{ $card }}">
                                                    <span style="{{ $statNum }}">{{ $fmt($report['inquiries']['received']) }}</span>
                                                    <span style="{{ $statLbl }}">Received</span>
                                                </td>
                                                <td width="33%" align="center" style="{{ $card }} background:#f0fdf4;border-color:#bbf7d0;">
                                                    <span style="{{ $statNum }} color:#15803d;">{{ $fmt($report['inquiries']['approved']) }}</span>
                                                    <span style="{{ $statLbl }}">Forwarded to agent</span>
                                                </td>
                                                <td width="33%" align="center" style="{{ $card }} background:#fffbeb;border-color:#fde68a;">
                                                    <span style="{{ $statNum }} color:#b45309;">{{ $fmt($report['inquiries']['pending_now']) }}</span>
                                                    <span style="{{ $statLbl }}">To be approved</span>
                                                </td>
                                            </tr>
                                        </table>
                                        <span style="font-size:12px;font-family:Helvetica,Arial,sans-serif;color:#98a2b3;display:block;margin-top:8px;">
                                            Received &amp; Forwarded are within this period; &ldquo;To be approved&rdquo; is the current pending queue.
                                        </span>
                                    </td>
                                </tr>

                                {{-- ── Inquiry response (forwarded-to-agent only) ── --}}
                                <tr>
                                    <td align="left" valign="top" style="padding:24px 40px 8px;">
                                        <span style="{{ $sectionTitle }}">Inquiry Response</span>
                                        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:8px 0;margin-left:-8px;">
                                            <tr>
                                                <td width="50%" align="center" style="{{ $card }} background:#f0fdf4;border-color:#bbf7d0;">
                                                    <span style="{{ $statNum }} color:#15803d;">{{ $fmt($report['inquiry_response']['answered']) }}</span>
                                                    <span style="{{ $statLbl }}">Answered</span>
                                                </td>
                                                <td width="50%" align="center" style="{{ $card }} background:#fef2f2;border-color:#fecaca;">
                                                    <span style="{{ $statNum }} color:#b91c1c;">{{ $fmt($report['inquiry_response']['unanswered']) }}</span>
                                                    <span style="{{ $statLbl }}">Unanswered</span>
                                                </td>
                                            </tr>
                                        </table>
                                        <span style="font-size:12px;font-family:Helvetica,Arial,sans-serif;color:#98a2b3;display:block;margin-top:8px;">
                                            Counts only the inquiries forwarded to an agent this period — answered means the agent has replied.
                                        </span>
                                    </td>
                                </tr>

                                {{-- ── Created listings ── --}}
                                <tr>
                                    <td align="left" valign="top" style="padding:24px 40px 8px;">
                                        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:8px 0;margin-left:-8px;">
                                            <tr>
                                                <td width="40%" valign="top">
                                                    <span style="{{ $sectionTitle }}">Created Listings</span>
                                                    <div style="{{ $card }} text-align:center;">
                                                        <span style="{{ $statNum }}">{{ $fmt($report['listings']['total']) }}</span>
                                                        <span style="{{ $statLbl }}">New listings</span>
                                                    </div>
                                                </td>
                                                <td width="60%" valign="top">
                                                    <span style="{{ $sectionTitle }}">Transactions</span>
                                                    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:8px 0;margin-left:-8px;">
                                                        <tr>
                                                            <td width="33%" align="center" style="{{ $card }} background:#f0fdf4;border-color:#bbf7d0;padding:20px 12px;">
                                                                <span style="{{ $statNum }} font-size:22px;color:#15803d;">{{ $fmt($report['transactions']['sold']) }}</span>
                                                                <span style="{{ $statLbl }}">Sold</span>
                                                            </td>
                                                            <td width="33%" align="center" style="{{ $card }} background:#eff6ff;border-color:#bfdbfe;padding:20px 12px;">
                                                                <span style="{{ $statNum }} font-size:22px;color:#1d4fc4;">{{ $fmt($report['transactions']['rented']) }}</span>
                                                                <span style="{{ $statLbl }}">Rented</span>
                                                            </td>
                                                            <td width="33%" align="center" style="{{ $card }} background:#faf5ff;border-color:#e9d5ff;padding:20px 12px;">
                                                                <span style="{{ $statNum }} font-size:22px;color:#7c3aed;">{{ $fmt($report['transactions']['leased']) }}</span>
                                                                <span style="{{ $statLbl }}">Leased</span>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                {{-- ── New projects ── --}}
                                <tr>
                                    <td align="left" valign="top" style="padding:24px 40px 8px;">
                                        <span style="{{ $sectionTitle }}">New Projects — {{ $fmt($report['projects']['total']) }}</span>
                                        <div style="{{ $card }} padding:12px 20px;">
                                            @forelse ($report['projects']['names'] as $proj)
                                                <span style="font-size:14px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#344054;display:block;">
                                                    🏗️ <strong style="color:#162033;">{{ $proj['name'] }}</strong>
                                                    <span style="color:#98a2b3;">— {{ $proj['agent_email'] }}</span>
                                                </span>
                                            @empty
                                                <span style="font-size:14px;font-family:Helvetica,Arial,sans-serif;color:#667085;">No new projects in this period.</span>
                                            @endforelse
                                            @if ($report['projects']['total'] > count($report['projects']['names']))
                                                <span style="font-size:13px;font-family:Helvetica,Arial,sans-serif;color:#98a2b3;display:block;margin-top:6px;">
                                                    + {{ $report['projects']['total'] - count($report['projects']['names']) }} more
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" valign="top" style="padding:28px 40px 36px;">
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

@php
    /**
     * The agent's own birthday greeting — a short letter from Anthony
     * Leuterio, wrapped around the poster BirthdayPosterService composited for
     * them.
     *
     * ─── Constraints this markup is shaped by ───────────────────────────────
     *  • Outlook renders through Word: no flexbox, no grid, no CSS gradients.
     *    Layout is nested tables with `bgcolor` ATTRIBUTES as well as inline
     *    styles, because Word ignores background shorthand on <td>. The
     *    border-radius and box-shadow on the container degrade there, which is
     *    the same trade every other template in this repo makes.
     *  • Gmail strips <style> blocks in some contexts, so every rule that
     *    matters is inline. The <style> block holds only the mobile media
     *    query, which Gmail does honour.
     *  • Web fonts do not load. Helvetica throughout.
     *  • Gmail's dark mode inverts INHERITED text colours, so every colour is
     *    set explicitly on the element that shows it.
     *  • IMAGES ARE OFTEN BLOCKED, and the poster is the best thing in here.
     *    The amber band states the greeting in live type so a client with
     *    images switched off still delivers the message, and the poster is
     *    also attached to the message (see the mailable) so it survives even
     *    when the hosted copy can't be fetched.
     *
     * Palette is the house celebration set, shared with agent-certificate:
     * FH blue header, amber band, slate body copy.
     */
    $blue      = '#245ee0';
    $blueEdge  = '#1f478b';
    $amberBg   = '#fffbeb';
    $amberEdge = '#fde68a';
    $amberInk  = '#b45309';
    $amberSoft = '#92400e';
    $ink       = '#162033';
    $body      = '#475467';
    $muted     = '#667085';
    $footerInk = '#94a3b8';
    $panelBg   = '#f8fafc';
    $panelEdge = '#e2e8f0';

    $font = 'Helvetica,Arial,sans-serif';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Happy Birthday, {{ $firstName }}!</title>
    <style>
        @media only screen and (max-width: 620px) {
            .pad { padding-left: 24px !important; padding-right: 24px !important; }
            .h1  { font-size: 24px !important; line-height: 32px !important; }
            .cta { display: block !important; width: auto !important; }
        }
    </style>
</head>
<body style="margin:0;padding:32px 12px;background-color:#edf2f7;">

    {{-- Preheader: the line Gmail shows next to the subject in the inbox list.
         Without it, clients preview the logo's alt text. --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#edf2f7;font-size:1px;line-height:1px;">
        A note from Anthony Leuterio and your Filipino Homes family. &nbsp;&nbsp;&nbsp;
    </div>

    <center>
        <table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" width="100%"
            style="border-collapse:collapse;width:100%;margin:0;padding:0;">
            <tbody>
                <tr>
                    <td align="center" valign="top" style="margin:0;padding:0;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                            bgcolor="#ffffff"
                            style="border-collapse:collapse;width:100%;max-width:640px;background-color:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 48px rgba(15, 23, 42, 0.12);">
                            <tbody>

                                {{-- Brand header --}}
                                <tr>
                                    <td align="center" valign="top" bgcolor="{{ $blue }}"
                                        style="background:{{ $blue }};padding:40px 32px 34px;border-bottom:1px solid {{ $blueEdge }};">
                                        <img align="center" alt="Filipino Homes"
                                            src="https://api2.filipinohomes.com/fh-logo-white.png"
                                            width="320"
                                            style="max-width:320px;width:100%;display:inline !important;border:0;height:auto;" />
                                    </td>
                                </tr>

                                {{-- Headline band. Live type, not an image: this is what
                                     carries the greeting when images are blocked. --}}
                                <tr>
                                    <td align="center" valign="top" bgcolor="{{ $amberBg }}"
                                        style="background-color:{{ $amberBg }};padding:30px 32px 26px;border-bottom:1px solid {{ $amberEdge }};">
                                        <span style="font-size:13px;line-height:18px;font-family:{{ $font }};font-weight:700;color:{{ $amberSoft }};letter-spacing:0.14em;text-transform:uppercase;display:block;">
                                            🎂 &nbsp;Happy Birthday
                                        </span>
                                        <span class="h1" style="font-size:30px;line-height:40px;font-family:{{ $font }};font-weight:700;color:{{ $amberInk }};display:block;padding-top:6px;">
                                            {{ $firstName }}!
                                        </span>
                                    </td>
                                </tr>

                                @if (! empty($posterUrl))
                                    {{-- The poster, shown rather than left as an attachment
                                         nobody taps. Capped at 420px: the artwork is a 2:3
                                         portrait, and at full width it pushes the letter
                                         below the fold on a phone. line-height/font-size
                                         zero kills the descender gap under the image. --}}
                                    <tr>
                                        <td align="center" valign="top" bgcolor="#ffffff"
                                            style="background-color:#ffffff;padding:28px 32px 0;line-height:0;font-size:0;">
                                            <img src="{{ $posterUrl }}"
                                                alt="Happy Birthday, {{ $fullName }} — from the Filipino Homes family"
                                                width="420"
                                                style="display:block;width:100%;max-width:420px;height:auto;border:0;outline:none;text-decoration:none;border-radius:12px;" />
                                        </td>
                                    </tr>

                                    <tr>
                                        <td align="center" valign="top" bgcolor="#ffffff" class="pad"
                                            style="background-color:#ffffff;padding:20px 40px 4px;">
                                            <a href="{{ $posterUrl }}" class="cta"
                                                style="display:inline-block;padding:14px 28px;font-size:15px;font-family:{{ $font }};color:#ffffff;background-color:{{ $blue }};border-radius:999px;text-decoration:none;font-weight:600;line-height:1;">
                                                Save your poster
                                            </a>
                                            <span style="display:block;font-size:12px;line-height:20px;font-family:{{ $font }};color:{{ $muted }};padding-top:10px;">
                                                It&rsquo;s attached to this email too — post it, share it, send it to the group chat.
                                            </span>
                                        </td>
                                    </tr>
                                @endif

                                {{-- The letter. Left-aligned and set at letter-opening size
                                     rather than as a centred heading, because that is what
                                     it is. --}}
                                <tr>
                                    <td align="left" valign="top" bgcolor="#ffffff" class="pad"
                                        style="background-color:#ffffff;padding:30px 40px 8px;">
                                        <span style="font-size:18px;line-height:26px;font-family:{{ $font }};font-weight:700;color:{{ $ink }};display:block;">
                                            Dear {{ $firstName }},
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="left" valign="top" bgcolor="#ffffff" class="pad"
                                        style="background-color:#ffffff;padding:12px 40px 8px;">
                                        <span style="font-size:16px;line-height:28px;font-family:{{ $font }};color:{{ $body }};display:block;">
                                            Today isn&rsquo;t about listings, targets, or closings &mdash; it&rsquo;s about you.
                                            <br /><br />
                                            Thank you for the trust you place in this company every day, and for every
                                            family you&rsquo;ve helped find their way home. Whatever this year brings, I hope
                                            it brings it in abundance: good health, peace at home, and a few wins worth
                                            celebrating.
                                            <br /><br />
                                            Enjoy your day. You&rsquo;ve earned it.
                                        </span>
                                    </td>
                                </tr>

                                {{-- Signature --}}
                                <tr>
                                    <td align="left" valign="top" bgcolor="#ffffff" class="pad"
                                        style="background-color:#ffffff;padding:22px 40px 34px;">
                                        <span style="font-size:16px;line-height:26px;font-family:{{ $font }};font-weight:700;color:{{ $ink }};display:block;">
                                            &mdash; Anthony Gerard O. Leuterio
                                        </span>
                                        <span style="font-size:13px;line-height:20px;font-family:{{ $font }};color:{{ $muted }};display:block;padding-top:2px;">
                                            Founder &amp; President, Filipino Homes
                                        </span>
                                        <span style="font-size:14px;line-height:22px;font-family:{{ $font }};color:{{ $amberInk }};font-weight:600;display:block;padding-top:10px;">
                                            &hellip;and your whole Filipino Homes family 🎉
                                        </span>
                                    </td>
                                </tr>

                                {{-- Footer: the same NAP block as the site footer, so
                                     recipients and citation crawlers see one set of
                                     details. --}}
                                <tr>
                                    <td align="center" valign="top" bgcolor="{{ $panelBg }}"
                                        style="background-color:{{ $panelBg }};padding:24px 32px;border-top:1px solid {{ $panelEdge }};">
                                        <span style="font-size:12px;line-height:20px;font-family:{{ $font }};color:{{ $muted }};display:block;font-weight:600;">
                                            Leuterio Realty &amp; Brokerage &middot; Filipino Homes &middot; Rent.ph &middot; Homes.ph
                                        </span>
                                        <span style="font-size:12px;line-height:20px;font-family:{{ $font }};color:{{ $footerInk }};display:block;padding-top:8px;">
                                            133F Aznar Road, Sambag 2, Urgello St., Cebu City, 6000 Cebu
                                            <br />(032) 254-8900 &middot; (+63) 977-815-0888 &middot; info@filipinohomes.com
                                        </span>
                                        <span style="font-size:11px;line-height:19px;font-family:{{ $font }};color:{{ $footerInk }};display:block;padding-top:12px;">
                                            You&rsquo;re receiving this because you&rsquo;re part of the Filipino Homes family.
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

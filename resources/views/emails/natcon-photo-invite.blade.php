@php
    /**
     * NATCON photo-confirmation email — dark art-deco, matching the event banner.
     *
     * ─── Constraints this markup is shaped by ───────────────────────────────
     *  • Outlook renders through Word: no flexbox, no grid, no CSS gradients, no
     *    border-radius on tables. Layout is nested tables with `bgcolor`
     *    ATTRIBUTES as well as inline styles, because Word ignores background
     *    shorthand on <td>.
     *  • Gmail strips <style> blocks in some contexts, so every rule that matters
     *    is inline. The <style> block only holds the mobile media query, which
     *    Gmail does honour.
     *  • Web fonts do not load. Helvetica with wide letter-spacing and heavy
     *    weight is what evokes the banner's condensed display type.
     *  • Dark backgrounds are safe, but Gmail's dark mode can invert light text.
     *    Every text colour is set explicitly on the element rather than inherited,
     *    which is what stops the inversion mangling it.
     */
    $ink      = '#0a0a14';   // near-black, from the banner
    $inkSoft  = '#14142a';   // panel
    $gold     = '#d4af37';
    $goldSoft = '#8a6f22';   // hairline rules
    $cream    = '#f6f1e4';
    $muted    = '#a49d8c';

    $urgent = $mode === 'reminder' && $daysRemaining <= 1;

    // Accent + banner line per mode. Gold for the invite, amber as the deadline
    // nears, red on the final day.
    $theme = $mode === 'invite'
        ? ['accent' => $gold,      'label' => 'AWARDEE PHOTO CONFIRMATION']
        : ($urgent
            ? ['accent' => '#ff6b6b', 'label' => $daysRemaining <= 0 ? 'FINAL NOTICE — DEADLINE TODAY' : 'FINAL NOTICE — LAST DAY TOMORROW']
            : ['accent' => '#f0b429', 'label' => 'REMINDER — PHOTO DEADLINE APPROACHING']);

    $hasPhotos = count($photos) > 0;
    $shown     = array_slice($photos, 0, 2);   // two reads well at 640px; three is mush
    $cols      = max(1, count($shown));

    $countdown = $daysRemaining <= 0
        ? 'TODAY IS THE LAST DAY'
        : ($daysRemaining === 1 ? '1 DAY LEFT' : $daysRemaining . ' DAYS LEFT');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>{{ $eventName }}</title>
    <style>
        @media only screen and (max-width: 620px) {
            .pad      { padding-left: 22px !important; padding-right: 22px !important; }
            .h1       { font-size: 24px !important; letter-spacing: 2px !important; }
            .cta      { display: block !important; width: auto !important; margin: 0 0 12px 0 !important; }
            .photo    { height: 200px !important; }
            .stackcol { display: block !important; width: 100% !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:{{ $ink }};">
    {{-- Preheader: the line Gmail shows next to the subject in the list. Hidden
         in the body itself. Without it, clients preview the alt text. --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:{{ $ink }};font-size:1px;line-height:1px;">
        {{ $hasPhotos ? 'Keep the photo we have on file, or send us a new one.' : 'We still need your photo for the event.' }}
        &nbsp;Deadline {{ $deadlineLabel }}.
    </div>

    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
        bgcolor="{{ $ink }}" style="background-color:{{ $ink }};margin:0;padding:0;width:100%;">
        <tr>
            <td align="center" valign="top" style="padding:24px 12px;">

                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="640"
                    bgcolor="{{ $inkSoft }}"
                    style="width:640px;max-width:640px;background-color:{{ $inkSoft }};border:1px solid {{ $goldSoft }};">

                    {{-- ── Event banner. Hosted on S3, not the web app: email art
                         must not depend on a frontend deploy, and Gmail caches
                         whatever it fetches the first time. ── --}}
                    <tr>
                        <td align="center" valign="top" bgcolor="{{ $ink }}"
                            style="background-color:{{ $ink }};padding:0;line-height:0;font-size:0;">
                            <img src="{{ $bannerUrl }}"
                                alt="{{ $eventName }} — {{ $eventDates }}, {{ $eventVenue }}"
                                width="640"
                                style="display:block;width:100%;max-width:640px;height:auto;border:0;outline:none;text-decoration:none;" />
                        </td>
                    </tr>

                    {{-- Gold rule under the banner --}}
                    <tr>
                        <td bgcolor="{{ $theme['accent'] }}"
                            style="background-color:{{ $theme['accent'] }};height:3px;line-height:3px;font-size:0;">&nbsp;</td>
                    </tr>

                    {{-- Mode label --}}
                    <tr>
                        <td align="center" class="pad" bgcolor="{{ $inkSoft }}"
                            style="background-color:{{ $inkSoft }};padding:22px 40px 0;">
                            <span style="font-family:Helvetica,Arial,sans-serif;font-size:11px;font-weight:bold;letter-spacing:3px;color:{{ $theme['accent'] }};">
                                {{ $theme['label'] }}
                            </span>
                        </td>
                    </tr>

                    {{-- Greeting --}}
                    <tr>
                        <td align="center" class="pad" bgcolor="{{ $inkSoft }}"
                            style="background-color:{{ $inkSoft }};padding:10px 40px 0;">
                            @php $greetName = trim($recipientName) !== '' ? ucwords(strtolower($recipientName)) : ''; @endphp
                            <span class="h1" style="font-family:Helvetica,Arial,sans-serif;font-size:27px;font-weight:bold;letter-spacing:1px;color:{{ $cream }};line-height:34px;">
                                {{ $greetName !== '' ? $greetName : 'Ma\'am / Sir' }}
                            </span>
                        </td>
                    </tr>

                    {{-- Small gold divider --}}
                    <tr>
                        <td align="center" bgcolor="{{ $inkSoft }}" style="background-color:{{ $inkSoft }};padding:14px 0 0;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td width="70" bgcolor="{{ $theme['accent'] }}"
                                        style="background-color:{{ $theme['accent'] }};height:2px;line-height:2px;font-size:0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ── Copy. The invite carries the team's wording verbatim;
                         the reminder deliberately does not reuse "good news!",
                         which reads as a broken template on deadline day. ── --}}
                    <tr>
                        <td align="left" class="pad" bgcolor="{{ $inkSoft }}"
                            style="background-color:{{ $inkSoft }};padding:22px 40px 0;">
                            <span style="font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:27px;color:{{ $cream }};">
                                @if($mode === 'invite')
                                    <strong style="color:{{ $theme['accent'] }};">Good news!</strong>
                                    <br /><br />
                                    @if($hasPhotos)
                                        We are now preparing for the NATCON event and we saw that we have a picture with you.
                                        Do you want this picture to be used in our event, or do you want to change it?
                                        <br /><br />
                                        If you want to have a new photo for the NATCON, our deadline for collection will be on
                                        <strong style="color:{{ $theme['accent'] }};">the {{ $deadlineDay }}</strong>.
                                    @else
                                        We are now preparing for the NATCON event, but we don&rsquo;t have a photo of you on file yet.
                                        <br /><br />
                                        Please send us the photo you&rsquo;d like us to use. Our deadline for collection will be on
                                        <strong style="color:{{ $theme['accent'] }};">the {{ $deadlineDay }}</strong>.
                                    @endif
                                @else
                                    @if($hasPhotos)
                                        We haven&rsquo;t heard back about the photo we&rsquo;ll be using for you at {{ $eventName }}.
                                        Please let us know whether to keep the picture below, or send us a new one instead.
                                    @else
                                        We still don&rsquo;t have a photo of you on file for {{ $eventName }}.
                                        Please send us the photo you&rsquo;d like us to use.
                                    @endif
                                    <br /><br />
                                    @if($daysRemaining <= 0)
                                        <strong style="color:{{ $theme['accent'] }};">Today is the last day</strong> of photo collection.
                                    @elseif($daysRemaining === 1)
                                        Photo collection closes <strong style="color:{{ $theme['accent'] }};">tomorrow</strong>.
                                    @else
                                        Photo collection closes in
                                        <strong style="color:{{ $theme['accent'] }};">{{ $daysRemaining }} days</strong>, on the {{ $deadlineDay }}.
                                    @endif
                                @endif
                            </span>
                        </td>
                    </tr>

                    {{-- ── The photo(s) on file. This IS the message — without it
                         the recipient has no idea what they're confirming. ── --}}
                    @if($hasPhotos)
                    <tr>
                        <td align="center" class="pad" bgcolor="{{ $inkSoft }}"
                            style="background-color:{{ $inkSoft }};padding:26px 40px 0;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                                style="width:100%;table-layout:fixed;border:1px solid {{ $goldSoft }};">
                                <tr>
                                    @foreach($shown as $photo)
                                    <td class="stackcol" valign="top" align="center" width="{{ intval(100 / $cols) }}%"
                                        bgcolor="{{ $ink }}"
                                        style="background-color:{{ $ink }};padding:0;line-height:0;font-size:0;{{ $loop->last ? '' : 'border-right:1px solid '.$goldSoft.';' }}">
                                        <img class="photo" src="{{ $photo }}" alt="Your {{ $eventName }} photo on file"
                                            width="{{ intval(636 / $cols) }}"
                                            style="display:block;width:100%;height:300px;object-fit:cover;object-position:center top;border:0;outline:none;" />
                                    </td>
                                    @endforeach
                                </tr>
                            </table>
                            <span style="display:block;margin-top:10px;font-family:Helvetica,Arial,sans-serif;font-size:11px;letter-spacing:1px;color:{{ $muted }};">
                                {{ count($photos) > 1 ? 'PHOTOS CURRENTLY ON FILE' : 'PHOTO CURRENTLY ON FILE' }}
                            </span>
                        </td>
                    </tr>
                    @endif

                    {{-- ── CTAs. Both are ordinary links to a PAGE; neither performs
                         the action on click. Outlook SafeLinks, Mimecast and
                         Proofpoint GET every URL in an email within seconds of
                         delivery, from datacenter IPs with no JavaScript — a
                         "Retain" that committed on GET would record hundreds of
                         responses before a human opened the mail, and drop those
                         people from the reminders meant to reach them. ── --}}
                    <tr>
                        <td align="center" class="pad" bgcolor="{{ $inkSoft }}"
                            style="background-color:{{ $inkSoft }};padding:26px 40px 6px;">
                            @if($hasPhotos)
                            {{-- Bulletproof button: a table, so Outlook renders the
                                 fill instead of collapsing to bare text. --}}
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center"
                                style="display:inline-block;margin:0 5px 12px;">
                                <tr>
                                    <td align="center" bgcolor="{{ $theme['accent'] }}"
                                        style="background-color:{{ $theme['accent'] }};padding:15px 30px;">
                                        <a class="cta" href="{{ $retainUrl }}" target="_blank"
                                            style="font-family:Helvetica,Arial,sans-serif;font-size:14px;font-weight:bold;letter-spacing:1.5px;color:{{ $ink }};text-decoration:none;display:inline-block;">
                                            KEEP THIS PHOTO
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center"
                                style="display:inline-block;margin:0 5px 12px;">
                                <tr>
                                    <td align="center" bgcolor="{{ $inkSoft }}"
                                        style="background-color:{{ $inkSoft }};padding:13px 28px;border:2px solid {{ $theme['accent'] }};">
                                        <a class="cta" href="{{ $changeUrl }}" target="_blank"
                                            style="font-family:Helvetica,Arial,sans-serif;font-size:14px;font-weight:bold;letter-spacing:1.5px;color:{{ $theme['accent'] }};text-decoration:none;display:inline-block;">
                                            SEND A NEW PHOTO
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            @else
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center"
                                style="display:inline-block;">
                                <tr>
                                    <td align="center" bgcolor="{{ $theme['accent'] }}"
                                        style="background-color:{{ $theme['accent'] }};padding:15px 34px;">
                                        <a href="{{ $changeUrl }}" target="_blank"
                                            style="font-family:Helvetica,Arial,sans-serif;font-size:14px;font-weight:bold;letter-spacing:1.5px;color:{{ $ink }};text-decoration:none;display:inline-block;">
                                            SEND MY PHOTO
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            @endif
                        </td>
                    </tr>

                    {{-- ── Deadline. The relative count and the absolute date always
                         appear together: email cannot tick, so the number is frozen
                         at send time and can only go stale downward — the date
                         beside it makes that self-correcting. ── --}}
                    <tr>
                        <td align="center" class="pad" bgcolor="{{ $inkSoft }}"
                            style="background-color:{{ $inkSoft }};padding:14px 40px 30px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                                style="width:100%;border-top:1px solid {{ $goldSoft }};border-bottom:1px solid {{ $goldSoft }};">
                                <tr>
                                    <td align="center" bgcolor="{{ $ink }}"
                                        style="background-color:{{ $ink }};padding:18px 16px;">
                                        <span style="display:block;font-family:Helvetica,Arial,sans-serif;font-size:10px;letter-spacing:3px;color:{{ $muted }};">
                                            PHOTO COLLECTION CLOSES
                                        </span>
                                        <span style="display:block;margin-top:8px;font-family:Helvetica,Arial,sans-serif;font-size:26px;font-weight:bold;letter-spacing:2px;color:{{ $theme['accent'] }};">
                                            {{ $countdown }}
                                        </span>
                                        <span style="display:block;margin-top:6px;font-family:Helvetica,Arial,sans-serif;font-size:13px;color:{{ $cream }};">
                                            {{ $deadlineLabel }}
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Event facts --}}
                    <tr>
                        <td align="center" class="pad" bgcolor="{{ $ink }}"
                            style="background-color:{{ $ink }};padding:26px 40px;border-top:1px solid {{ $goldSoft }};">
                            <span style="display:block;font-family:Helvetica,Arial,sans-serif;font-size:17px;font-weight:bold;letter-spacing:2px;color:{{ $gold }};">
                                {{ strtoupper($eventName) }}
                            </span>
                            <span style="display:block;margin-top:10px;font-family:Helvetica,Arial,sans-serif;font-size:14px;letter-spacing:1px;color:{{ $cream }};">
                                {{ strtoupper($eventDates) }}
                            </span>
                            <span style="display:block;margin-top:4px;font-family:Helvetica,Arial,sans-serif;font-size:13px;color:{{ $muted }};">
                                {{ $eventVenue }}
                            </span>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" class="pad" bgcolor="{{ $ink }}"
                            style="background-color:{{ $ink }};padding:0 40px 26px;">
                            <span style="display:block;font-family:Helvetica,Arial,sans-serif;font-size:11px;line-height:19px;color:{{ $muted }};">
                                Leuterio Realty &amp; Brokerage &middot; Filipino Homes &middot; Rent.ph &middot; Homes.ph
                                <br />
                                You&rsquo;re receiving this because you&rsquo;re on the {{ $eventName }} awardee list.
                                <br />
                                This is an automated message — please do not reply.
                                @if($unsubscribeUrl)
                                <br />
                                <a href="{{ $unsubscribeUrl }}" target="_blank" style="color:{{ $muted }};text-decoration:underline;">
                                    Stop receiving NATCON emails
                                </a>
                                @endif
                            </span>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>

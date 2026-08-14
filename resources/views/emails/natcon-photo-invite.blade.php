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

    /**
     * Three cases, not two.
     *
     * "We have your photo" and "we have your photo but you cannot keep it" are
     * different messages, and collapsing them means offering Option 1 to someone
     * a reviewer has already ruled against — which produces a reply and a phone
     * call rather than a photo.
     */
    $mustReplace = $requiresNewPhoto && $hasPhotos;
    $canRetain   = $hasPhotos && ! $requiresNewPhoto;

    // Wording for the ask. Plural only when it is actually plural.
    $needed   = max(0, $requiredCount - $uploadedCount);
    $askCount = $requiredCount === 1
        ? 'a high-resolution portrait photo with a solid background'
        : "{$requiredCount} high-resolution portrait photos with a solid background";
    $partial  = $uploadedCount > 0 && $uploadedCount < $requiredCount;
    // "1 more photo" / "2 more photos". Nobody writes "photo(s)" on purpose.
    $neededLabel = $needed . ' more ' . ($needed === 1 ? 'photo' : 'photos');
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
            {{-- Must stay <= the desktop size above, or the greeting grows on a
                 phone. It was a 27px display heading when this said 24px. --}}
            .h1       { font-size: 19px !important; letter-spacing: 0.3px !important; }
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
        @if($partial)
            {{ $needed }} more photo(s) and you're done.
        @elseif($mustReplace)
            The photo we have on file can't be used. Please send us new ones.
        @elseif($canRetain)
            Keep the photo we have on file, or send us new ones.
        @else
            We still need your photos for the event.
        @endif
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

                    {{-- Greeting. Left-aligned and set at letter-opening size rather
                         than as a centred display heading: it reads as the start of a
                         letter, which is what it is, and it shares a left edge with the
                         body copy directly under it. --}}
                    <tr>
                        <td align="left" class="pad" bgcolor="{{ $inkSoft }}"
                            style="background-color:{{ $inkSoft }};padding:12px 40px 0;">
                            @php
                                /**
                                 * Recipient::displayName() falls back to the EMAIL when no name is
                                 * known, and plenty of awardees aren't on the LR list — so without
                                 * this check the greeting renders
                                 * "Johnrobertmaizo2@Gmail.Com" in 27px bold display type.
                                 * An address is not a name; "Awardee" is the honest fallback.
                                 */
                                $raw       = trim($recipientName);
                                $looksMail = $raw === '' || str_contains($raw, '@');
                                // ⚠️ NOT ucwords(strtolower()). That was harmless while
                                // names were absent, and the moment 285 real ones arrived
                                // it started turning "Jo-ann and Albert Maranian" into
                                // "Jo-Ann And Albert Maranian" — damaging 282 correctly
                                // cased names to fix the 3 that shout. tidyName() only
                                // re-cases a name that is entirely upper case.
                                $greetName = $looksMail ? '' : \App\Natcon\Models\Recipient::tidyName($raw);
                            @endphp
                            <span class="h1" style="font-family:Helvetica,Arial,sans-serif;font-size:20px;font-weight:bold;letter-spacing:0.3px;color:{{ $cream }};line-height:28px;">
                                {{ $greetName !== '' ? 'Dear ' . $greetName . ',' : 'Dear Awardee,' }}
                            </span>
                        </td>
                    </tr>

                    {{-- Small gold divider. Left-aligned with the greeting — centred
                         under left-aligned text left the block looking unrelated to it.
                         Uses the same 40px side padding as .pad so it lines up with the
                         copy on mobile too. --}}
                    <tr>
                        <td align="left" class="pad" bgcolor="{{ $inkSoft }}" style="background-color:{{ $inkSoft }};padding:12px 40px 0;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td width="70" bgcolor="{{ $theme['accent'] }}"
                                        style="background-color:{{ $theme['accent'] }};height:2px;line-height:2px;font-size:0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ── Copy ────────────────────────────────────────────────
                         The invite carries the team's wording. It is NOT one block
                         of text: an awardee with nothing on file cannot be offered
                         "use your existing photo from last year", and telling them
                         we'll fall back to it is worse than saying nothing — so the
                         no-photo variant drops Option 1 and its fallback sentence
                         rather than printing a choice that does not exist.

                         The reminder deliberately reads differently. Re-sending an
                         invite's opening line four days from the deadline reads as a
                         broken template, so it leads with the silence instead. ── --}}
                    <tr>
                        <td align="left" class="pad" bgcolor="{{ $inkSoft }}"
                            style="background-color:{{ $inkSoft }};padding:22px 40px 0;">
                            <span style="font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:27px;color:{{ $cream }};">
                                @if($mode === 'invite')
                                    Our <strong style="color:{{ $cream }};">{{ $eventName }}</strong> is in the works,
                                    and we will need your cooperation for this event to be a success.
                                    <br /><br />
                                    @if($canRetain)
                                        We kindly ask you to confirm your preferred photo:
                                        <br /><br />
                                        <strong style="color:{{ $theme['accent'] }};">Option 1</strong> &nbsp;Use your existing photo from last year.
                                        <br />
                                        <strong style="color:{{ $theme['accent'] }};">Option 2</strong> &nbsp;Submit new photos. Please send
                                        {{ $askCount }}.
                                    @elseif($mustReplace)
                                        {{-- No Option 1: it has already been ruled out, and offering it
                                             anyway is how you get a reply instead of a photo. --}}
                                        The photo we have on file for you cannot be used for the official materials,
                                        so we will need new ones from you.
                                        <br /><br />
                                        Please send {{ $askCount }}.
                                    @else
                                        We kindly ask you to submit new photos.
                                        <br />
                                        Please send {{ $askCount }}.
                                    @endif
                                    <br /><br />
                                    <strong style="color:{{ $theme['accent'] }};">Deadline: {{ $deadlineLabel }}</strong>
                                    <br /><br />
                                    @if($canRetain)
                                        If we do not receive new photos by the deadline, we will use your photo from last year.
                                        If no suitable photo is available, the organizers reserve the discretion to select the
                                        photo to be used for the official materials.
                                    @else
                                        If no suitable photo is available, the organizers reserve the discretion to select the
                                        photo to be used for the official materials.
                                    @endif
                                    <br /><br />
                                    Thank you for your prompt cooperation, and congratulations once again on your
                                    well-deserved recognition!
                                @else
                                    {{-- ⚠️ The partial arm has to come FIRST. Someone who has already
                                         sent two photos being told "we still need your photo" is how a
                                         campaign loses the benefit of the doubt on every later email. --}}
                                    @if($partial)
                                        We&rsquo;ve received
                                        <strong style="color:{{ $theme['accent'] }};">{{ $uploadedCount }} of {{ $requiredCount }}</strong>
                                        photos from you for
                                        <strong style="color:{{ $cream }};">{{ $eventName }}</strong> — thank you.
                                        <br /><br />
                                        {{-- The apostrophe entity lives OUTSIDE {{ }} on purpose:
                                             Blade escapes &, so an entity inside an echo prints
                                             literally as "you&rsquo;re". --}}
                                        {{ $needed === 1 ? 'One more' : $needed . ' more' }} and you&rsquo;re done.
                                        Please keep to a high-resolution portrait photo with a solid background.
                                    @elseif($mustReplace)
                                        We haven&rsquo;t received your new photos for
                                        <strong style="color:{{ $cream }};">{{ $eventName }}</strong>.
                                        The photo we have on file cannot be used for the official materials.
                                        <br /><br />
                                        Please send {{ $askCount }}.
                                    @elseif($canRetain)
                                        We haven&rsquo;t heard back about which photo to use for you at
                                        <strong style="color:{{ $cream }};">{{ $eventName }}</strong>.
                                        <br /><br />
                                        <strong style="color:{{ $theme['accent'] }};">Option 1</strong> &nbsp;Keep your existing photo from last year.
                                        <br />
                                        <strong style="color:{{ $theme['accent'] }};">Option 2</strong> &nbsp;Send {{ $askCount }}.
                                    @else
                                        We still need your photos for
                                        <strong style="color:{{ $cream }};">{{ $eventName }}</strong>.
                                        Please send {{ $askCount }}.
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
                                    <br /><br />
                                    @if($canRetain)
                                        If we do not hear from you by then, we will use your photo from last year.
                                    @else
                                        If no suitable photo is available, the organizers reserve the discretion to select the
                                        photo to be used for the official materials.
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
                            {{-- Shown even when it has been rejected: "we need a new
                                 photo" with nothing to look at reads as arbitrary,
                                 and seeing the old one is what makes the ask land. --}}
                            <span style="display:block;margin-top:10px;font-family:Helvetica,Arial,sans-serif;font-size:11px;letter-spacing:1px;color:{{ $mustReplace ? '#ff8f8f' : $muted }};">
                                @if($mustReplace)
                                    {{ count($photos) > 1 ? 'PHOTOS ON FILE — NOT SUITABLE FOR PRINT' : 'PHOTO ON FILE — NOT SUITABLE FOR PRINT' }}
                                @else
                                    {{ count($photos) > 1 ? 'PHOTOS CURRENTLY ON FILE' : 'PHOTO CURRENTLY ON FILE' }}
                                @endif
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
                            @if($canRetain)
                            {{-- Bulletproof button: a table, so Outlook renders the
                                 fill instead of collapsing to bare text.

                                 Gated on $canRetain, not $hasPhotos: a KEEP button
                                 for a photo we have already rejected would 422 on
                                 the way through, and the awardee would read that as
                                 the site being broken. --}}
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
                                            {{ $requiredCount > 1 ? 'SEND NEW PHOTOS' : 'SEND A NEW PHOTO' }}
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
                                            @if($partial)
                                                SEND {{ strtoupper($neededLabel) }}
                                            @else
                                                {{ $requiredCount > 1 ? 'SEND MY PHOTOS' : 'SEND MY PHOTO' }}
                                            @endif
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
                            {{-- No unsubscribe link, deliberately.

                                 This goes to a known, finite awardee list about an
                                 event they are attending, not a marketing list —
                                 and an awardee who unsubscribes never confirms
                                 their photo, which is the entire point of the send.

                                 If volume ever exceeds ~5,000/day to Gmail, their
                                 bulk-sender rules make one-click unsubscribe
                                 mandatory. At that point BUILD /natcon/unsubscribe
                                 first, then add both the link and the
                                 List-Unsubscribe header. Do not add the header on
                                 its own: Gmail renders its own Unsubscribe button
                                 from it, and pointing that at a 404 is worse than
                                 having none. (It did exactly that until now.) --}}
                            <span style="display:block;font-family:Helvetica,Arial,sans-serif;font-size:11px;line-height:19px;color:{{ $muted }};">
                                Leuterio Realty &amp; Brokerage &middot; Filipino Homes &middot; Rent.ph &middot; Homes.ph
                                <br />
                                You&rsquo;re receiving this because you&rsquo;re on the {{ $eventName }} awardee list.
                                <br />
                                This is an automated message — please do not reply.
                            </span>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>

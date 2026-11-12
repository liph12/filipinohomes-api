{{--
    Bottom action area: info box (ⓘ + title + note) then buttons with icons.
    url: primary button link; button: its label; listingUrl: optional "View Listing";
    secondaryUrl + secondaryLabel: any other outlined second button.
    note: grey second line (null hides it).
--}}
@props([
    'url',
    'button' => 'Reply to Inquiry',
    'listingUrl' => null,
    'secondaryUrl' => null,
    'secondaryLabel' => null,
    'title' => 'To respond to the inquiry, open it in your Filipino Homes account.',
    'note' => "Please don't reply to this email — replies here are not delivered to the client.",
])
@php $ico = fn (string $n) => \App\Support\EmailAsset::url('images/email/'.$n.'.png'); @endphp
<tr>
    <td align="left" class="fh-pad" style="padding:18px 40px 22px;background:#ffffff;">
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;">
            <tr>
                <td valign="middle" width="64" style="width:64px;padding:0;">
                    <div style="width:56px;height:56px;border-radius:16px;background:#e8effc;text-align:center;line-height:0;">
                        <img src="{{ $ico('icon-info') }}" width="28" height="28" alt="" style="display:inline-block;width:28px;height:28px;border:0;margin-top:14px;" />
                    </div>
                </td>
                <td valign="middle" style="padding:0 0 0 12px;font-family:Helvetica,Arial,sans-serif;">
                    <div style="font-size:14px;line-height:20px;color:#0f172a;font-weight:800;margin-bottom:4px;">{{ $title }}</div>
                    @if ($note)
                    <div style="font-size:13px;line-height:19px;color:#64748b;">{{ $note }}</div>
                    @endif
                </td>
            </tr>
        </table>

        <table border="0" cellpadding="0" cellspacing="0" align="center" style="border-collapse:collapse;margin:18px auto 0;">
            <tr>
                <td align="center" valign="middle" class="fh-btn" style="padding:0 8px;">
                    <a href="{{ $url }}" style="display:inline-block;padding:15px 30px;font-size:16px;font-family:Helvetica,Arial,sans-serif;color:#ffffff;background-color:#245ee0;border:1px solid #245ee0;border-radius:12px;text-decoration:none;font-weight:700;line-height:20px;white-space:nowrap;">
                        <img src="{{ $ico('icon-reply-white') }}" width="20" height="20" alt="" style="width:20px;height:20px;border:0;vertical-align:-4px;margin-right:10px;" />{{ $button }}
                    </a>
                </td>
                @php
                    $secUrl = $secondaryUrl ?: $listingUrl;
                    $secLabel = $secondaryUrl ? $secondaryLabel : 'View Listing';
                @endphp
                @if ($secUrl)
                <td align="center" valign="middle" class="fh-btn" style="padding:0 8px;">
                    <a href="{{ $secUrl }}" target="_blank" style="display:inline-block;padding:15px 30px;font-size:16px;font-family:Helvetica,Arial,sans-serif;color:#245ee0;background-color:#ffffff;border:1px solid #245ee0;border-radius:12px;text-decoration:none;font-weight:700;line-height:20px;white-space:nowrap;">
                        <img src="{{ $ico('icon-external') }}" width="20" height="20" alt="" style="width:20px;height:20px;border:0;vertical-align:-4px;margin-right:10px;" />{{ $secLabel }}
                    </a>
                </td>
                @endif
            </tr>
        </table>
    </td>
</tr>

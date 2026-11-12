{{--
    Listing: rounded photo (left) + details as text on white (right).
    $listing keys: name, public_url, image, category, subtype, location, price.
    Title clamps to 2 lines by width; address to 1 line.
--}}
@props(['listing', 'label' => 'Your Listing'])
@php
    // The pill shows the property code when the listing has one ("Listing
    // Code: CEB-16296") so staff and clients can quote it; otherwise the label.
    if (!empty($listing['code'])) {
        $label = 'Listing Code: '.$listing['code'];
    }
@endphp
@if (!empty($listing))
<tr>
    <td align="left" valign="top" class="fh-pad" style="background-color:#ffffff;padding:22px 40px 6px;">
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;">
            <tbody>
                <tr>
                    @if (!empty($listing['image']))
                    {{-- Photo fills its block (cover); the <img> is Outlook's fallback (it ignores background images). --}}
                    <td valign="top" width="190" height="200" class="fh-photo"
                        style="width:190px;height:200px;padding:0;line-height:0;border-radius:16px;background-image:url('{{ $listing['image'] }}');background-size:cover;background-position:center;background-repeat:no-repeat;">
                        <a href="{{ $listing['public_url'] ?? '#' }}" target="_blank" style="display:block;line-height:0;">
                            <img src="{{ $listing['image'] }}" alt="{{ $listing['name'] ?? 'Listing' }}" width="190" height="200"
                                style="display:block;width:190px;height:200px;object-fit:cover;object-position:center;border:0;border-radius:16px;" />
                        </a>
                    </td>
                    @endif
                    <td valign="middle" height="200" class="fh-details" style="height:200px;padding:8px 0 8px {{ !empty($listing['image']) ? '22px' : '0' }};background:#ffffff;">
                        <table border="0" cellpadding="0" cellspacing="0" style="border-collapse:separate;margin-bottom:12px;">
                            <tr>
                                <td style="background:#eef2f8;border-radius:999px;padding:7px 16px 7px 12px;">
                                    <img src="{{ \App\Support\EmailAsset::url('images/email/icon-listing.png') }}" width="20" height="20" alt="" style="width:20px;height:20px;border:0;vertical-align:-5px;margin-right:8px;" /><span style="font-size:13px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#334155;letter-spacing:0.06em;text-transform:uppercase;font-weight:800;">{{ $label }}</span>
                                </td>
                            </tr>
                        </table>
                        @if (!empty($listing['category']) || !empty($listing['subtype']))
                        <span style="display:block;font-size:13px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#2546d8;font-weight:700;text-transform:uppercase;letter-spacing:0.03em;margin-bottom:8px;">
                            {{ trim(($listing['category'] ?? '') . (!empty($listing['category']) && !empty($listing['subtype']) ? ' · ' : '') . ($listing['subtype'] ?? '')) }}
                        </span>
                        @endif
                        @if (!empty($listing['name']))
                        <div title="{{ $listing['name'] }}" style="max-height:54px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;margin-bottom:8px;">
                            <a href="{{ $listing['public_url'] ?? '#' }}" target="_blank" style="font-size:20px;line-height:27px;font-family:Helvetica,Arial,sans-serif;color:#0f172a;font-weight:800;text-decoration:none;">{{ $listing['name'] }}</a>
                        </div>
                        @endif
                        @if (!empty($listing['location']))
                        <div title="{{ $listing['location'] }}" style="font-size:14px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#475467;margin-bottom:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><img src="{{ \App\Support\EmailAsset::url('images/email/icon-pin.png') }}" width="16" height="16" alt="" style="width:16px;height:16px;border:0;vertical-align:-3px;margin-right:6px;" />{{ \Illuminate\Support\Str::limit($listing['location'], 38, '…') }}</div>
                        @endif
                        @if (!empty($listing['price']))
                        <span style="display:block;font-size:22px;line-height:28px;font-family:Helvetica,Arial,sans-serif;color:#2f8a3e;font-weight:800;">{{ $listing['price'] }}</span>
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
    </td>
</tr>
@endif

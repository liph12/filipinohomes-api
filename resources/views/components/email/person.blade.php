{{--
    A person card (the client "From", or the "Listing Owner"): avatar | divider |
    bold name, email, phone + WhatsApp (official logo) on one line.
    Falls back to an initials avatar in $avatarBg when there's no photo.
--}}
@props(['label' => 'From', 'icon' => 'icon-user', 'name' => null, 'email' => null, 'mobile' => null, 'whatsapp' => null, 'avatar' => null, 'avatarBg' => '245ee0'])
@php
    $seed = trim((string) $name) !== '' ? $name : 'FH';
    $avatarSrc = !empty($avatar) ? $avatar
        : 'https://ui-avatars.com/api/?name='.urlencode($seed).'&background='.$avatarBg.'&color=fff&size=128&bold=true&rounded=true';
    $ico = fn (string $n) => asset('images/email/'.$n.'.png');
@endphp
<tr>
    <td align="left" valign="top" class="fh-pad" style="background-color:#ffffff;padding:22px 40px 8px;">
        <x-email.section-label :icon="$icon" :text="$label" />
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:separate;border:1px solid #e4e9f0;border-radius:16px;background:#ffffff;">
            <tbody>
                <tr>
                    <td valign="middle" width="96" style="width:96px;padding:16px 0 16px 20px;">
                        <img src="{{ $avatarSrc }}" alt="{{ $name }}" width="64" height="64" style="display:block;width:64px;height:64px;border-radius:50%;border:0;background:#e5e7eb;" />
                    </td>
                    <td valign="middle" width="1" style="width:1px;padding:16px 0;"><div style="width:1px;height:56px;background:#e4e9f0;"></div></td>
                    <td valign="middle" style="padding:16px 20px;">
                        @if (!empty($name))
                        <div style="font-size:17px;line-height:22px;font-family:Helvetica,Arial,sans-serif;color:#0f172a;font-weight:800;margin-bottom:6px;">{{ ucwords(strtolower($name)) }}</div>
                        @endif
                        <div style="font-size:14px;line-height:24px;font-family:Helvetica,Arial,sans-serif;color:#475467;">
                            @if (!empty($email))
                            <img src="{{ $ico('icon-mail') }}" width="16" height="16" alt="" style="width:16px;height:16px;border:0;vertical-align:-3px;margin-right:6px;" /><a href="mailto:{{ $email }}" style="color:#1d4ed8;text-decoration:none;">{{ $email }}</a>
                            @endif
                            @if ((!empty($mobile) || !empty($whatsapp)) && !empty($email))
                            <br />
                            @endif
                            @if (!empty($mobile))
                            <img src="{{ $ico('icon-phone') }}" width="16" height="16" alt="" style="width:16px;height:16px;border:0;vertical-align:-3px;margin-right:6px;" /><a href="tel:{{ $mobile }}" style="color:#334155;text-decoration:none;">{{ $mobile }}</a>
                            @endif
                            @if (!empty($whatsapp))
                            <img src="{{ $ico('icon-whatsapp') }}" width="16" height="16" alt="WhatsApp" style="width:16px;height:16px;border:0;vertical-align:-3px;margin:0 6px 0 {{ !empty($mobile) ? '18px' : '0' }};" /><a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $whatsapp) }}" target="_blank" style="color:#334155;text-decoration:none;">{{ $whatsapp }}</a>
                            @endif
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </td>
</tr>

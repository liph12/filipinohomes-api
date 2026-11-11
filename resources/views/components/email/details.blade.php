{{-- Label/value rows in a light card (e.g. Contact Us: inquiry type, subject, phone).
     :rows="['Inquiry Type' => $type, 'Subject' => $subject]" — empty values are skipped. --}}
@props(['rows' => [], 'label' => 'Details', 'icon' => 'icon-info'])
@php $rows = array_filter($rows, fn ($v) => filled($v)); @endphp
@if ($rows !== [])
<tr>
    <td align="left" valign="top" class="fh-pad" style="background-color:#ffffff;padding:22px 40px 6px;">
        <x-email.section-label :icon="$icon" :text="$label" />
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:separate;border:1px solid #e4e9f0;border-radius:16px;background:#ffffff;">
            <tbody>
                @foreach ($rows as $k => $v)
                <tr>
                    <td valign="top" width="140" style="width:140px;padding:{{ $loop->first ? '16px' : '8px' }} 0 {{ $loop->last ? '16px' : '8px' }} 20px;font-size:12px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#667085;letter-spacing:0.06em;text-transform:uppercase;font-weight:700;">{{ $k }}</td>
                    <td valign="top" style="padding:{{ $loop->first ? '16px' : '8px' }} 20px {{ $loop->last ? '16px' : '8px' }} 0;font-size:15px;line-height:20px;font-family:Helvetica,Arial,sans-serif;color:#0f172a;font-weight:600;">{{ $v }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </td>
</tr>
@endif

{{-- Uppercase section heading with an icon on its left ("MESSAGE", "FROM"…). --}}
@props(['icon', 'text'])
<table border="0" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:12px;">
    <tr>
        <td valign="middle" style="padding:0 10px 0 0;line-height:0;"><img src="{{ asset('images/email/'.$icon.'.png') }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;border:0;" /></td>
        <td valign="middle" style="font-size:13px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#475467;letter-spacing:0.08em;text-transform:uppercase;font-weight:800;">{{ $text }}</td>
    </tr>
</table>

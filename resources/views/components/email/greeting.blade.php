{{-- "Hello {name}," (bold) + the paragraph in $slot. --}}
@props(['name'])
<tr>
    <td align="left" valign="top" class="fh-pad" style="background-color:#ffffff;padding:26px 40px 8px;">
        <div style="font-size:19px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#0f172a;font-weight:700;margin:0 0 6px;">
            Hello {{ $name }},
        </div>
        <span style="font-size:16px;line-height:26px;font-family:Helvetica,Arial,sans-serif;color:#475467;display:block;">{{ $slot }}</span>
    </td>
</tr>

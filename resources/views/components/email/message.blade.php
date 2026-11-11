{{-- The client's message as a light-blue chat bubble (icon is on the section label). --}}
@props(['message', 'label' => 'Message'])
@if (!empty($message))
<tr>
    <td align="left" valign="top" class="fh-pad" style="background-color:#ffffff;padding:22px 40px 6px;">
        <x-email.section-label icon="icon-message" :text="$label" />
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:separate;background:#eef3fb;border-radius:16px 16px 16px 4px;">
            <tbody>
                <tr>
                    <td valign="middle" style="padding:16px 22px;font-size:15px;line-height:24px;font-family:Helvetica,Arial,sans-serif;color:#0f172a;white-space:pre-line;">{{ $message }}</td>
                </tr>
            </tbody>
        </table>
    </td>
</tr>
@endif

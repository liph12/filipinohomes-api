{{-- The client's message as a light-blue chat bubble (icon is on the section label).
     Lines like "💬 0917…" (the Inquiry AI Assistant's WhatsApp line) show the
     official WhatsApp logo + a wa.me link; "📞/📱 <number>" become tap-to-call.
     Every piece of text is escaped — only those fixed tags are added. --}}
@props(['message', 'label' => 'Message'])
@if (!empty($message))
@php
    $html = collect(preg_split('/\R/u', (string) $message))->map(function (string $line) {
        if (! preg_match('/^(💬|📞|📱) ([+()\d][\d\s()+-]{5,})$/u', $line, $m)) {
            return e($line);
        }
        if ($m[1] === '💬') {
            return '<img src="'.e(\App\Support\EmailAsset::url('images/email/icon-whatsapp.png')).'" width="16" height="16" alt="WhatsApp" style="width:16px;height:16px;border:0;vertical-align:-3px;margin-right:6px;" />'
                .'<a href="'.e(\App\Support\PhoneLink::whatsapp($m[2])).'" target="_blank" style="color:#1d4ed8;text-decoration:none;">'.e($m[2]).'</a>';
        }

        return e($m[1]).' <a href="'.e(\App\Support\PhoneLink::tel($m[2])).'" style="color:#1d4ed8;text-decoration:none;">'.e($m[2]).'</a>';
    })->implode("\n");
@endphp
<tr>
    <td align="left" valign="top" class="fh-pad" style="background-color:#ffffff;padding:22px 40px 6px;">
        <x-email.section-label icon="icon-message" :text="$label" />
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:separate;background:#eef3fb;border-radius:16px 16px 16px 4px;">
            <tbody>
                <tr>
                    <td valign="middle" style="padding:16px 22px;font-size:15px;line-height:24px;font-family:Helvetica,Arial,sans-serif;color:#0f172a;white-space:pre-line;">{!! $html !!}</td>
                </tr>
            </tbody>
        </table>
    </td>
</tr>
@endif

{{-- Notice box with a coloured left bar (team info / warnings). tone: amber, red, purple. --}}
@props(['tone' => 'amber', 'title'])
@php
    $t = [
        'amber' => ['bg' => '#fef3c7', 'bar' => '#b45309', 'title' => '#92400e', 'text' => '#1f2937'],
        'red' => ['bg' => '#fee2e2', 'bar' => '#b91c1c', 'title' => '#991b1b', 'text' => '#7f1d1d'],
        'purple' => ['bg' => '#ede9fe', 'bar' => '#6d28d9', 'title' => '#5b21b6', 'text' => '#1f2937'],
    ][$tone] ?? ['bg' => '#f1f5f9', 'bar' => '#64748b', 'title' => '#334155', 'text' => '#1f2937'];
@endphp
<tr>
    <td align="left" valign="top" class="fh-pad" style="background-color:#ffffff;padding:18px 40px 0;">
        <div style="padding:14px 18px;background:{{ $t['bg'] }};border-left:4px solid {{ $t['bar'] }};border-radius:8px;">
            <span style="font-size:11px;line-height:16px;font-family:Helvetica,Arial,sans-serif;color:{{ $t['title'] }};letter-spacing:0.06em;text-transform:uppercase;font-weight:800;display:block;margin-bottom:4px;">{{ $title }}</span>
            <span style="font-size:14px;line-height:21px;font-family:Helvetica,Arial,sans-serif;color:{{ $t['text'] }};display:block;">{{ $slot }}</span>
        </div>
    </td>
</tr>

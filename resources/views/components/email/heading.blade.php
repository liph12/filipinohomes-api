{{-- Centered coloured headline at the top of the body. color: green (default), amber, purple, teal, blue. --}}
@props(['color' => 'green'])
@php
    $hex = ['green' => '#15803d', 'amber' => '#b45309', 'purple' => '#6d28d9', 'teal' => '#0f766e', 'blue' => '#245ee0'][$color] ?? $color;
@endphp
<tr>
    <td align="center" valign="top" class="fh-pad" style="background-color:#ffffff;padding:24px 40px 0;">
        <span style="display:inline-block;font-size:22px;line-height:28px;font-family:Helvetica,Arial,sans-serif;font-weight:800;color:{{ $hex }};">{{ $slot }}</span>
    </td>
</tr>

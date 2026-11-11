{{--
    Filipino Homes email shell: logo header → body ($slot) → footer.
    Usage:
      <x-email.layout footer="You're receiving this because …">
          <x-email.heading>…</x-email.heading>
          …
      </x-email.layout>
    Everything is inline-styled tables (Gmail/Outlook-safe); the <style> block
    only adds phone tweaks for clients that support it.
--}}
@props(['footer' => null])
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @media only screen and (max-width: 600px) {
            .fh-pad { padding-left: 20px !important; padding-right: 20px !important; }
            .fh-photo, .fh-photo img { display: block !important; width: 100% !important; height: 200px !important; }
            .fh-details { display: block !important; width: 100% !important; height: auto !important; padding: 14px 0 0 0 !important; }
            .fh-btn { display: block !important; padding: 0 0 10px 0 !important; }
        }
    </style>
</head>

<body style="margin:0;padding:32px 16px;background-color:#edf2f7;">
    <center>
        <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;width:100%;margin:0;padding:0;">
            <tbody>
                <tr>
                    <td align="center" valign="top" style="margin:0;padding:0;">
                        <table border="0" cellpadding="0" cellspacing="0" width="100%"
                            style="border-collapse:collapse;width:100%;max-width:640px;background-color:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 48px rgba(15, 23, 42, 0.12);">
                            <tbody>
                                {{-- LOGO HEADER — white, full-colour logo (PNG for Outlook), thin inset divider. --}}
                                <tr>
                                    <td align="center" valign="top" class="fh-pad" style="background:#ffffff;padding:22px 40px 0;">
                                        <img align="center" alt="Filipino Homes" src="https://filipinohomes.com/images/fh-logo-black.png"
                                            width="300" style="max-width:300px;width:100%;display:inline !important;border:0;height:auto;" />
                                        <div style="height:1px;line-height:1px;font-size:0;background:#e9eef5;margin:20px 0 0;">&nbsp;</div>
                                    </td>
                                </tr>

                                {{ $slot }}

                                @if ($footer)
                                <tr>
                                    <td align="center" class="fh-pad" style="padding:14px 40px 18px;background:#ffffff;border-top:1px solid #eef2f6;">
                                        <span style="font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:#94a3b8;">{{ $footer }}</span>
                                    </td>
                                </tr>
                                @endif
                            </tbody>
                        </table>
                    </td>
                </tr>
            </tbody>
        </table>
    </center>
</body>

</html>

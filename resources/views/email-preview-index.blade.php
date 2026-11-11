{{--
    Local email preview viewer (APP_DEBUG only — see routes/web.php).
    Group tabs → buttons for each email in the group → the selected email below
    in a frame. The selection lives in the URL hash (#group/type) so reloads and
    shared links keep it. Each email is still at /preview/email/{type}.
--}}
@php
    $groups = [
        'Inquiry' => [
            'inquiry-admin' => 'Admin — pending review',
            'inquiry-admin-unassigned' => 'Admin — agent not on a team',
            'inquiry-team-leader' => 'Team leader',
            'inquiry-agent' => 'Agent — assigned',
            'inquiry-agent-profile' => 'Agent profile',
            'inquiry-agent-page' => 'Website Builder',
            'notification' => 'New message (chat reminder)',
        ],
        'Contact Us' => [
            'contact-us' => 'Contact Us',
            'inquiry' => 'Get In Touch',
            'inquiry-maintenance' => 'Maintenance',
        ],
        'ATS' => [
            'ats-approved' => 'Approved',
            'ats-pending' => 'Pending',
            'ats-rejected' => 'Rejected',
            'ats-expired' => 'Expired',
            'ats-expiring-soon' => 'Expiring soon',
            'ats-expiry-expired' => 'Expiry — expired',
        ],
        'Audit' => [
            'flagged' => 'Listing flagged',
            'verified' => 'Listing verified',
        ],
        'Reports' => [
            'boss-report' => 'Site activity report',
            'website-analytics' => 'Website analytics',
            'birthdays' => 'Birthdays (daily digest)',
            'birthday-greeting' => 'Agent birthday greeting',
        ],
        'Account' => [
            'otp' => 'Login OTP',
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email previews</title>
    <style>
        :root { --blue:#245ee0; --ink:#0f172a; --muted:#64748b; --line:#e4e9f0; --bg:#edf2f7; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:Helvetica,Arial,sans-serif; background:var(--bg); color:var(--ink); }
        header { position:sticky; top:0; z-index:5; background:#fff; border-bottom:1px solid var(--line); padding:14px 20px 12px; }
        h1 { margin:0; font-size:18px; }
        .sub { margin:2px 0 12px; font-size:13px; color:var(--muted); }
        .row { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .tabs button { border:0; background:transparent; padding:8px 14px; border-radius:999px; font:700 14px Helvetica,Arial,sans-serif; color:var(--muted); cursor:pointer; }
        .tabs button.on { background:var(--blue); color:#fff; }
        .tabs button .n { font-weight:600; opacity:.7; margin-left:4px; }
        .emails { margin-top:10px; padding-top:10px; border-top:1px solid var(--line); }
        .emails button { border:1px solid var(--line); background:#fff; padding:7px 12px; border-radius:10px; font:600 13px Helvetica,Arial,sans-serif; color:var(--ink); cursor:pointer; }
        .emails button.on { border-color:var(--blue); color:var(--blue); background:#eef3fd; }
        .emails .open { margin-left:auto; font-size:13px; color:var(--blue); text-decoration:none; font-weight:600; }
        main { padding:20px; }
        iframe { display:block; width:100%; max-width:760px; margin:0 auto; border:0; border-radius:12px; background:var(--bg); min-height:600px; }
    </style>
</head>
<body>
    <header>
        <h1>Email previews</h1>
        <p class="sub">Each email rendered with sample data — nothing is sent. Edit a Blade template, then click the email again (or reload) to see it.</p>
        <div class="row tabs" id="tabs">
            @foreach ($groups as $group => $items)
                <button type="button" data-group="{{ $group }}">{{ $group }}<span class="n">{{ count($items) }}</span></button>
            @endforeach
        </div>
        <div class="row emails" id="emails"></div>
    </header>
    <main>
        <iframe id="frame" title="Email preview"></iframe>
    </main>

    <script>
        const groups = @json($groups);
        const tabs = document.getElementById('tabs');
        const emails = document.getElementById('emails');
        const frame = document.getElementById('frame');

        function select(group, type) {
            const items = groups[group] || groups[Object.keys(groups)[0]];
            group = groups[group] ? group : Object.keys(groups)[0];
            type = items[type] ? type : Object.keys(items)[0];

            tabs.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.group === group));
            emails.innerHTML = '';
            for (const [key, label] of Object.entries(items)) {
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = label;
                b.className = key === type ? 'on' : '';
                b.onclick = () => select(group, key);
                emails.appendChild(b);
            }
            const open = document.createElement('a');
            open.className = 'open';
            open.href = '/preview/email/' + type;
            open.target = '_blank';
            open.textContent = 'Open in new tab ↗';
            emails.appendChild(open);

            history.replaceState(null, '', '#' + encodeURIComponent(group) + '/' + type);
            frame.src = '/preview/email/' + type + '?t=' + Date.now(); // always re-render the latest template
        }

        // Fit the frame to the email's height (same origin, so it can be measured).
        frame.addEventListener('load', () => {
            try { frame.style.height = frame.contentDocument.documentElement.scrollHeight + 'px'; } catch (e) {}
        });

        tabs.addEventListener('click', e => {
            const b = e.target.closest('button');
            if (b) select(b.dataset.group);
        });

        const [g, t] = decodeURIComponent(location.hash.slice(1)).split('/');
        select(g, t);
    </script>
</body>
</html>

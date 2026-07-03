<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Email previews</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; max-width: 720px; margin: 64px auto; padding: 0 24px; color: #1f2937; }
        h1   { font-size: 24px; margin-bottom: 8px; }
        p    { color: #6b7280; }
        ul   { list-style: none; padding: 0; }
        li   { margin: 12px 0; }
        a    { display: inline-block; padding: 12px 18px; border-radius: 10px; background: #245ee0; color: #fff; text-decoration: none; font-weight: 600; }
        a:hover { background: #1e4ec0; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 0.85em; }
    </style>
</head>
<body>
    <h1>Email previews</h1>
    <p>Renders each Mailable with dummy data. Refresh after editing the blade template — no mail is sent.</p>
    <ul>
        <li><a href="/preview/email/flagged">⚑ Listing Flagged</a> &nbsp; <code>ListingFlaggedMailer</code></li>
        <li><a href="/preview/email/verified">✓ Listing Verified</a> &nbsp; <code>ListingVerifiedMailer</code></li>
        <li><a href="/preview/email/ats-approved">✓ ATS Updated — Approved</a> &nbsp; <code>AtsStatusUpdatedMailer</code></li>
        <li><a href="/preview/email/ats-pending">⏳ ATS Updated — Pending</a> &nbsp; <code>AtsStatusUpdatedMailer</code></li>
        <li><a href="/preview/email/ats-expired">⚠ ATS Updated — Expired</a> &nbsp; <code>AtsStatusUpdatedMailer</code></li>
        <li><a href="/preview/email/ats-rejected">✕ ATS Updated — Rejected</a> &nbsp; <code>AtsStatusUpdatedMailer</code></li>
        <li><a href="/preview/email/ats-expiring-soon">⏳ ATS Expiring Soon (1 week)</a> &nbsp; <code>AtsExpiryMailer</code></li>
        <li><a href="/preview/email/ats-expiry-expired">⚠ ATS Expired</a> &nbsp; <code>AtsExpiryMailer</code></li>
        <li><a href="/preview/email/inquiry">✉ New Inquiry</a> &nbsp; <code>InquiryMailer</code> &nbsp; <small>(home / maintenance)</small></li>
        <li><a href="/preview/email/contact-us">📨 Contact Us Submission</a> &nbsp; <code>ContactUsMailer</code> &nbsp; <small>(contact page — rich form)</small></li>
        <li><a href="/preview/email/notification">💬 Message Notification</a> &nbsp; <code>MessageNotificationMailer</code> &nbsp; <small>(legacy / reply-path)</small></li>
        <li><a href="/preview/email/inquiry-admin">🏠 Listing Inquiry — Admin View</a> &nbsp; <code>perspective=admin</code></li>
        <li><a href="/preview/email/inquiry-admin-unassigned">🚨 Listing Inquiry — Admin View (Agent Unassigned)</a> &nbsp; <code>perspective=admin, teamName=null</code></li>
        <li><a href="/preview/email/inquiry-team-leader">👥 Listing Inquiry — Team Leader View</a> &nbsp; <code>perspective=team_leader</code></li>
        <li><a href="/preview/email/inquiry-agent">✅ Listing Inquiry — Agent (Assigned)</a> &nbsp; <code>perspective=agent</code></li>
        <li><a href="/preview/email/otp">🔐 Login OTP</a> &nbsp; <code>LoginOtpMailer</code></li>
    </ul>
</body>
</html>

<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 32px; }
        .header { background-color: #1B3A6B; padding: 24px; border-radius: 8px 8px 0 0; }
        .header h1 { color: white; margin: 0; font-size: 20px; }
        .body { background: #f8f9fa; padding: 32px; border-radius: 0 0 8px 8px; }
        .message-box { background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #1B3A6B; margin: 20px 0; }
        .footer { margin-top: 16px; font-size: 13px; color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>New Inquiry — Filipino Homes</h1>
        </div>
        <div class="body">
            <p>Dear Admin,</p>

            <div class="message-box">
                <p>{{ $clientMessage }}</p>
            </div>

            <div class="footer">
                <p>From:<br>
                <strong>{{ $clientName }}</strong><br>
                <a href="mailto:{{ $clientEmail }}">{{ $clientEmail }}</a></p>
            </div>
        </div>
    </div>
</body>
</html>
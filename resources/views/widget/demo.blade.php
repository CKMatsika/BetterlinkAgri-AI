<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BetterlinkAgr AI — Zimbabwe Agronomy & Sales Web Widget</title>
    <link rel="stylesheet" href="/widget/betterlink-chat-widget.css">
</head>
<body style="margin:0;background:#f1f5f9;font-family:system-ui,sans-serif;padding:32px;">
    <div style="max-width:640px;">
        <h1 style="color:#14532d;margin-bottom:8px;">BetterlinkAgr AI — Web Chat Widget Host Demo</h1>
        <p style="color:#334155;line-height:1.6;">
            Use the floating widget in the bottom-right corner to test photo diagnosis, 1-at-a-time clarifying questions,
            deterministic application rates (per plant, Pfumvudza station, and hectare), and the Location-Aware Outlet Finder.
        </p>
    </div>

    <div id="betterlink-chat-root"></div>

    <script src="/widget/betterlink-chat-widget.js"></script>
    <script>
        new window.BetterlinkChatWidget({
            tenantSlug: 'farmserve-zimbabwe',
            phone: '+263772999000'
        });
    </script>
</body>
</html>

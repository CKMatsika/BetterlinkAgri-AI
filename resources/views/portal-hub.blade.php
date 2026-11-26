<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BetterlinkAgri AI — Zimbabwe Agri-Ecosystem Portal Hub</title>
    <style>
        :root {
            --forest: #14532d;
            --forest-dark: #052e16;
            --emerald: #15803d;
            --mint: #dcfce7;
            --amber: #b45309;
            --amber-bg: #fef3c7;
            --slate-900: #0f172a;
            --slate-600: #475569;
            --slate-200: #e2e8f0;
            --bg: #f8fafc;
            --card: #ffffff;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--slate-900);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        header {
            background: linear-gradient(135deg, var(--forest-dark) 0%, var(--forest) 60%, var(--emerald) 100%);
            color: #fff;
            padding: 3rem 1.5rem 2.75rem;
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
            width: 100%;
        }
        .header-top {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            font-weight: 800;
            font-size: 1.35rem;
            letter-spacing: -0.02em;
        }
        .brand-badge {
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.25);
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.24);
            color: #fff;
            text-decoration: none;
            padding: 0.35rem 0.8rem;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 600;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #4ade80;
            display: inline-block;
        }
        .hero-title {
            font-size: clamp(1.75rem, 3.5vw, 2.5rem);
            font-weight: 800;
            letter-spacing: -0.03em;
            margin-bottom: 0.6rem;
        }
        .hero-sub {
            max-width: 720px;
            color: #dcfce7;
            font-size: 1.02rem;
        }
        main {
            flex: 1;
            padding: 2.25rem 1.5rem 3rem;
        }
        .section-label {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--emerald);
            margin-bottom: 0.85rem;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
            gap: 1.15rem;
            margin-bottom: 2.25rem;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--slate-200);
            border-radius: 14px;
            padding: 1.4rem;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
            transition: transform 0.14s ease, box-shadow 0.14s ease, border-color 0.14s ease;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px -8px rgba(15, 23, 42, 0.12);
            border-color: var(--emerald);
        }
        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }
        .icon-box {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            font-size: 1.25rem;
            background: var(--mint);
            color: var(--forest);
            flex-shrink: 0;
        }
        .icon-box.ops {
            background: var(--amber-bg);
            color: var(--amber);
        }
        .pill {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            background: #f1f5f9;
            color: var(--slate-600);
        }
        .card h3 {
            font-size: 1.12rem;
            font-weight: 700;
            color: var(--slate-900);
        }
        .card p {
            font-size: 0.92rem;
            color: var(--slate-600);
            flex: 1;
        }
        .card-cta {
            margin-top: 0.4rem;
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--emerald);
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        footer {
            border-top: 1px solid var(--slate-200);
            background: #fff;
            padding: 1.25rem 1.5rem;
            font-size: 0.85rem;
            color: var(--slate-600);
        }
        .footer-inner {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 0.75rem;
            align-items: center;
        }
        .footer-links a {
            color: var(--forest);
            text-decoration: none;
            font-weight: 600;
            margin-left: 1rem;
        }
        .footer-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-top">
                <div class="brand">
                    <span>🌱 BetterlinkAgri AI</span>
                    <span class="brand-badge">Zimbabwe v1.0</span>
                </div>
                <a href="/api/v1/health" class="status-pill" id="health-pill">
                    <span class="status-dot" id="health-dot"></span>
                    <span id="health-text">System Online</span>
                </a>
            </div>
            <h1 class="hero-title">Agri-Ecosystem Portal Hub</h1>
            <p class="hero-sub">
                AI-powered crop &amp; livestock diagnostics, deterministic farm budgeting, spatial input marketplace,
                service bookings, and consent-gated agri-finance — built for Zimbabwe's agricultural value chain.
            </p>
        </div>
    </header>

    <main>
        <div class="container">
            <div class="section-label">Farmer Portals</div>
            <div class="grid">
                <a href="/widget-demo" class="card">
                    <div class="card-head">
                        <div class="icon-box">📸</div>
                        <span class="pill">AI Mudhumeni</span>
                    </div>
                    <h3>Crop &amp; Livestock AI Doctor</h3>
                    <p>Upload field photos, answer guided diagnostic questions, check safe spray weather, and get verified product dosage calculations.</p>
                    <span class="card-cta">Open AI Chat Widget →</span>
                </a>

                <a href="/planner" class="card">
                    <div class="card-head">
                        <div class="icon-box">📊</div>
                        <span class="pill">Phone OTP</span>
                    </div>
                    <h3>Farm Planner &amp; Budgeting</h3>
                    <p>Build resumable enterprise plans (Broilers, Layers, Maize &amp; more), run deterministic What-If scale projections, and export PDF schedules.</p>
                    <span class="card-cta">Open Farm Planner →</span>
                </a>

                <a href="/marketplace" class="card">
                    <div class="card-head">
                        <div class="icon-box">🛒</div>
                        <span class="pill">Spatial Rings</span>
                    </div>
                    <h3>Input Marketplace</h3>
                    <p>Broadcast input requests to nearby verified agro-dealers across 10/25/50 km PostGIS rings, compare ranked offers, and confirm safe substitutes.</p>
                    <span class="card-cta">Open Marketplace →</span>
                </a>
            </div>

            <div class="section-label">Retailer, Supply Chain &amp; Platform Operations</div>
            <div class="grid">
                <a href="/outlet" class="card">
                    <div class="card-head">
                        <div class="icon-box ops">🏪</div>
                        <span class="pill">Live Alerts</span>
                    </div>
                    <h3>Outlet Staff Portal</h3>
                    <p>Receive real-time nearby farmer stock requests, submit competitive offers, update stock levels, and fulfil confirmed orders.</p>
                    <span class="card-cta">Open Outlet Portal →</span>
                </a>

                <a href="/admin" class="card">
                    <div class="card-head">
                        <div class="icon-box ops">🏢</div>
                        <span class="pill">Multi-Tenant</span>
                    </div>
                    <h3>Organisation Admin Panel</h3>
                    <p>Manage outlets, verified product catalogues, agronomy documents, sales attribution, demand aggregates, and supply-chain stock signals.</p>
                    <span class="card-cta">Open Tenant Admin →</span>
                </a>

                <a href="/platform" class="card">
                    <div class="card-head">
                        <div class="icon-box ops">🛡️</div>
                        <span class="pill">Super-Admin</span>
                    </div>
                    <h3>Platform Governance</h3>
                    <p>Oversee tenants, seller verifications, dispute resolutions, Agritex k-anonymity analytics, and CDPA compliance audit logs.</p>
                    <span class="card-cta">Open Platform Panel →</span>
                </a>
            </div>
        </div>
    </main>

    <footer>
        <div class="container footer-inner">
            <span>BetterlinkAgri AI • Powered by Laravel, PostgreSQL + PostGIS + pgvector</span>
            <div class="footer-links">
                <a href="/api/v1/health">API Health</a>
                <a href="/planner">Planner</a>
                <a href="/marketplace">Marketplace</a>
                <a href="/widget-demo">AI Doctor</a>
            </div>
        </div>
    </footer>

    <script>
        fetch('/api/v1/health')
            .then(r => r.json())
            .then(data => {
                const text = document.getElementById('health-text');
                const dot = document.getElementById('health-dot');
                if (data && data.ok) {
                    text.textContent = 'PostgreSQL + PostGIS + pgvector OK';
                    dot.style.background = '#4ade80';
                } else {
                    text.textContent = 'Check /api/v1/health';
                    dot.style.background = '#fbbf24';
                }
            })
            .catch(() => {});
    </script>
</body>
</html>

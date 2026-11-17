<?php

declare(strict_types=1);

define('LARAVEL_START', microtime(true));

// Serve static assets from /public when using PHP's built-in development server
if (PHP_SAPI === 'cli-server') {
    $urlPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $staticFile = __DIR__ . $urlPath;
    if ($urlPath !== '/' && is_file($staticFile)) {
        return false;
    }
}

// If Composer dependencies are installed, boot the full Laravel 11 application kernel
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $app->handleRequest(\Illuminate\Http\Request::capture());
    exit;
}

// ==============================================================================
// ZERO-DEPENDENCY INTERACTIVE PREVIEW ENGINE (When running before composer install)
// ==============================================================================
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$stateDir = __DIR__ . '/../storage/framework';
if (!is_dir($stateDir)) {
    @mkdir($stateDir, 0777, true);
}
$stateFile = $stateDir . '/preview_sessions.json';
$sessions = file_exists($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];

if ($uri === '/api/v1/web-chat/messages' && $method === 'POST') {
    header('Content-Type: application/json');
    $phone = trim((string) ($_POST['phone'] ?? '+263772999000'));
    $lang = trim((string) ($_POST['language'] ?? 'en'));
    $text = trim((string) ($_POST['message_text'] ?? ''));
    $hasPhoto = !empty($_FILES['photo']['tmp_name']);
    $hasGps = isset($_POST['latitude'], $_POST['longitude']);

    $session = $sessions[$phone] ?? [
        'conversation_id' => 'conv-zw-' . substr(md5($phone), 0, 8),
        'state' => 'CONSENT_CHECK',
        'mode' => 'ai_active',
        'consented' => false,
        'context_chip' => 'General Agronomy / NR IIa',
        'diagnosis_type' => 'pest',
        'question_step' => 0,
    ];

    $upper = strtoupper($text);

    // 1. Check Opt-Out Keywords (STOP, MIRA, MANA)
    if (in_array($upper, ['STOP', 'MIRA', 'MANA'], true)) {
        $session['state'] = 'OPTED_OUT';
        $session['consented'] = false;
        $sessions[$phone] = $session;
        file_put_contents($stateFile, json_encode($sessions, JSON_PRETTY_PRINT));

        echo json_encode([
            'data' => [
                'conversation_id' => $session['conversation_id'],
                'state' => 'OPTED_OUT',
                'mode' => 'ai_active',
                'language' => $lang,
                'context_chip' => 'Opted Out (CDPA)',
                'reply_text' => 'You have been opted out (STOP / MIRA / MANA). You will receive no further messages. Reply START to re-enable.',
                'buttons' => [['id' => 'START', 'title' => 'START (Re-enable)']],
                'rich_cards' => [],
            ],
        ]);
        exit;
    }

    if ($session['state'] === 'OPTED_OUT' && in_array($upper, ['START', 'TANGA', 'QALA'], true)) {
        $session['state'] = 'CONSENT_CHECK';
    }

    // 2. Consent Gate
    if (!$session['consented']) {
        if (in_array($upper, ['1', 'YES', 'HONGU', 'YEBO'], true)) {
            $session['consented'] = true;
            $session['state'] = 'AWAITING_PHOTO_OR_SYMPTOMS';
            $sessions[$phone] = $session;
            file_put_contents($stateFile, json_encode($sessions, JSON_PRETTY_PRINT));

            echo json_encode([
                'data' => [
                    'conversation_id' => $session['conversation_id'],
                    'state' => 'AWAITING_PHOTO_OR_SYMPTOMS',
                    'mode' => 'ai_active',
                    'language' => $lang,
                    'context_chip' => 'Consent Active • Ready for Diagnosis',
                    'reply_text' => "✅ Consent recorded under Zimbabwe's Cyber & Data Protection Act.\n\nSend a photo (📷) of an insect, leaf, stem, fruit, or livestock problem—or click one of the sample cases below:",
                    'buttons' => [
                        ['id' => 'SAMPLE_FAW', 'title' => '🐛 Fall Armyworm Photo'],
                        ['id' => 'SAMPLE_NITROGEN', 'title' => '🌱 Maize Nitrogen Def.'],
                        ['id' => 'SAMPLE_OUTLETS', 'title' => '📍 Nearest Outlets'],
                    ],
                    'rich_cards' => [],
                ],
            ]);
            exit;
        }

        $session['state'] = 'AWAITING_CONSENT';
        $sessions[$phone] = $session;
        file_put_contents($stateFile, json_encode($sessions, JSON_PRETTY_PRINT));

        echo json_encode([
            'data' => [
                'conversation_id' => $session['conversation_id'],
                'state' => 'AWAITING_CONSENT',
                'mode' => 'ai_active',
                'language' => $lang,
                'context_chip' => 'Step 0: CDPA Consent Check',
                'reply_text' => "Welcome to FarmServe Zimbabwe AI Mudhumeni / Umlimisi.\n\nUnder Zimbabwe's Cyber & Data Protection Act [Cap 12:07], we store your phone number, crop/livestock photos, and ward location to diagnose farm problems and find stocked outlets near you.\n\nDo you agree? (Reply STOP, MIRA or MANA anytime to opt out).",
                'buttons' => [
                    ['id' => '1', 'title' => '1. Yes, I agree'],
                    ['id' => 'STOP', 'title' => '2. No / Opt Out'],
                ],
                'rich_cards' => [],
            ],
        ]);
        exit;
    }

    // 3. Handle Outlet Finder & Stock Hold States
    if ($hasGps || in_array($upper, ['1', 'SAMPLE_OUTLETS', 'NEAREST OUTLETS', 'GLENDALE', 'MAZOWE'], true) && $session['state'] === 'QUALIFYING_BUYING_INTENT' || $upper === 'SAMPLE_OUTLETS') {
        $session['state'] = 'OUTLET_RESULTS_SENT';
        $sessions[$phone] = $session;
        file_put_contents($stateFile, json_encode($sessions, JSON_PRETTY_PRINT));

        $outlets = [
            [
                'outlet_id' => 'out-glendale-01',
                'outlet_name' => 'FarmServe Glendale Agro-Branch (Mazowe)',
                'distance_km' => 4.2,
                'estimated_travel_minutes' => 6,
                'is_open_now' => true,
                'supplies_whole_basket' => true,
            ],
            [
                'outlet_id' => 'out-bindura-02',
                'outlet_name' => 'FarmServe Bindura Depot',
                'distance_km' => 24.8,
                'estimated_travel_minutes' => 28,
                'is_open_now' => true,
                'supplies_whole_basket' => true,
            ],
        ];

        echo json_encode([
            'data' => [
                'conversation_id' => $session['conversation_id'],
                'state' => 'OUTLET_RESULTS_SENT',
                'mode' => 'ai_active',
                'language' => $lang,
                'context_chip' => $session['context_chip'],
                'reply_text' => "📍 *Nearest Stocked Outlets (within 25 km — Mazowe Ward 14):*\n"
                    . "_(Privacy Guard: Exact GPS hidden from outlets; only ward & distance band shared)_\n\n"
                    . "*1. FarmServe Glendale Agro-Branch (Mazowe)* — 4.2 km (~6 mins)\n"
                    . "   🟢 Open now | ✅ Full basket in stock | 🚚 Delivers (US$8.00)\n"
                    . "   • CoragenPro 200 SC (50ml): US$11.00 / ZiG 280.50 _(updated 2h ago)_\n"
                    . "   • BeltGuard Emamectin 5% SG (100g): US$6.50 / ZiG 165.75 _(updated 2h ago)_\n"
                    . "   • ZFC Compound D (50kg): US$32.00 / ZiG 816.00 _(updated 2h ago)_\n"
                    . "   📞 +263772200101\n\n"
                    . "*2. FarmServe Bindura Depot* — 24.8 km (~28 mins)\n"
                    . "   🟢 Open now | ✅ Full basket in stock | 🚚 Delivers (US$10.00)\n"
                    . "   📞 +263772200102\n\n"
                    . "Reply *HOLD 1* to reserve this stock at Glendale (30-min SLA confirmation guarantee), or *REP* to speak to a sales rep.",
                'buttons' => [
                    ['id' => 'HOLD_1', 'title' => 'Hold at Glendale (#1)'],
                    ['id' => 'REP', 'title' => 'Speak to Sales Rep'],
                ],
                'rich_cards' => [
                    [
                        'card_type' => 'outlet_list',
                        'title' => 'Nearest Stocked Outlets (25 km radius)',
                        'context_chip' => $session['context_chip'],
                        'payload' => ['outlets' => $outlets],
                    ],
                ],
            ],
        ]);
        exit;
    }

    if ($upper === 'HOLD_1' || $upper === 'HOLD 1') {
        $session['state'] = 'RESERVATION_PENDING';
        $sessions[$phone] = $session;
        file_put_contents($stateFile, json_encode($sessions, JSON_PRETTY_PRINT));

        echo json_encode([
            'data' => [
                'conversation_id' => $session['conversation_id'],
                'state' => 'RESERVATION_PENDING',
                'mode' => 'ai_active',
                'language' => $lang,
                'context_chip' => $session['context_chip'],
                'reply_text' => "✅ *Stock Hold Requested at FarmServe Glendale Agro-Branch!*\n"
                    . "Reservation Ref: `RSV-84920A` • Lead Temperature: 🔥 *HOT (Score: 90/100)*\n"
                    . "The Outlet Manager has been notified on WhatsApp and the Outlet Portal. If not confirmed within *30 minutes*, we automatically cascade to Bindura Depot.\n\n"
                    . "📅 *Follow-Up Scheduled:* We will check in with you in *7 days* for a progress photo.",
                'buttons' => [
                    ['id' => 'SAMPLE_FAW', 'title' => 'New Photo Diagnosis'],
                ],
                'rich_cards' => [],
            ],
        ]);
        exit;
    }

    // 4. Clarifying Questions & Grounded Advisories
    if ($session['state'] === 'AWAITING_QUESTION_ANSWER') {
        if ($session['question_step'] === 1) {
            $session['question_step'] = 2;
            $sessions[$phone] = $session;
            file_put_contents($stateFile, json_encode($sessions, JSON_PRETTY_PRINT));

            echo json_encode([
                'data' => [
                    'conversation_id' => $session['conversation_id'],
                    'state' => 'AWAITING_QUESTION_ANSWER',
                    'mode' => 'ai_active',
                    'language' => $lang,
                    'context_chip' => $session['context_chip'],
                    'reply_text' => "Candidate #1 confidence boosted to *89%* (>= 0.80 threshold).\n\n*Question 2 (Mandatory Context):* What is the size of the affected field or how many Pfumvudza plots are you treating?",
                    'buttons' => [
                        ['id' => 'PFUMVUDZA_2', 'title' => '2 Pfumvudza plots'],
                        ['id' => 'HA_1', 'title' => '1 Hectare (1.0 ha)'],
                    ],
                    'rich_cards' => [],
                ],
            ]);
            exit;
        }

        // Question 2 answered -> Deliver Grounded Advisory (Steps 4 & 5)
        $session['state'] = 'QUALIFYING_BUYING_INTENT';
        $session['question_step'] = 0;
        $sessions[$phone] = $session;
        file_put_contents($stateFile, json_encode($sessions, JSON_PRETTY_PRINT));

        if ($session['diagnosis_type'] === 'nutrient') {
            $advisoryText = "🌱 *Confirmed Diagnosis: Nitrogen (N) Deficiency* (Kushomeka kweNitrogen — 91% confidence)\n\n"
                . "*What it is:*\nMobile Nitrogen deficiency causing V-shaped yellowing starting from the tips of older lower leaves along the midrib. Rule out low soil pH (<5.2 CaCl2) and waterlogging.\n\n"
                . "*What to do now:*\n• Fix Now: Side-dress Ammonium Nitrate (AN 34.5% N) when soil has moisture.\n• Season Plan: Combine basal Compound D (7:14:7) with Dolomitic Lime on sandy soils.\n\n"
                . "*Product options (Approved Catalogue):*\n"
                . "1. *Sable Ammonium Nitrate (AN 34.5% N)* (Reg: ZW-FSF-2021-0015)\n"
                . "   • 50kg bag: *US$38.00 / ZiG 969.00* | 10kg pack: *US$8.50 / ZiG 216.75*\n\n"
                . "*How to apply (Deterministic 3-Way Rates):*\n"
                . "✓ *1. Per Plant:* *5.0 g/plant* (1 level Nr. 5 cup / 1 heaped beer bottle cap) at 75cm × 60cm spacing.\n"
                . "✓ *2. Per Pfumvudza Station (39m × 16m = 1,456 holes, 2 plants/hole):* *10.0 g/station* × 1,456 stations = *14.56 kg per Pfumvudza plot*.\n"
                . "✓ *3. Per Hectare:* *250 kg/ha* (*5.0 × 50 kg bags/ha*).\n"
                . "✓ *Precautions:* Place 5–7 cm away from the stem; never apply on dry soil or into the funnel.\n\n"
                . "*Safety:*\n⚠️ Wear gloves and store AN away from moisture, fuel, children, and livestock.\n\n"
                . "*Next step:*\nWould you like us to find the nearest stocked outlet to reserve bags for you? (Send a follow-up photo in 10–14 days)";

            $cards = [
                [
                    'card_type' => 'diagnosis_result',
                    'title' => 'Nitrogen (N) Deficiency',
                    'context_chip' => $session['context_chip'],
                    'payload' => ['confidence_pct' => 91, 'local_name' => 'Kushomeka kweNitrogen'],
                ],
                [
                    'card_type' => 'application_guidance',
                    'title' => '3-Way Fertiliser Application Rates (Plant / Pfumvudza / Hectare)',
                    'context_chip' => $session['context_chip'],
                    'payload' => ['per_plant_g' => 5.0, 'per_pfumvudza_station_g' => 10.0, 'per_ha_kg' => 250.0, 'bags_50kg_per_ha' => 5.0],
                ],
            ];
        } else {
            $advisoryText = "🌿 *Confirmed Diagnosis: Fall Armyworm (Spodoptera frugiperda)* (Gonye remunhava / Amacimbi — 90% confidence)\n\n"
                . "*What it is:*\nFall Armyworm caterpillars feeding deep in the maize whorl. Causes severe leaf ragging and growing-point destruction if untreated.\n\n"
                . "*What to do now:*\n• *Scouting threshold:* >20% whorl infestation reached — spray immediately.\n• *Cultural options first:* Crush visible egg masses, practice crop rotation, and keep field weed-free.\n\n"
                . "*Product options (Approved Tenant Catalogue — Ranked by Agronomic Fit):*\n"
                . "1. *CoragenPro Chlorantraniliprole 200 SC* (IRAC Group 28 Diamide • Reg: ZW-PPRI-2024-0118)\n"
                . "   • Packs: 50ml (*US$11.00 / ZiG 280.50*) | 200ml (*US$38.00 / ZiG 969.00*)\n"
                . "   • Dose: *10 ml per 16 L knapsack* (*150 ml/ha* in 250 L water/ha = 16 knapsacks/ha)\n"
                . "   • PHI: 14 days | REI: 12 hours | Re-apply interval: 14 days\n"
                . "   • Source: Zimbabwe Approved Crop Protection Handbook 2026 (p. 15)\n\n"
                . "2. *BeltGuard Emamectin 5% SG* (IRAC Group 6 Avermectin • Reg: ZW-PPRI-2023-0412)\n"
                . "   • Packs: 100g (*US$6.50 / ZiG 165.75*) | 250g (*US$14.50 / ZiG 369.75*)\n"
                . "   • Dose: *8 g per 16 L knapsack* (*150 g/ha*)\n"
                . "   • PHI: 14 days | REI: 12 hours | Re-apply interval: 10 days\n\n"
                . "*How to apply:*\n"
                . "✓ *Mixing Order:* Half-fill 16 L knapsack with clean water -> add 10 ml CoragenPro 200 SC -> stir and top up to 16 L.\n"
                . "✓ *Timing & Weather Window (Mazowe Ward 14):* 24.5°C, wind 8.5 km/h, 15% rain chance — *SAFE to spray tomorrow 06:00–09:30* directly into the funnel.\n"
                . "✓ *Resistance Management:* Rotate IRAC Group 28 with IRAC Group 6 on next spray.\n\n"
                . "*Safety:*\n"
                . "⚠️ *MANDATORY PPE:* Wear nitrile gloves, respirator mask, goggles, gumboots, and overalls. Keep away from children, pregnant women, bees, and boreholes/streams.\n\n"
                . "*Next step:*\nWould you like to see the nearest outlets with these products in stock and reserve them?";

            $cards = [
                [
                    'card_type' => 'diagnosis_result',
                    'title' => 'Fall Armyworm (Spodoptera frugiperda)',
                    'context_chip' => $session['context_chip'],
                    'payload' => ['confidence_pct' => 90, 'local_name' => 'Gonye remunhava / Amacimbi'],
                ],
                [
                    'card_type' => 'recommended_product',
                    'title' => 'CoragenPro 200 SC & BeltGuard 5% SG',
                    'context_chip' => $session['context_chip'],
                    'payload' => ['dose_16l' => '10 ml', 'dose_ha' => '150 ml', 'phi_days' => 14, 'rei_hours' => 12],
                ],
            ];
        }

        echo json_encode([
            'data' => [
                'conversation_id' => $session['conversation_id'],
                'state' => 'QUALIFYING_BUYING_INTENT',
                'mode' => 'ai_active',
                'language' => $lang,
                'context_chip' => $session['context_chip'],
                'reply_text' => $advisoryText,
                'buttons' => [
                    ['id' => '1', 'title' => '1. Nearest Outlets'],
                    ['id' => 'REP', 'title' => '2. Speak to Rep'],
                ],
                'rich_cards' => $cards,
            ],
        ]);
        exit;
    }

    // 5. First-Pass Vision Identification -> Adaptive Clarifying Question #1 (ONE AT A TIME)
    $isNutrient = str_contains($upper, 'NITROGEN') || str_contains($upper, 'YELLOW') || str_contains($upper, 'YERO');
    $session['diagnosis_type'] = $isNutrient ? 'nutrient' : 'pest';
    $session['state'] = 'AWAITING_QUESTION_ANSWER';
    $session['question_step'] = 1;
    $session['context_chip'] = $isNutrient
        ? 'Maize / V4–V6 vegetative / Nutrient Deficiency'
        : 'Maize / V6 vegetative / Fall Armyworm Check';
    $sessions[$phone] = $session;
    file_put_contents($stateFile, json_encode($sessions, JSON_PRETTY_PRINT));

    if ($isNutrient) {
        echo json_encode([
            'data' => [
                'conversation_id' => $session['conversation_id'],
                'state' => 'AWAITING_QUESTION_ANSWER',
                'mode' => 'ai_active',
                'language' => $lang,
                'context_chip' => $session['context_chip'],
                'reply_text' => "🔍 *First-Pass Vision Identification (Confidence < 0.80 — Confirming):*\n"
                    . "1. Likely *Nitrogen (N) Deficiency* (Kushomeka kweNitrogen — *72% confidence*)\n"
                    . "2. Possible *Potassium (K) Deficiency* (*18% confidence*)\n"
                    . "3. Possible *Sulphur (S) Deficiency* (*10% confidence*)\n"
                    . "_Confirm with field symptoms before treatment._\n\n"
                    . "*Question 1 (of max 5):* Which leaves are yellowing first, and is the yellow pattern shaped like a 'V' down the center midrib or burning along the outer leaf edges?",
                'buttons' => [
                    ['id' => 'OLD_V_MIDRIB', 'title' => 'Old leaf V-midrib (N)'],
                    ['id' => 'OLD_OUTER_EDGES', 'title' => 'Outer edges (K)'],
                ],
                'rich_cards' => [],
            ],
        ]);
        exit;
    }

    echo json_encode([
        'data' => [
            'conversation_id' => $session['conversation_id'],
            'state' => 'AWAITING_QUESTION_ANSWER',
            'mode' => 'ai_active',
            'language' => $lang,
            'context_chip' => $session['context_chip'],
            'reply_text' => "🔍 *First-Pass Vision Identification (Confidence < 0.80 — Confirming):*\n"
                . "1. Likely *Fall Armyworm (Spodoptera frugiperda)* (Gonye remunhava / Amacimbi — *74% confidence*)\n"
                . "2. Possible *African Maize Stalk Borer (Busseola fusca)* (Chiburi — *19% confidence*)\n"
                . "3. Possible *African Bollworm* (*7% confidence*)\n"
                . "_Confirm with field symptoms before treatment._\n\n"
                . "*Question 1 (of max 5):* Looking inside the maize funnel, do you see moist sawdust-like frass and a caterpillar with 4 dark spots in a square near its tail, or neat horizontal rows of pinholes?",
            'buttons' => [
                ['id' => 'FAW_4_DOTS', 'title' => '4 tail dots + frass'],
                ['id' => 'BORER_HOLES', 'title' => 'Pinkish + stem holes'],
            ],
            'rich_cards' => [],
        ],
    ]);
    exit;
}

if ($uri === '/widget-demo') {
    require __DIR__ . '/../resources/views/widget/demo.blade.php';
    exit;
}

// Interactive Preview Portal for /, /admin/farmserve-zimbabwe, /outlet, /platform
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BetterlinkAgr AI — Zimbabwe Multi-Tenant AI Agri-Input Sales Representative</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/widget/betterlink-chat-widget.css">
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen font-sans">
    <!-- Top Navigation Bar -->
    <header class="bg-emerald-900 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-6 py-4 flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="text-2xl">🌿</span>
                <div>
                    <h1 class="text-lg font-bold tracking-tight">BetterlinkAgr AI — Zimbabwe</h1>
                    <p class="text-xs text-emerald-200">White-Label Multi-Tenant AI Agri-Input Sales Representative (EN • ChiShona • isiNdebele)</p>
                </div>
            </div>
            <nav class="flex flex-wrap gap-2 text-xs font-semibold">
                <a href="/" class="px-3 py-2 rounded-lg <?= $uri === '/' ? 'bg-white text-emerald-900' : 'bg-emerald-800 hover:bg-emerald-700' ?>">Overview & Live Chat</a>
                <a href="/admin/farmserve-zimbabwe" class="px-3 py-2 rounded-lg <?= str_starts_with($uri, '/admin') ? 'bg-white text-emerald-900' : 'bg-emerald-800 hover:bg-emerald-700' ?>">Tenant Admin Panel (/admin)</a>
                <a href="/outlet" class="px-3 py-2 rounded-lg <?= str_starts_with($uri, '/outlet') ? 'bg-white text-emerald-900' : 'bg-emerald-800 hover:bg-emerald-700' ?>">Outlet Portal (/outlet)</a>
                <a href="/platform" class="px-3 py-2 rounded-lg <?= str_starts_with($uri, '/platform') ? 'bg-white text-emerald-900' : 'bg-emerald-800 hover:bg-emerald-700' ?>">Platform Super-Admin (/platform)</a>
                <a href="/widget-demo" class="px-3 py-2 rounded-lg bg-amber-400 text-slate-950 hover:bg-amber-300">Fullscreen Widget Demo</a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8 pb-24">
        <?php if (str_starts_with($uri, '/outlet')): ?>
            <!-- MOBILE-FIRST OUTLET PORTAL PREVIEW -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold uppercase tracking-wider text-amber-700 bg-amber-100 px-2.5 py-1 rounded">Outlet Portal • OutletScope Enforced</span>
                    <h2 class="text-2xl font-bold mt-1">FarmServe Glendale Agro-Branch (Mazowe Ward 14)</h2>
                    <p class="text-sm text-slate-600">Manager: Blessing Nyoni (+263772100005) • PostGIS Point: -17.3555, 31.0672</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- 30-Minute SLA Reservation Inbox -->
                <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
                    <h3 class="font-bold text-base mb-3 flex items-center justify-between">
                        <span>🛍️ Incoming Stock Reservations (30-Min SLA)</span>
                        <span class="text-xs bg-amber-100 text-amber-800 px-2.5 py-1 rounded-full">1 Pending Hold</span>
                    </h3>
                    <div class="rounded-lg border border-amber-200 bg-amber-50/50 p-4 space-y-2 text-sm">
                        <div class="flex justify-between font-bold">
                            <span>Ref: RSV-84920A • Farmer: Rumbidzai Moyo</span>
                            <span class="text-red-700">⏰ 24 mins left on SLA</span>
                        </div>
                        <p class="text-xs text-slate-600">📍 Disclosure: <b>Mazowe Ward 14 (~< 5 km band — exact GPS hidden until farmer contact consent)</b></p>
                        <p class="text-xs font-medium text-slate-800">Basket: 2× CoragenPro 200 SC (50ml) @ US$11.00 / ZiG 280.50 + 5× ZFC Compound D (50kg) @ US$32.00 / ZiG 816.00</p>
                        <div class="pt-2 flex flex-wrap gap-2">
                            <button onclick="this.textContent='✅ Confirmed & Farmer Notified'" class="bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-xs font-semibold">Confirm Hold</button>
                            <button onclick="this.textContent='💰 Sale Attributed (USD $182.00)'" class="bg-blue-700 text-white px-3 py-1.5 rounded-lg text-xs font-semibold">Mark Bought / Sold</button>
                            <button class="bg-red-100 text-red-700 px-3 py-1.5 rounded-lg text-xs font-semibold">Reject (Cascade to Bindura)</button>
                        </div>
                    </div>
                </div>

                <!-- 1-Tap Shelf Stock Bands & Dual-Currency Prices -->
                <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
                    <h3 class="font-bold text-base mb-3">📦 1-Tap Shelf Stock Bands (48h Freshness Tracked)</h3>
                    <div class="space-y-3 text-xs">
                        <div class="flex items-center justify-between p-2.5 bg-slate-50 rounded-lg border">
                            <div>
                                <div class="font-bold text-sm">CoragenPro Chlorantraniliprole 200 SC (50ml)</div>
                                <div class="text-slate-500">US$11.00 / ZiG 280.50 • Updated 2h ago</div>
                            </div>
                            <span class="bg-emerald-100 text-emerald-800 px-2.5 py-1 rounded font-bold">🟢 IN STOCK (45)</span>
                        </div>
                        <div class="flex items-center justify-between p-2.5 bg-slate-50 rounded-lg border">
                            <div>
                                <div class="font-bold text-sm">BeltGuard Emamectin 5% SG (100g)</div>
                                <div class="text-slate-500">US$6.50 / ZiG 165.75 • Updated 2h ago</div>
                            </div>
                            <span class="bg-emerald-100 text-emerald-800 px-2.5 py-1 rounded font-bold">🟢 IN STOCK (60)</span>
                        </div>
                        <div class="flex items-center justify-between p-2.5 bg-slate-50 rounded-lg border">
                            <div>
                                <div class="font-bold text-sm">ZFC Compound D 7:14:7 Basal (50kg)</div>
                                <div class="text-slate-500">US$32.00 / ZiG 816.00 • Updated 1h ago</div>
                            </div>
                            <span class="bg-emerald-100 text-emerald-800 px-2.5 py-1 rounded font-bold">🟢 IN STOCK (120 bags)</span>
                        </div>
                        <div class="flex items-center justify-between p-2.5 bg-slate-50 rounded-lg border">
                            <div>
                                <div class="font-bold text-sm">Sable Ammonium Nitrate 34.5% N (50kg)</div>
                                <div class="text-slate-500">US$38.00 / ZiG 969.00 • Updated 3h ago</div>
                            </div>
                            <span class="bg-amber-100 text-amber-800 px-2.5 py-1 rounded font-bold">🟡 LOW STOCK (8 bags)</span>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif (str_starts_with($uri, '/platform')): ?>
            <!-- PLATFORM SUPER-ADMIN PANEL PREVIEW -->
            <div class="mb-6">
                <span class="text-xs font-bold uppercase tracking-wider text-purple-800 bg-purple-100 px-2.5 py-1 rounded">Platform Super-Admin (/platform)</span>
                <h2 class="text-2xl font-bold mt-1">Zimbabwe Agri-Input Network & Supply Chain Links</h2>
                <p class="text-sm text-slate-600">Manufacturer -> Distributor -> Agro-Dealer Catalogue Inheritance & Sell-Through Attribution</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white p-4 rounded-xl border shadow-sm">
                    <span class="text-xs text-slate-500">Manufacturer Tenant</span>
                    <h3 class="font-bold text-base mt-1">ZimCrop & Chem Manufacturers</h3>
                    <p class="text-xs text-emerald-700 mt-1">Scope: Own Approved Brands Only • Master Dose & PHI/REI Owner</p>
                </div>
                <div class="bg-white p-4 rounded-xl border shadow-sm">
                    <span class="text-xs text-slate-500">Active Supply Link (tenant_supply_links)</span>
                    <h3 class="font-bold text-base mt-1">ZimCrop ➡️ FarmServe Zimbabwe</h3>
                    <p class="text-xs text-blue-700 mt-1">Status: ACTIVE • 8 Registered SKUs & Label PDFs Inherited</p>
                </div>
                <div class="bg-white p-4 rounded-xl border shadow-sm">
                    <span class="text-xs text-slate-500">Agro-Dealer Chain Tenant</span>
                    <h3 class="font-bold text-base mt-1">FarmServe Zimbabwe Agro-Dealers</h3>
                    <p class="text-xs text-emerald-700 mt-1">Scope: Own + Partner Brands • 6 PostGIS Outlets (Harare, Mazowe, Bindura, Marondera, Chinhoyi, Bulawayo)</p>
                </div>
            </div>

        <?php else: ?>
            <!-- TENANT ADMIN & OVERVIEW DASHBOARD PREVIEW -->
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <span class="text-xs font-bold uppercase tracking-wider text-emerald-800 bg-emerald-100 px-2.5 py-1 rounded">Tenant Admin • FarmServe Zimbabwe</span>
                    <h2 class="text-2xl font-bold mt-1">Analytics, Connected Farmer Profile & Live Diagnosis Preview</h2>
                </div>
                <div class="text-xs bg-white border rounded-lg px-3 py-2 shadow-sm">
                    💱 RBZ Indicative Rate: <b>US$1.00 = ZiG 25.50</b> • Guardrails: <b>STRICT (Zero Hallucinated Doses)</b>
                </div>
            </div>

            <!-- Section 14 Analytics KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4 mb-8">
                <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                    <div class="text-xs text-slate-500">Farmers Reached</div>
                    <div class="text-2xl font-bold text-emerald-800 mt-1">1,420</div>
                    <div class="text-[11px] text-slate-500 mt-1">Across 10 Provinces & 16 Districts</div>
                </div>
                <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                    <div class="text-xs text-slate-500">New Sales First Engaged via AI</div>
                    <div class="text-2xl font-bold text-blue-800 mt-1">US$18,450</div>
                    <div class="text-[11px] text-emerald-700 mt-1">+US$6,210 Upsell Uplift</div>
                </div>
                <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                    <div class="text-xs text-slate-500">AI Diagnosis Accuracy</div>
                    <div class="text-2xl font-bold text-emerald-700 mt-1">94.2%</div>
                    <div class="text-[11px] text-slate-500 mt-1">Confirmed by Agronomist Review</div>
                </div>
                <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                    <div class="text-xs text-slate-500">Farmer Satisfaction</div>
                    <div class="text-2xl font-bold text-amber-600 mt-1">9.4 / 10</div>
                    <div class="text-[11px] text-slate-500 mt-1">96% Thumbs Up Approval</div>
                </div>
                <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                    <div class="text-xs text-slate-500">Referral -> Reservation</div>
                    <div class="text-2xl font-bold text-indigo-700 mt-1">41.8%</div>
                    <div class="text-[11px] text-slate-500 mt-1">Avg Outlet SLA: 7.4 mins</div>
                </div>
                <div class="bg-white p-4 rounded-xl border border-red-200 bg-red-50/40 shadow-sm">
                    <div class="text-xs text-red-700 font-semibold">Active Outbreak Alerts</div>
                    <div class="text-2xl font-bold text-red-800 mt-1">2 Wards</div>
                    <div class="text-[11px] text-red-600 mt-1">Mazowe W14 (FAW) • Bindura W8</div>
                </div>
            </div>

            <!-- Two-Column Layout: Connected Farmer Profile Panel + Live State Machine Instructions -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                <!-- Left 7 Columns: Connected Farmer Profile Panel (Section 10 Spec) -->
                <div class="lg:col-span-7 bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
                    <div class="flex items-center justify-between border-b pb-3 mb-4">
                        <div>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-900">
                                🌱 Context Chip: Maize / V6 vegetative / Fall Armyworm
                            </span>
                            <h3 class="text-lg font-bold mt-2">Connected Farmer Profile Panel <span class="text-xs font-normal text-slate-500">(FRM-88A12C01)</span></h3>
                        </div>
                        <span class="bg-amber-100 text-amber-900 text-xs font-semibold px-2.5 py-1 rounded">Pfumvudza Enrolled (3 Plots)</span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs mb-5">
                        <div class="bg-slate-50 p-3 rounded-lg border">
                            <span class="text-slate-500 block">Farmer & Language</span>
                            <strong class="text-slate-900 text-sm">Rumbidzai Moyo (ChiShona)</strong>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-lg border">
                            <span class="text-slate-500 block">Location & Natural Region</span>
                            <strong class="text-slate-900 text-sm">Mazowe Ward 14 (NR IIa)</strong>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-lg border">
                            <span class="text-slate-500 block">Area Under Crop</span>
                            <strong class="text-slate-900 text-sm">1.0 ha + 3 Pfumvudza Plots</strong>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-lg border">
                            <span class="text-slate-500 block">Crop & Growth Stage</span>
                            <strong class="text-slate-900 text-sm">Maize • V6 (Knee-High)</strong>
                        </div>
                        <div class="bg-red-50 p-3 rounded-lg border border-red-200 col-span-2">
                            <span class="text-red-600 block">Recent Challenge & Confidence</span>
                            <strong class="text-red-900 text-sm">Fall Armyworm (Gonye remunhava — 90% Confidence)</strong>
                        </div>
                    </div>

                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Approved Portfolio for This Context (Ranked by Agronomic Fit, Never Margin)</h4>
                    <div class="space-y-2 text-xs">
                        <div class="p-3 rounded-lg border border-emerald-200 bg-emerald-50/50 flex justify-between items-center">
                            <div>
                                <div class="font-bold text-slate-900">1. CoragenPro Chlorantraniliprole 200 SC (ZIM-CHLORANT-200SC)</div>
                                <div class="text-slate-600">IRAC Group 28 • Reg: ZW-PPRI-2024-0118 • Dose: <b>10 ml / 16L knapsack (150 ml/ha)</b> • PHI: 14d | REI: 12h</div>
                            </div>
                            <span class="font-bold text-emerald-900 whitespace-nowrap ml-2">US$11.00 / ZiG 280.50</span>
                        </div>
                        <div class="p-3 rounded-lg border border-emerald-200 bg-emerald-50/50 flex justify-between items-center">
                            <div>
                                <div class="font-bold text-slate-900">2. BeltGuard Emamectin 5% SG (ZIM-EMAMEC-5SG)</div>
                                <div class="text-slate-600">IRAC Group 6 • Reg: ZW-PPRI-2023-0412 • Dose: <b>8 g / 16L knapsack (150 g/ha)</b> • PHI: 14d | REI: 12h</div>
                            </div>
                            <span class="font-bold text-emerald-900 whitespace-nowrap ml-2">US$6.50 / ZiG 165.75</span>
                        </div>
                        <div class="p-3 rounded-lg border border-slate-200 bg-slate-50 flex justify-between items-center">
                            <div>
                                <div class="font-bold text-slate-900">3. Sable Ammonium Nitrate 34.5% N (FERT-AN-345-50KG)</div>
                                <div class="text-slate-600">3-Way Rate: <b>5g/plant (Nr. 5 cup) • 10g/Pfumvudza hole (14.56kg/plot) • 250kg/ha (5 bags/ha)</b></div>
                            </div>
                            <span class="font-bold text-slate-900 whitespace-nowrap ml-2">US$38.00 / ZiG 969.00</span>
                        </div>
                    </div>
                </div>

                <!-- Right 5 Columns: Try the Live Interactive Chat Widget Guide -->
                <div class="lg:col-span-5 bg-emerald-950 text-white rounded-xl p-6 shadow-sm">
                    <h3 class="text-lg font-bold mb-2">💬 Try the Live Diagnosis Widget (Bottom-Right 👉)</h3>
                    <p class="text-xs text-emerald-200 mb-4 leading-relaxed">
                        The floating Web Chat Widget in the bottom-right corner is connected to the live stateful Diagnosis Engine. Try this walkthrough:
                    </p>
                    <ol class="space-y-2.5 text-xs text-emerald-100 list-decimal list-inside">
                        <li>Send <b>Hello</b> (or click Send) -> Prompts for <b>Step 0: Zimbabwe CDPA Consent</b>.</li>
                        <li>Click <b>1. Yes, I agree</b> -> Unlocks diagnosis & shows quick sample buttons.</li>
                        <li>Click <b>🐛 Fall Armyworm Photo</b> (or upload any image with 📷) -> Runs <b>Step 2: First-Pass Vision Identification</b> (74% confidence < 0.80) and asks <b>1 discriminative question</b>.</li>
                        <li>Click <b>4 tail dots + frass</b> -> Boosts confidence to 89% and asks mandatory field size / Pfumvudza plot count.</li>
                        <li>Click <b>1 Hectare (1.0 ha)</b> -> Delivers grounded <b>Pest Advisory</b> (or try <b>🌱 Maize Nitrogen Def.</b> for <b>3-way Pfumvudza/plant/ha rates</b>).</li>
                        <li>Click <b>1. Nearest Outlets</b> -> Runs <b>Location-Aware Outlet Finder</b> (Glendale & Bindura) and click <b>Hold at Glendale (#1)</b> to create a 30-min SLA reservation!</li>
                    </ol>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Live Floating Web Chat Widget -->
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

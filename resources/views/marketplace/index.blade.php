<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BetterlinkAgr AI — Farmer Marketplace</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-stone-50 text-stone-900 min-h-screen">
<div class="max-w-5xl mx-auto px-4 py-6">
    <header class="flex flex-wrap items-center justify-between gap-4 pb-5 border-b border-stone-200">
        <div>
            <a href="/" class="text-xs font-semibold text-emerald-700 hover:underline">← Back to Portal Hub</a>
            <h1 class="text-2xl font-bold text-emerald-800">BetterlinkAgr AI — Input Marketplace</h1>
            <p class="text-sm text-stone-600">Request farm inputs from verified nearby shops, book field services, and access offtake &amp; agri-finance.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <input id="farmerPhone" type="text" value="+263771000001" placeholder="+26377..."
                   class="px-3 py-1.5 text-sm border border-stone-300 rounded-lg bg-white">
            <button onclick="requestOtp()" class="px-3 py-1.5 text-sm bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg">Send OTP</button>
            <input id="otpCode" type="text" maxlength="6" placeholder="OTP"
                   class="hidden w-24 px-2.5 py-1.5 text-sm border border-stone-300 rounded-lg bg-white">
            <button id="verifyBtn" onclick="verifyOtp()" class="hidden px-3 py-1.5 text-sm bg-stone-800 text-white rounded-lg">Verify</button>
            <span id="authBadge" class="hidden px-2.5 py-1 text-xs font-medium bg-emerald-100 text-emerald-800 rounded-full">Verified</span>
        </div>
    </header>

    <nav class="mt-4 flex flex-wrap gap-2">
        <button onclick="switchTab('inputs')" id="tabBtn-inputs" class="px-4 py-2 text-sm font-semibold rounded-lg bg-emerald-700 text-white">Inputs Marketplace</button>
        <button onclick="switchTab('services')" id="tabBtn-services" class="px-4 py-2 text-sm font-semibold rounded-lg bg-white border border-stone-300 text-stone-700">Services &amp; Logistics (M4)</button>
        <button onclick="switchTab('finance')" id="tabBtn-finance" class="px-4 py-2 text-sm font-semibold rounded-lg bg-white border border-stone-300 text-stone-700">Offtake &amp; Agri-Finance (M5)</button>
    </nav>

    <div id="tabPanel-inputs">
    <section class="mt-4 bg-white p-5 rounded-xl shadow-sm border border-stone-200">
        <h2 class="text-lg font-semibold">Post a New Input Request</h2>
        <div class="mt-3 grid sm:grid-cols-4 gap-3">
            <input id="itemName" type="text" placeholder="e.g. Compound D 50kg" class="px-3 py-2 border rounded-lg sm:col-span-2">
            <input id="qtyInput" type="number" min="1" value="4" placeholder="Qty" class="px-3 py-2 border rounded-lg">
            <input id="unitInput" type="text" value="bags" placeholder="Unit" class="px-3 py-2 border rounded-lg">
            <select id="deliveryPref" class="px-3 py-2 border rounded-lg">
                <option value="either">Pickup or Delivery</option>
                <option value="pickup">Pickup Only</option>
                <option value="delivery">Delivery</option>
            </select>
            <select id="currencySelect" class="px-3 py-2 border rounded-lg">
                <option value="USD">USD</option>
                <option value="ZiG">ZiG</option>
            </select>
            <input id="exactLocation" type="text" placeholder="Exact location (hidden until accepted + consent)" class="px-3 py-2 border rounded-lg sm:col-span-2">
        </div>
        <button onclick="createRequest()" class="mt-3 px-5 py-2 bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-medium rounded-lg">
            Ask Nearby Shops for Offers
        </button>
    </section>

    <section id="activeRequestBox" class="mt-6 hidden bg-white p-5 rounded-xl shadow-sm border border-stone-200">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">Live Request Tracker</h2>
            <span id="reqStatusBadge" class="px-3 py-1 text-xs font-semibold bg-stone-200 rounded-full"></span>
        </div>
        <div class="mt-3 grid grid-cols-4 gap-3 text-center">
            <div class="p-3 bg-stone-50 rounded-lg"><div class="text-xs text-stone-500">Sent To</div><div id="cntSent" class="text-xl font-bold">0</div></div>
            <div class="p-3 bg-stone-50 rounded-lg"><div class="text-xs text-stone-500">Opened By</div><div id="cntOpened" class="text-xl font-bold">0</div></div>
            <div class="p-3 bg-stone-50 rounded-lg"><div class="text-xs text-stone-500">Offers</div><div id="cntOffers" class="text-xl font-bold text-emerald-700">0</div></div>
            <div class="p-3 bg-stone-50 rounded-lg"><div class="text-xs text-stone-500">Ring</div><div id="cntRing" class="text-xl font-bold">1</div></div>
        </div>
        <div class="mt-4">
            <label class="inline-flex items-center gap-2 text-sm text-stone-700">
                <input id="consentCheckbox" type="checkbox" checked class="rounded">
                Share my exact location & phone with the winning shop upon acceptance
            </label>
        </div>
        <div id="offersList" class="mt-4 space-y-2"></div>
    </section>

    <section class="mt-6 bg-white p-5 rounded-xl shadow-sm border border-stone-200">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold">My Requests</h2>
            <button onclick="loadRequests()" class="px-3 py-1 text-xs bg-stone-200 rounded-lg">Refresh</button>
        </div>
        <div id="myRequestsList" class="mt-3 space-y-2 text-sm text-stone-600">Verify with OTP to view your requests.</div>
    </section>
    </div>

    <div id="tabPanel-services" class="hidden space-y-5 mt-4">
        <section class="bg-white p-5 rounded-xl shadow-sm border border-stone-200">
            <h2 class="text-lg font-semibold">Find Nearby Field Services &amp; Logistics</h2>
            <p class="text-xs text-stone-500 mt-0.5">Search PostGIS-verified tillage, spraying, soil testing, veterinary, and transport providers near your farm.</p>
            <div class="mt-3 flex flex-wrap gap-3">
                <select id="serviceTypeSelect" class="px-3 py-2 border rounded-lg text-sm">
                    <option value="tillage">Tillage / Ploughing</option>
                    <option value="spraying">Drone / Boom Spraying</option>
                    <option value="soil_testing">Soil Testing</option>
                    <option value="transport">Produce / Input Transport</option>
                    <option value="veterinary">Veterinary Visit</option>
                </select>
                <button onclick="searchProviders()" class="px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-medium rounded-lg">Find Providers</button>
                <button onclick="loadBookings()" class="px-4 py-2 bg-stone-200 text-stone-800 text-sm font-medium rounded-lg">My Bookings</button>
            </div>
            <label class="mt-3 inline-flex items-center gap-2 text-xs text-stone-700">
                <input id="serviceConsentCheckbox" type="checkbox" checked class="rounded">
                Share my phone number with the service provider when I book (CDPA Consent)
            </label>
            <div id="providersList" class="mt-4 space-y-2 text-sm text-stone-600">Select a service type and click Find Providers.</div>
        </section>
        <section class="bg-white p-5 rounded-xl shadow-sm border border-stone-200">
            <h2 class="text-lg font-semibold">My Service Bookings</h2>
            <div id="myBookingsList" class="mt-3 space-y-2 text-sm text-stone-600">Verify with OTP to view your service bookings.</div>
        </section>
    </div>

    <div id="tabPanel-finance" class="hidden space-y-5 mt-4">
        <section class="bg-white p-5 rounded-xl shadow-sm border border-stone-200">
            <h2 class="text-lg font-semibold">Offtaker Forward Quote &amp; Consent-Gated Agri-Finance</h2>
            <p class="text-xs text-stone-600 mt-1">
                Lock in harvest forward quotes with verified offtakers and apply for input financing with partner banks.
                Banks can <strong>never</strong> view your Farm Plan without your explicit <code>bank_plan_sharing</code> consent, and you can revoke consent at any time.
            </p>
            <div class="mt-4 grid sm:grid-cols-2 gap-4">
                <div class="p-4 border rounded-xl bg-stone-50">
                    <h3 class="font-semibold text-sm text-emerald-800">1. Request Harvest Forward Quote</h3>
                    <div class="mt-2 space-y-2">
                        <input id="fqOfftakerId" type="text" placeholder="Offtaker Organisation UUID" class="w-full px-3 py-1.5 text-sm border rounded-lg bg-white">
                        <input id="fqCommodity" type="text" value="White Maize Grade A" placeholder="Commodity" class="w-full px-3 py-1.5 text-sm border rounded-lg bg-white">
                        <input id="fqQty" type="number" min="1" value="10" placeholder="Tonnes" class="w-full px-3 py-1.5 text-sm border rounded-lg bg-white">
                        <button onclick="submitForwardQuote()" class="px-4 py-1.5 bg-emerald-700 text-white text-xs font-semibold rounded-lg">Request Forward Quote</button>
                    </div>
                </div>
                <div class="p-4 border rounded-xl bg-stone-50">
                    <h3 class="font-semibold text-sm text-emerald-800">2. Apply for Input Financing</h3>
                    <div class="mt-2 space-y-2">
                        <input id="finBankId" type="text" placeholder="Partner Bank UUID" class="w-full px-3 py-1.5 text-sm border rounded-lg bg-white">
                        <input id="finPlanId" type="text" placeholder="Farm Plan UUID" class="w-full px-3 py-1.5 text-sm border rounded-lg bg-white">
                        <input id="finAmountUsd" type="number" min="10" value="1500" placeholder="Amount (USD)" class="w-full px-3 py-1.5 text-sm border rounded-lg bg-white">
                        <label class="flex items-center gap-2 text-xs text-stone-700">
                            <input id="finConsentCheckbox" type="checkbox" class="rounded">
                            I explicitly consent to share this Farm Plan snapshot with this bank (CDPA)
                        </label>
                        <button onclick="submitFinanceApp()" class="px-4 py-1.5 bg-stone-800 text-white text-xs font-semibold rounded-lg">Submit Finance Application</button>
                    </div>
                </div>
            </div>
            <div id="m5StatusBox" class="mt-3 text-sm font-medium text-emerald-800"></div>
        </section>
    </div>
</div>

<script>
let activeRequestId = null;
let pollTimer = null;
const headers = () => ({'Content-Type': 'application/json', 'Accept': 'application/json'});

async function requestOtp() {
    const res = await fetch('/api/v1/planner/auth/request-otp', {
        method: 'POST', credentials: 'same-origin', headers: headers(),
        body: JSON.stringify({phone: document.getElementById('farmerPhone').value})
    });
    const d = await res.json();
    document.getElementById('otpCode').classList.remove('hidden');
    document.getElementById('verifyBtn').classList.remove('hidden');
    if (d.debug_otp) document.getElementById('otpCode').value = d.debug_otp;
}

async function verifyOtp() {
    const res = await fetch('/api/v1/planner/auth/verify-otp', {
        method: 'POST', credentials: 'same-origin', headers: headers(),
        body: JSON.stringify({
            phone: document.getElementById('farmerPhone').value,
            otp: document.getElementById('otpCode').value
        })
    });
    if (res.ok) {
        document.getElementById('otpCode').classList.add('hidden');
        document.getElementById('verifyBtn').classList.add('hidden');
        document.getElementById('authBadge').classList.remove('hidden');
        loadRequests();
    }
}

async function createRequest() {
    const qty = Math.max(1, parseInt(document.getElementById('qtyInput').value || '1', 10));
    const res = await fetch('/api/v1/marketplace/requests', {
        method: 'POST', credentials: 'same-origin', headers: headers(),
        body: JSON.stringify({
            approx_latitude: -17.8292,
            approx_longitude: 31.0522,
            exact_location: document.getElementById('exactLocation').value || null,
            delivery_preference: document.getElementById('deliveryPref').value,
            currency: document.getElementById('currencySelect').value,
            items: [{
                item_name: document.getElementById('itemName').value,
                quantity_scaled: qty * 100,
                unit: document.getElementById('unitInput').value || 'units'
            }]
        })
    });
    if (!res.ok) { alert('Please verify with OTP and enter an item name.'); return; }
    const d = await res.json();
    openRequest(d.request.id);
    loadRequests();
}

async function loadRequests() {
    const res = await fetch('/api/v1/marketplace/requests', {credentials: 'same-origin', headers: headers()});
    if (!res.ok) return;
    document.getElementById('authBadge').classList.remove('hidden');
    const d = await res.json();
    const box = document.getElementById('myRequestsList');
    if ((d.requests || []).length === 0) {
        box.textContent = 'No marketplace requests yet.';
        return;
    }
    box.innerHTML = d.requests.map(r => `
        <button onclick="openRequest('${r.id}')" class="w-full text-left p-3 border rounded-lg hover:bg-stone-50 flex justify-between">
            <span class="font-medium text-stone-800">${(r.items || []).map(i => i.item_name).join(', ') || r.id.slice(0, 8)}</span>
            <span class="text-xs px-2 py-0.5 bg-stone-200 rounded-full">${r.status}</span>
        </button>
    `).join('');
}

async function openRequest(id) {
    activeRequestId = id;
    document.getElementById('activeRequestBox').classList.remove('hidden');
    await refreshActiveRequest();
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(refreshActiveRequest, 5000);
}

async function refreshActiveRequest() {
    if (!activeRequestId) return;
    const res = await fetch(`/api/v1/marketplace/requests/${activeRequestId}`, {credentials: 'same-origin', headers: headers()});
    if (!res.ok) return;
    const d = await res.json();
    document.getElementById('reqStatusBadge').textContent = d.counters.status;
    document.getElementById('cntSent').textContent = d.counters.sent;
    document.getElementById('cntOpened').textContent = d.counters.opened;
    document.getElementById('cntOffers').textContent = d.counters.offers;
    document.getElementById('cntRing').textContent = d.counters.current_ring;
    const list = document.getElementById('offersList');
    list.innerHTML = (d.offers || []).map((o, idx) => `
        <div class="p-3 border rounded-lg flex flex-wrap justify-between items-center gap-2">
            <div>
                <div class="font-semibold">#${idx + 1} · ${o.currency} ${(o.total_price_minor / 100).toFixed(2)} (${o.fulfilment_type})</div>
                <div class="text-xs text-stone-600">${o.rank_explanation_json?.summary || ''} · Status: ${o.status}</div>
            </div>
            ${o.status === 'pending' ? `<button onclick="acceptOffer('${o.id}')" class="px-3 py-1.5 text-xs bg-emerald-700 text-white rounded-lg">Accept Offer</button>` : ''}
        </div>
    `).join('') || '<p class="text-sm text-stone-500">Waiting for nearby shops to respond...</p>';
}

async function acceptOffer(offerId) {
    const consent = document.getElementById('consentCheckbox').checked;
    const res = await fetch(`/api/v1/marketplace/requests/${activeRequestId}/offers/${offerId}/accept`, {
        method: 'POST', credentials: 'same-origin', headers: headers(),
        body: JSON.stringify({grant_contact_reveal_consent: consent})
    });
    if (res.ok) {
        await refreshActiveRequest();
        loadRequests();
    }
}

function switchTab(tab) {
    ['inputs', 'services', 'finance'].forEach(t => {
        document.getElementById(`tabPanel-${t}`).classList.toggle('hidden', t !== tab);
        const btn = document.getElementById(`tabBtn-${t}`);
        if (t === tab) {
            btn.className = 'px-4 py-2 text-sm font-semibold rounded-lg bg-emerald-700 text-white';
        } else {
            btn.className = 'px-4 py-2 text-sm font-semibold rounded-lg bg-white border border-stone-300 text-stone-700';
        }
    });
    if (tab === 'services') loadBookings();
}

async function searchProviders() {
    const type = document.getElementById('serviceTypeSelect').value;
    const res = await fetch(`/api/v1/marketplace/services/providers?service_type=${encodeURIComponent(type)}&latitude=-17.8292&longitude=31.0522`, {
        credentials: 'same-origin', headers: headers()
    });
    const box = document.getElementById('providersList');
    if (!res.ok) { box.textContent = 'Please verify with OTP first.'; return; }
    const d = await res.json();
    box.innerHTML = (d.providers || []).map(p => `
        <div class="p-3 border rounded-lg flex flex-wrap justify-between items-center gap-2">
            <div>
                <div class="font-semibold">${p.name} ${p.is_verified ? '✅' : ''}</div>
                <div class="text-xs text-stone-600">${p.currency} ${(p.unit_rate_minor / 100).toFixed(2)} ${p.rate_unit} · Radius ${p.coverage_radius_km}km</div>
            </div>
            <button onclick="bookProvider('${p.id}')" class="px-3 py-1.5 text-xs bg-emerald-700 text-white rounded-lg">Book Now</button>
        </div>
    `).join('') || '<p class="text-stone-500">No providers found in range for this service type.</p>';
}

async function bookProvider(providerId) {
    const scheduled = new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 10);
    const res = await fetch('/api/v1/marketplace/bookings', {
        method: 'POST', credentials: 'same-origin', headers: headers(),
        body: JSON.stringify({
            service_provider_id: providerId,
            scheduled_date: scheduled,
            quantity_scaled: 100,
            unit: 'ha',
            grant_contact_consent: document.getElementById('serviceConsentCheckbox').checked
        })
    });
    if (res.ok) loadBookings();
}

async function loadBookings() {
    const res = await fetch('/api/v1/marketplace/bookings', {credentials: 'same-origin', headers: headers()});
    if (!res.ok) return;
    const d = await res.json();
    document.getElementById('myBookingsList').innerHTML = (d.bookings || []).map(b => `
        <div class="p-3 border rounded-lg flex justify-between items-center">
            <span><strong>${b.service_type}</strong> · ${b.provider_name || ''} · ${b.scheduled_date}</span>
            <span class="text-xs px-2 py-0.5 bg-stone-200 rounded-full">${b.status}</span>
        </div>
    `).join('') || 'No service bookings yet.';
}

async function submitForwardQuote() {
    const start = new Date(Date.now() + 60 * 86400000).toISOString().slice(0, 10);
    const end = new Date(Date.now() + 90 * 86400000).toISOString().slice(0, 10);
    const res = await fetch('/api/v1/marketplace/forward-quotes', {
        method: 'POST', credentials: 'same-origin', headers: headers(),
        body: JSON.stringify({
            offtaker_organisation_id: document.getElementById('fqOfftakerId').value.trim(),
            commodity_code: 'maize_grain_white',
            commodity_name: document.getElementById('fqCommodity').value.trim(),
            quantity_scaled: Math.max(1, parseInt(document.getElementById('fqQty').value || '1', 10)) * 100,
            unit: 'tonnes',
            delivery_window_start: start,
            delivery_window_end: end
        })
    });
    const d = await res.json();
    document.getElementById('m5StatusBox').textContent = res.ok ? `Forward quote requested (${d.forward_quote.id.slice(0, 8)}).` : (d.error || 'Could not request forward quote.');
}

async function submitFinanceApp() {
    const res = await fetch('/api/v1/marketplace/finance/applications', {
        method: 'POST', credentials: 'same-origin', headers: headers(),
        body: JSON.stringify({
            bank_organisation_id: document.getElementById('finBankId').value.trim(),
            farm_plan_id: document.getElementById('finPlanId').value.trim(),
            requested_amount_minor: Math.max(10, parseInt(document.getElementById('finAmountUsd').value || '100', 10)) * 100,
            purpose: 'input_finance',
            grant_bank_sharing_consent: document.getElementById('finConsentCheckbox').checked
        })
    });
    const d = await res.json();
    document.getElementById('m5StatusBox').textContent = res.ok ? `Finance application submitted (${d.application.id.slice(0, 8)}).` : (d.error || 'Could not submit application.');
}

loadRequests();
</script>
</body>
</html>

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
            <h1 class="text-2xl font-bold text-emerald-800">BetterlinkAgr AI — Input Marketplace</h1>
            <p class="text-sm text-stone-600">Request farm inputs from verified nearby shops, track live responses, and compare ranked offers.</p>
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

    <section class="mt-6 bg-white p-5 rounded-xl shadow-sm border border-stone-200">
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

loadRequests();
</script>
</body>
</html>

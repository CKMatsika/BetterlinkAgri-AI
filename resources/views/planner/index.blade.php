<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BetterlinkAgr AI — Farm Planner Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-stone-50 text-stone-900 min-h-screen">
<div class="max-w-5xl mx-auto px-4 py-6">
    <header class="flex flex-wrap items-center justify-between gap-4 pb-6 border-b border-stone-200">
        <div>
            <h1 class="text-2xl font-bold text-emerald-800">BetterlinkAgr AI — Farm Planner</h1>
            <p class="text-sm text-stone-600">Plan your crop or livestock enterprise, test "what-if" scenarios, and export a summary PDF.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <input id="farmerPhone" type="text" value="+263771000001" placeholder="+26377..."
                   class="px-3 py-1.5 text-sm border border-stone-300 rounded-lg bg-white">
            <button id="otpRequestBtn" onclick="requestOtp()" class="px-3 py-1.5 text-sm bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg">Send OTP</button>
            <input id="otpCodeInput" type="text" maxlength="6" placeholder="6-digit OTP"
                   class="hidden w-28 px-2.5 py-1.5 text-sm border border-stone-300 rounded-lg bg-white">
            <button id="otpVerifyBtn" onclick="verifyOtp()" class="hidden px-3 py-1.5 text-sm bg-stone-800 text-white rounded-lg">Verify</button>
            <span id="authBadge" class="hidden px-2.5 py-1 text-xs font-medium bg-emerald-100 text-emerald-800 rounded-full">Verified</span>
            <select id="languageSelect" class="px-3 py-1.5 text-sm border border-stone-300 rounded-lg bg-white">
                <option value="en">English</option>
                @if(config('betterlink.localization.enable_sn_nd'))
                    <option value="sn">ChiShona (needs native review)</option>
                    <option value="nd">isiNdebele (needs native review)</option>
                @endif
            </select>
            <select id="currencySelect" class="px-3 py-1.5 text-sm border border-stone-300 rounded-lg bg-white">
                <option value="USD">USD</option>
                <option value="ZiG">ZiG</option>
            </select>
            <button onclick="loadPlans()" class="px-3 py-1.5 text-sm bg-stone-200 hover:bg-stone-300 rounded-lg">My Plans</button>
        </div>
    </header>

    <!-- Entry Section -->
    <section id="entrySection" class="mt-6 bg-white p-6 rounded-xl shadow-sm border border-stone-200">
        <h2 class="text-lg font-semibold text-stone-800">What do you want to grow or raise?</h2>
        <div class="mt-3 flex gap-2">
            <input id="goalInput" type="text" placeholder="e.g. I want to start 500 broilers, or 2 hectares of Pfumvudza maize"
                   class="flex-1 px-4 py-2.5 border border-stone-300 rounded-lg">
            <button onclick="startPlan(null)" class="px-5 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white font-medium rounded-lg">
                Start Planning
            </button>
        </div>
        <div id="enterpriseShortcuts" class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-3"></div>
        <div id="savedPlansList" class="mt-6 hidden">
            <h3 class="text-sm font-semibold text-stone-600 uppercase tracking-wide">Saved Plans</h3>
            <div id="savedPlansGrid" class="mt-2 grid sm:grid-cols-2 gap-2"></div>
        </div>
    </section>

    <!-- Interview Section -->
    <section id="interviewSection" class="mt-6 hidden bg-white p-6 rounded-xl shadow-sm border border-stone-200">
        <div class="flex items-center justify-between text-sm text-stone-600 mb-2">
            <span id="interviewPlanTitle" class="font-semibold text-emerald-800"></span>
            <span id="interviewProgressLabel"></span>
        </div>
        <div class="w-full bg-stone-200 h-2 rounded-full overflow-hidden">
            <div id="interviewProgressBar" class="bg-emerald-600 h-2 transition-all" style="width: 0%"></div>
        </div>
        <div id="assumptionWarning" class="hidden mt-4 p-3 rounded-lg bg-amber-50 border border-amber-300 text-amber-900 text-sm"></div>
        <div class="mt-5">
            <p id="questionText" class="text-lg font-medium text-stone-900"></p>
            <div id="questionOptions" class="mt-3 flex flex-wrap gap-2"></div>
            <div class="mt-4 flex gap-2">
                <input id="answerInput" type="text" placeholder="Type your answer..."
                       class="flex-1 px-4 py-2 border border-stone-300 rounded-lg">
                <button onclick="submitAnswer()" class="px-5 py-2 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg">
                    Next
                </button>
            </div>
        </div>
    </section>

    <!-- Results Section (6 Tabs + What-If + PDF) -->
    <section id="resultsSection" class="mt-6 hidden space-y-4">
        <div class="bg-white p-4 rounded-xl shadow-sm border border-stone-200 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="resultTitle" class="text-xl font-bold text-stone-900"></h2>
                <p id="resultMeta" class="text-sm text-stone-600"></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button onclick="downloadPdf()" class="px-4 py-2 text-sm bg-stone-800 hover:bg-stone-900 text-white rounded-lg">
                    Download PDF
                </button>
                <button id="acceptBtn" onclick="acceptPlan()" class="px-4 py-2 text-sm bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg">
                    Accept Plan
                </button>
                <button onclick="showEntry()" class="px-4 py-2 text-sm bg-stone-200 hover:bg-stone-300 rounded-lg">
                    New Plan
                </button>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex flex-wrap gap-2 border-b border-stone-200 pb-2">
            <button data-tab="plan" onclick="switchTab('plan')" class="tab-btn px-3 py-1.5 text-sm font-medium rounded-lg bg-emerald-700 text-white">Plan</button>
            <button data-tab="recommendations" onclick="switchTab('recommendations')" class="tab-btn px-3 py-1.5 text-sm font-medium rounded-lg bg-stone-200 text-stone-800">Recommendations</button>
            <button data-tab="money" onclick="switchTab('money')" class="tab-btn px-3 py-1.5 text-sm font-medium rounded-lg bg-stone-200 text-stone-800">Money</button>
            <button data-tab="verdict" onclick="switchTab('verdict')" class="tab-btn px-3 py-1.5 text-sm font-medium rounded-lg bg-stone-200 text-stone-800">Verdict</button>
            <button data-tab="schedule" onclick="switchTab('schedule')" class="tab-btn px-3 py-1.5 text-sm font-medium rounded-lg bg-stone-200 text-stone-800">Schedule</button>
            <button data-tab="coverage" onclick="switchTab('coverage')" class="tab-btn px-3 py-1.5 text-sm font-medium rounded-lg bg-stone-200 text-stone-800">Coverage</button>
        </div>

        <div id="tabContent" class="bg-white p-6 rounded-xl shadow-sm border border-stone-200"></div>

        <!-- What-If Controls -->
        <div class="bg-white p-5 rounded-xl shadow-sm border border-stone-200">
            <h3 class="text-sm font-semibold text-stone-700 uppercase tracking-wide">What-If Simulator</h3>
            <div class="mt-3 grid sm:grid-cols-4 gap-3 items-end">
                <div>
                    <label class="block text-xs text-stone-600">Scale</label>
                    <input id="whatIfScale" type="number" step="0.1" class="w-full px-3 py-1.5 border border-stone-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-xs text-stone-600">Output Price (minor cents)</label>
                    <input id="whatIfPrice" type="number" placeholder="Optional override" class="w-full px-3 py-1.5 border border-stone-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-xs text-stone-600">Start Date</label>
                    <input id="whatIfDate" type="date" class="w-full px-3 py-1.5 border border-stone-300 rounded-lg">
                </div>
                <button onclick="runWhatIf()" class="px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-medium rounded-lg">
                    Recalculate
                </button>
            </div>
        </div>
    </section>
</div>

<script>
let currentPayload = null;
let activeTab = 'plan';
let isAuthenticated = false;

function authHeaders() {
    return {'Content-Type': 'application/json', 'Accept': 'application/json'};
}

async function requestOtp() {
    const res = await fetch('/api/v1/planner/auth/request-otp', {
        method: 'POST',
        credentials: 'same-origin',
        headers: authHeaders(),
        body: JSON.stringify({phone: document.getElementById('farmerPhone').value})
    });
    const data = await res.json();
    document.getElementById('otpCodeInput').classList.remove('hidden');
    document.getElementById('otpVerifyBtn').classList.remove('hidden');
    if (data.debug_otp) document.getElementById('otpCodeInput').value = data.debug_otp;
}

async function verifyOtp() {
    const res = await fetch('/api/v1/planner/auth/verify-otp', {
        method: 'POST',
        credentials: 'same-origin',
        headers: authHeaders(),
        body: JSON.stringify({
            phone: document.getElementById('farmerPhone').value,
            otp: document.getElementById('otpCodeInput').value
        })
    });
    if (res.ok) {
        isAuthenticated = true;
        document.getElementById('otpCodeInput').classList.add('hidden');
        document.getElementById('otpVerifyBtn').classList.add('hidden');
        document.getElementById('authBadge').classList.remove('hidden');
        loadPlans();
    }
}

async function init() {
    const res = await fetch('/api/v1/planner/enterprises');
    const data = await res.json();
    const grid = document.getElementById('enterpriseShortcuts');
    grid.innerHTML = (data.enterprises || []).map(e => `
        <button onclick="startPlan('${e.code}')" class="text-left p-3 rounded-lg border border-stone-200 hover:border-emerald-600 hover:bg-emerald-50">
            <div class="font-semibold text-stone-800">${e.name_en}</div>
            <div class="text-xs text-stone-500">${e.name_sn || ''} / ${e.name_nd || ''}</div>
        </button>
    `).join('');
    await loadPlans();
}

async function loadPlans() {
    const res = await fetch('/api/v1/planner/plans', {credentials: 'same-origin', headers: authHeaders()});
    if (!res.ok) return;
    isAuthenticated = true;
    document.getElementById('authBadge').classList.remove('hidden');
    const data = await res.json();
    const list = document.getElementById('savedPlansList');
    const grid = document.getElementById('savedPlansGrid');
    if ((data.plans || []).length === 0) {
        list.classList.add('hidden');
        return;
    }
    list.classList.remove('hidden');
    grid.innerHTML = data.plans.map(p => `
        <button onclick="openPlan('${p.id}')" class="text-left p-3 rounded-lg border border-stone-200 hover:bg-stone-50">
            <div class="font-medium text-stone-800">${p.title}</div>
            <div class="text-xs text-stone-500">${p.state} · ${p.scale_value} ${p.scale_unit} · ${p.currency}</div>
        </button>
    `).join('');
}

async function startPlan(enterpriseCode) {
    if (!isAuthenticated) { alert('Please verify your phone with OTP first.'); return; }
    const res = await fetch('/api/v1/planner/plans', {
        method: 'POST',
        credentials: 'same-origin',
        headers: authHeaders(),
        body: JSON.stringify({
            enterprise_code: enterpriseCode,
            goal_text: document.getElementById('goalInput').value,
            language: document.getElementById('languageSelect').value,
            currency: document.getElementById('currencySelect').value,
        })
    });
    renderPayload(await res.json());
}

async function openPlan(id) {
    const lang = document.getElementById('languageSelect').value;
    const res = await fetch(`/api/v1/planner/plans/${id}?language=${lang}`, {credentials: 'same-origin', headers: authHeaders()});
    renderPayload(await res.json());
}

async function submitAnswer(presetText = null) {
    if (!currentPayload || !currentPayload.interview.next_question) return;
    const answerText = presetText ?? document.getElementById('answerInput').value;
    if (!answerText) return;
    const res = await fetch(`/api/v1/planner/plans/${currentPayload.plan.id}/answer`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: authHeaders(),
        body: JSON.stringify({
            question_key: currentPayload.interview.next_question.key,
            answer_text: answerText,
            language: document.getElementById('languageSelect').value,
        })
    });
    document.getElementById('answerInput').value = '';
    renderPayload(await res.json());
}

async function runWhatIf() {
    if (!currentPayload) return;
    const priceVal = document.getElementById('whatIfPrice').value;
    const res = await fetch(`/api/v1/planner/plans/${currentPayload.plan.id}/what-if`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: authHeaders(),
        body: JSON.stringify({
            scale_value: document.getElementById('whatIfScale').value || undefined,
            planned_start_date: document.getElementById('whatIfDate').value || undefined,
            output_price_minor: priceVal ? parseInt(priceVal, 10) : undefined,
            currency: document.getElementById('currencySelect').value,
            language: document.getElementById('languageSelect').value,
        })
    });
    renderPayload(await res.json());
}

async function acceptPlan() {
    if (!currentPayload) return;
    const res = await fetch(`/api/v1/planner/plans/${currentPayload.plan.id}/accept`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: authHeaders(),
        body: JSON.stringify({language: document.getElementById('languageSelect').value})
    });
    renderPayload(await res.json());
}

function downloadPdf() {
    if (!currentPayload) return;
    const lang = document.getElementById('languageSelect').value;
    window.location.href = `/api/v1/planner/plans/${currentPayload.plan.id}/pdf?language=${lang}`;
}

function showEntry() {
    document.getElementById('entrySection').classList.remove('hidden');
    document.getElementById('interviewSection').classList.add('hidden');
    document.getElementById('resultsSection').classList.add('hidden');
    loadPlans();
}

function renderPayload(payload) {
    currentPayload = payload;
    if (!payload.interview.completed && payload.interview.next_question) {
        document.getElementById('entrySection').classList.add('hidden');
        document.getElementById('resultsSection').classList.add('hidden');
        document.getElementById('interviewSection').classList.remove('hidden');
        document.getElementById('interviewPlanTitle').textContent = payload.plan.title;
        document.getElementById('interviewProgressLabel').textContent = `${payload.interview.answered_count} / ${payload.interview.total_questions}`;
        document.getElementById('interviewProgressBar').style.width = `${payload.interview.progress_pct}%`;
        document.getElementById('questionText').textContent = payload.interview.next_question.text;

        const warn = document.getElementById('assumptionWarning');
        if (payload.last_answer_feedback && payload.last_answer_feedback.flagged) {
            warn.textContent = payload.last_answer_feedback.flag_reason;
            warn.classList.remove('hidden');
        } else {
            warn.classList.add('hidden');
        }

        const opts = document.getElementById('questionOptions');
        opts.innerHTML = (payload.interview.next_question.options || []).map(o => {
            const label = typeof o === 'string' ? o : (o.label || o.value || JSON.stringify(o));
            return `<button onclick="submitAnswer('${String(label).replace(/'/g, "\\'")}')" class="px-3 py-1.5 text-sm rounded-lg border border-stone-300 hover:bg-emerald-50">${label}</button>`;
        }).join('');
        return;
    }

    document.getElementById('entrySection').classList.add('hidden');
    document.getElementById('interviewSection').classList.add('hidden');
    document.getElementById('resultsSection').classList.remove('hidden');
    document.getElementById('resultTitle').textContent = payload.plan.title;
    document.getElementById('resultMeta').textContent = `${payload.plan.state} · ${payload.plan.scale_value} ${payload.plan.scale_unit} · Start ${payload.plan.planned_start_date || 'TBD'}`;
    document.getElementById('whatIfScale').value = payload.plan.scale_value;
    document.getElementById('whatIfDate').value = payload.plan.planned_start_date || '';
    document.getElementById('acceptBtn').disabled = payload.plan.state === 'ACCEPTED';
    renderTab();
}

function switchTab(tab) {
    activeTab = tab;
    document.querySelectorAll('.tab-btn').forEach(btn => {
        const isActive = btn.dataset.tab === tab;
        btn.className = `tab-btn px-3 py-1.5 text-sm font-medium rounded-lg ${isActive ? 'bg-emerald-700 text-white' : 'bg-stone-200 text-stone-800'}`;
    });
    renderTab();
}

function fmtMoney(minor, cur) {
    return `${cur} ${(minor / 100).toFixed(2)}`;
}

function renderTab() {
    if (!currentPayload) return;
    const el = document.getElementById('tabContent');
    const f = currentPayload.financials;

    if (activeTab === 'plan') {
        el.innerHTML = `<div class="space-y-2 text-sm">
            <p><strong>Enterprise:</strong> ${currentPayload.plan.enterprise?.name_en || ''}</p>
            <p><strong>Scale:</strong> ${currentPayload.plan.scale_value} ${currentPayload.plan.scale_unit}</p>
            <p><strong>Planned Start:</strong> ${currentPayload.plan.planned_start_date || 'TBD'}</p>
            <p><strong>State:</strong> ${currentPayload.plan.state}</p>
        </div>`;
    } else if (activeTab === 'recommendations') {
        const flags = (currentPayload.recommendations.risk_flags || []).map(r => `<li class="p-3 bg-stone-50 rounded-lg border"><strong>[${r.severity}]</strong> ${r.description}<br><span class="text-stone-600">${r.mitigation_note || ''}</span></li>`).join('');
        const checks = (currentPayload.recommendations.checklist || []).map(c => `<li class="p-2 border-b text-sm"><strong>${c.title}</strong> (${c.authority_or_body}) — ${c.description}</li>`).join('');
        el.innerHTML = `<h4 class="font-semibold mb-2">Risks & Mitigations</h4><ul class="space-y-2 mb-4">${flags}</ul><h4 class="font-semibold mb-2">Compliance Checklist</h4><ul>${checks}</ul>`;
    } else if (activeTab === 'money') {
        if (f.numbers_hidden_pending_review) {
            el.innerHTML = `<div class="p-4 bg-amber-50 border border-amber-300 rounded-lg text-amber-900"><strong>Under agronomist review:</strong> Reference benchmarks are awaiting agronomist verification; financial figures are withheld.</div>`;
        } else if (f.projection) {
            const p = f.projection;
            el.innerHTML = `<div class="grid sm:grid-cols-3 gap-4 text-sm">
                <div class="p-3 bg-stone-50 rounded-lg"><div class="text-stone-500">Total Cost</div><div class="text-lg font-bold">${fmtMoney(p.total_cost_minor, p.currency)}</div></div>
                <div class="p-3 bg-stone-50 rounded-lg"><div class="text-stone-500">Expected Revenue</div><div class="text-lg font-bold">${fmtMoney(p.total_revenue_minor, p.currency)}</div></div>
                <div class="p-3 bg-stone-50 rounded-lg"><div class="text-stone-500">Net Margin</div><div class="text-lg font-bold text-emerald-700">${fmtMoney(p.net_margin_minor, p.currency)}</div></div>
                <div class="p-3 bg-stone-50 rounded-lg"><div class="text-stone-500">Peak Funding Need</div><div class="font-semibold">${fmtMoney(p.peak_funding_need_minor, p.currency)}</div></div>
                <div class="p-3 bg-stone-50 rounded-lg"><div class="text-stone-500">Break-even Price</div><div class="font-semibold">${fmtMoney(p.break_even_price_minor, p.currency)}</div></div>
                <div class="p-3 bg-stone-50 rounded-lg"><div class="text-stone-500">ROI</div><div class="font-semibold">${(p.roi_pct_scaled / 100).toFixed(2)}%</div></div>
            </div>`;
        } else {
            el.innerHTML = `<p class="text-sm text-stone-600">Complete the interview to generate financial projections.</p>`;
        }
    } else if (activeTab === 'verdict') {
        const reasons = (f.verdict_reasons || []).map(r => `<li>${r}</li>`).join('');
        el.innerHTML = `<div class="space-y-2">
            <div class="text-lg font-bold uppercase">${f.viability_verdict || 'PENDING'}</div>
            <div class="inline-block px-2.5 py-1 text-xs font-medium bg-stone-200 rounded-full">${f.verdict_label || ''}</div>
            <ul class="list-disc pl-5 text-sm text-stone-700 mt-2">${reasons}</ul>
        </div>`;
    } else if (activeTab === 'schedule') {
        el.innerHTML = `<p class="text-sm text-stone-600">${currentPayload.schedule_placeholder.message}</p>`;
    } else if (activeTab === 'coverage') {
        el.innerHTML = `<p class="text-sm text-stone-600">${currentPayload.coverage_placeholder.message}</p>`;
    }
}

init();
</script>
</body>
</html>

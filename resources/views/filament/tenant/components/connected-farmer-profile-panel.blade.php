@php
    /** @var array<string, mixed> $profile */
@endphp
<div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900" x-data="{ showAllApproved: false }">
    <!-- Context Chip Header -->
    <div class="mb-4 flex items-center justify-between border-b border-gray-100 pb-3 dark:border-gray-800">
        <div>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                🌱 {{ $profile['context_chip'] }}
            </span>
            <h3 class="mt-2 text-base font-bold text-gray-900 dark:text-white">
                {{ $profile['full_name'] }}
                <span class="ml-1 text-xs font-normal text-gray-500">({{ $profile['farmer_short_id'] }})</span>
            </h3>
            <p class="text-xs text-gray-500">
                📞 {{ $profile['phone'] }} • Spoken: {{ $profile['preferred_language'] }} • Active {{ $profile['last_active_human'] }}
            </p>
        </div>
        @if($profile['is_pfumvudza_enrolled'])
            <span class="rounded bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800">
                Pfumvudza / Intwasa
            </span>
        @endif
    </div>

    <!-- Connected Farm & Agronomic Attributes Grid -->
    <div class="grid grid-cols-2 gap-3 text-sm">
        <div class="rounded-lg bg-gray-50 p-2.5 dark:bg-gray-800">
            <span class="block text-xs text-gray-500">Crop & Variety</span>
            <span class="font-semibold text-gray-900 dark:text-white">{{ $profile['crop_and_variety'] }}</span>
        </div>
        <div class="rounded-lg bg-gray-50 p-2.5 dark:bg-gray-800">
            <span class="block text-xs text-gray-500">Growth Stage</span>
            <span class="font-semibold text-gray-900 dark:text-white">{{ $profile['growth_stage'] }}</span>
        </div>
        <div class="rounded-lg bg-gray-50 p-2.5 dark:bg-gray-800">
            <span class="block text-xs text-gray-500">Location & Region</span>
            <span class="font-semibold text-gray-900 dark:text-white">{{ $profile['location_summary'] }} ({{ $profile['natural_region'] }})</span>
        </div>
        <div class="rounded-lg bg-gray-50 p-2.5 dark:bg-gray-800">
            <span class="block text-xs text-gray-500">Area Under Crop</span>
            <span class="font-semibold text-gray-900 dark:text-white">{{ $profile['area_under_crop_ha'] }}</span>
        </div>
        <div class="col-span-2 rounded-lg bg-red-50 p-2.5 dark:bg-red-950/40">
            <span class="block text-xs text-red-600 dark:text-red-400">Recent Challenge</span>
            <span class="font-semibold text-red-900 dark:text-red-200">
                {{ $profile['recent_challenge'] }}
                @if(!is_null($profile['recent_confidence_pct']))
                    ({{ $profile['recent_confidence_pct'] }}% confidence)
                @endif
            </span>
        </div>
    </div>

    <!-- Approved Portfolio for This Context -->
    <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-800">
        <div class="flex items-center justify-between">
            <h4 class="text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400">
                Approved Portfolio for This Context
            </h4>
            <button
                type="button"
                @click="showAllApproved = !showAllApproved"
                class="text-xs font-semibold text-emerald-700 hover:underline dark:text-emerald-400"
            >
                <span x-text="showAllApproved ? 'Hide all approved options' : 'Show all approved options ({{ count($profile['all_approved_options']) }})'"></span>
            </button>
        </div>

        <div class="mt-2 space-y-2">
            @forelse($profile['approved_portfolio_for_context'] as $item)
                <div class="rounded-lg border border-emerald-200 bg-emerald-50/40 p-2.5 text-xs dark:border-emerald-800 dark:bg-emerald-950/20">
                    <div class="font-bold text-gray-900 dark:text-white">{{ $item['product_name'] }} ({{ $item['sku'] }})</div>
                    <div class="text-gray-600 dark:text-gray-300">
                        {{ $item['active_ingredient'] }} • {{ $item['mode_of_action_group'] }}
                    </div>
                    <div class="mt-1 font-medium text-emerald-800 dark:text-emerald-300">
                        Dose: {{ $item['dose_per_16l_knapsack_value'] }}{{ $item['dose_per_16l_knapsack_unit'] }} / 16L knapsack ({{ $item['dose_per_ha_value'] }}{{ $item['dose_per_ha_unit'] }}/ha) • PHI: {{ $item['phi_days'] }}d
                    </div>
                </div>
            @empty
                <p class="text-xs italic text-gray-500">No context-specific chemical matched yet. Expand all approved options below.</p>
            @endforelse
        </div>

        <!-- Expandable All Approved Catalogue Options -->
        <div x-show="showAllApproved" x-cloak class="mt-3 space-y-2 border-t border-dashed border-gray-200 pt-3 dark:border-gray-700">
            @foreach($profile['all_approved_options'] as $opt)
                <div class="rounded border border-gray-200 p-2 text-xs dark:border-gray-700">
                    <span class="font-semibold">{{ $opt['name'] }}</span>
                    <span class="text-gray-500">({{ $opt['sku'] }} • Reg: {{ $opt['registration_number'] }})</span>
                </div>
            @endforeach
        </div>
    </div>
</div>

<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <!-- Left 7 Columns: Live Conversation Transcript & Immutable Audit Ledger -->
        <div class="space-y-6 lg:col-span-7">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between border-b pb-3">
                    <h3 class="text-base font-bold text-gray-900 dark:text-white">
                        Conversation Transcript ({{ strtoupper($conversation->channel) }})
                    </h3>
                    <span class="rounded bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800">
                        State: {{ $conversation->state->value }} • Mode: {{ $conversation->mode->value }}
                    </span>
                </div>

                <div class="max-h-[480px] space-y-3 overflow-y-auto pr-2">
                    @foreach($messages as $msg)
                        <div class="flex {{ $msg->direction === 'inbound' ? 'justify-start' : 'justify-end' }}">
                            <div class="max-w-[85%] rounded-lg p-3 text-xs {{ $msg->direction === 'inbound' ? 'bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-white' : 'bg-emerald-50 text-emerald-950 dark:bg-emerald-950 dark:text-emerald-100' }}">
                                <div class="mb-1 flex items-center justify-between gap-4 text-[10px] text-gray-500">
                                    <span class="font-bold uppercase">{{ $msg->sender_type }}</span>
                                    <span>{{ $msg->created_at?->format('Y-m-d H:i:s') }}</span>
                                </div>
                                <div class="whitespace-pre-line">{{ $msg->body_text ?: $msg->transcribed_text }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Immutable AI & State Transition Audit Log -->
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <h3 class="mb-3 text-base font-bold text-gray-900 dark:text-white">
                    Immutable Audit Trail (State Transitions, Candidates, Questions, Tool Calls & RAG Sources)
                </h3>
                <div class="space-y-2 text-xs">
                    @foreach($auditLogs as $log)
                        <div class="rounded border border-gray-100 bg-gray-50 p-2.5 dark:border-gray-800 dark:bg-gray-800/50">
                            <div class="flex items-center justify-between font-semibold">
                                <span>[{{ $log->event_type }}] {{ $log->from_state ? "{$log->from_state} -> {$log->to_state}" : '' }}</span>
                                <span class="text-gray-500">{{ $log->created_at?->format('H:i:s') }}</span>
                            </div>
                            @if($log->guardrail_result_json)
                                <div class="mt-1 text-emerald-700 dark:text-emerald-400">
                                    Guardrail Passed: {{ ($log->guardrail_result_json['passed'] ?? false) ? 'YES' : 'NO' }}
                                    • Verified SKUs: {{ implode(', ', $log->guardrail_result_json['verified_skus'] ?? []) }}
                                </div>
                            @endif
                            @if($log->tool_calls_json)
                                <div class="mt-1 text-blue-700 dark:text-blue-400">
                                    Tools: {{ json_encode($log->tool_calls_json) }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Right 5 Columns: Connected Farmer Profile Panel -->
        <div class="lg:col-span-5">
            @include('filament.tenant.components.connected-farmer-profile-panel', ['profile' => $profile])
        </div>
    </div>
</x-filament-panels::page>

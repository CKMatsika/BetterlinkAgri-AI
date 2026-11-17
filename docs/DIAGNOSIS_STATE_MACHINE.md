# BetterlinkAgr AI — Conversation & Diagnosis State Machine (Authoritative Spec)

Every inbound message from any channel (WhatsApp Cloud API, Web Chat Widget, SMS/USSD, or Flutter App) enters through `ConversationStateMachine` at `CONSENT_CHECK`. Every state transition is validated by a transition guard, persisted to `conversations.state`, and written as an immutable record to `audit_logs`.

---

## 1. Authoritative State Machine Diagram

```mermaid
stateDiagram-v2
    [*] --> CONSENT_CHECK : Inbound message (photo / text / voice / location)

    state "Step 0: Consent and Opt-Out Check" as CONSENT_CHECK
    CONSENT_CHECK --> OPTED_OUT : Message matches STOP / MIRA / MANA
    CONSENT_CHECK --> AWAITING_CONSENT : No active CDPA consent on record
    CONSENT_CHECK --> HUMAN_TAKEOVER : conversation.mode == human_takeover
    CONSENT_CHECK --> INTENT_ROUTING : Consent active and mode == ai_active

    OPTED_OUT --> CONSENT_CHECK : Farmer sends START
    AWAITING_CONSENT --> INTENT_ROUTING : Farmer replies YES / 1 (replay original message)
    AWAITING_CONSENT --> OPTED_OUT : Farmer replies NO / 2

    state "Intent Routing" as INTENT_ROUTING
    INTENT_ROUTING --> INTAKE_PROCESSING : Crop or animal problem (photo or symptoms)
    INTENT_ROUTING --> PROFILING_FLOW : Onboarding or profile question pending
    INTENT_ROUTING --> PROGRAMME_FLOW : Programme, campaign reply or event RSVP
    INTENT_ROUTING --> AWAITING_LOCATION : Where to buy / stock / price request
    INTENT_ROUTING --> GENERAL_QA : General product or agronomy question
    INTENT_ROUTING --> HUMAN_TAKEOVER : Farmer asks for a person
    PROFILING_FLOW --> INTENT_ROUTING : Profile field saved (send Profile updated card)
    PROGRAMME_FLOW --> INTENT_ROUTING : Reply logged, profile updated
    GENERAL_QA --> QUALIFYING_BUYING_INTENT : Answer given and commercial signal detected
    GENERAL_QA --> [*] : Answer given

    state "Step 1: Intake and Quality Gate" as INTAKE_PROCESSING
    INTAKE_PROCESSING --> AWAITING_PHOTO_OR_SYMPTOMS : Greeting only (no symptom or photo)
    INTAKE_PROCESSING --> AWAITING_BETTER_IMAGE : Photo blurry / dark / far (quality_passed == false)
    INTAKE_PROCESSING --> VISION_IDENTIFICATION : Valid photo (EXIF stripped, compressed)
    INTAKE_PROCESSING --> TEXT_SYMPTOM_PARSING : Text or voice symptom only (also request a photo)

    AWAITING_PHOTO_OR_SYMPTOMS --> INTAKE_PROCESSING : Farmer sends photo or symptoms
    AWAITING_BETTER_IMAGE --> VISION_IDENTIFICATION : Farmer sends clearer photo
    AWAITING_BETTER_IMAGE --> TEXT_SYMPTOM_PARSING : Retry count >= 2 (proceed with low-confidence warning)

    state "Step 2: First-Pass Identification" as VISION_IDENTIFICATION
    VISION_IDENTIFICATION --> AWAITING_BETTER_IMAGE : Vision returns image_quality == poor
    VISION_IDENTIFICATION --> ESCALATED_HIGH_STAKES : Notifiable disease or acute livestock deaths
    VISION_IDENTIFICATION --> CLARIFYING_QUESTIONS : confidence < 0.80 or mandatory context missing
    VISION_IDENTIFICATION --> DETERMINISTIC_TOOL_EXECUTION : confidence >= 0.80 and context complete

    TEXT_SYMPTOM_PARSING --> CLARIFYING_QUESTIONS : Candidates ranked from text or voice

    state "Step 3: Adaptive Clarifying Loop (one at a time)" as CLARIFYING_QUESTIONS
    CLARIFYING_QUESTIONS --> AWAITING_QUESTION_ANSWER : Send 1 short discriminative question (order 1..5)
    AWAITING_QUESTION_ANSWER --> CLARIFYING_QUESTIONS : Answer received, re-score candidates
    AWAITING_QUESTION_ANSWER --> INTAKE_PROCESSING : Farmer uploads new photo mid-questioning
    CLARIFYING_QUESTIONS --> DETERMINISTIC_TOOL_EXECUTION : confidence >= 0.80 or (questions_asked == 5 and confidence >= 0.55)
    CLARIFYING_QUESTIONS --> ESCALATED_LOW_CONFIDENCE : questions_asked == 5 and confidence < 0.55
    CLARIFYING_QUESTIONS --> ESCALATED_HIGH_STAKES : Answer reveals widespread outbreak or animal mortality

    state "Steps 4 and 5: Grounded Advisory and Safety Guardrails" as DETERMINISTIC_TOOL_EXECUTION
    DETERMINISTIC_TOOL_EXECUTION --> GUARDRAIL_VALIDATION : Run lookup_products, calculate_application_rate, search_knowledge, get_weather
    GUARDRAIL_VALIDATION --> ADVISORY_DELIVERED : All doses and SKUs verified, PPE/PHI/REI attached
    GUARDRAIL_VALIDATION --> ESCALATED_NO_REGISTERED_PRODUCT : No approved SKU for (crop, target), send non-chemical advice and escalate

    state "Step 6: Advisory Delivered" as ADVISORY_DELIVERED
    ADVISORY_DELIVERED --> QUALIFYING_BUYING_INTENT : Prompt on quantity, stock and dealer connect

    state "Qualification" as QUALIFYING_BUYING_INTENT
    QUALIFYING_BUYING_INTENT --> AWAITING_LOCATION : Farmer wants to buy or check stock
    QUALIFYING_BUYING_INTENT --> LEAD_ROUTED_TO_REP : Hot intent or farmer asks for a representative
    QUALIFYING_BUYING_INTENT --> HANDOFF_COMMERCIAL_NEGOTIATION : Farmer asks for credit, discount or contract terms
    QUALIFYING_BUYING_INTENT --> FOLLOW_UP_SCHEDULED : Farmer declines now (cold or warm logged)

    state "Location-Aware Outlet Finder" as AWAITING_LOCATION
    AWAITING_LOCATION --> OUTLET_RESULTS_SENT : Pin, saved farm location or ward fallback resolved
    AWAITING_LOCATION --> OUTLET_RESULTS_SENT : No reply after 24 h, use saved location if any
    OUTLET_RESULTS_SENT --> RESERVATION_PENDING : Farmer picks an outlet (Hold this for me)
    OUTLET_RESULTS_SENT --> LEAD_ROUTED_TO_REP : No outlet in range, offer delivery or callback
    OUTLET_RESULTS_SENT --> FOLLOW_UP_SCHEDULED : Farmer will decide later
    RESERVATION_PENDING --> FOLLOW_UP_SCHEDULED : Outlet confirms, pickup reminder set
    RESERVATION_PENDING --> OUTLET_RESULTS_SENT : Outlet rejects or SLA expires, offer next nearest
    RESERVATION_PENDING --> LEAD_ROUTED_TO_REP : All outlets fail, escalate to rep

    LEAD_ROUTED_TO_REP --> MEETING_SCHEDULING : Farmer wants a call or farm visit
    LEAD_ROUTED_TO_REP --> HUMAN_TAKEOVER : Rep claims conversation
    LEAD_ROUTED_TO_REP --> FOLLOW_UP_SCHEDULED : Lead logged, SLA timer started
    MEETING_SCHEDULING --> FOLLOW_UP_SCHEDULED : Slot confirmed, ICS and reminders sent

    ESCALATED_HIGH_STAKES --> HUMAN_TAKEOVER : Agronomist or vet claims ticket
    ESCALATED_LOW_CONFIDENCE --> HUMAN_TAKEOVER : Agronomist reviews photo and overrides advisory
    ESCALATED_NO_REGISTERED_PRODUCT --> FOLLOW_UP_SCHEDULED : Logged in agronomist queue
    HANDOFF_COMMERCIAL_NEGOTIATION --> HUMAN_TAKEOVER : Rep takes over pricing or credit chat
    HUMAN_TAKEOVER --> FOLLOW_UP_SCHEDULED : Human resolves and returns chat to AI

    state "Step 7: Follow-Up and Outbreak Loop" as FOLLOW_UP_SCHEDULED
    FOLLOW_UP_SCHEDULED --> AWAITING_FOLLOW_UP_RESPONSE : Timer fires (+7d pest/disease, +14d nutrient, +3d livestock)
    AWAITING_FOLLOW_UP_RESPONSE --> FOLLOW_UP_EVALUATION : Farmer replies with progress photo or status
    AWAITING_FOLLOW_UP_RESPONSE --> [*] : No reply after 2 reminders
    FOLLOW_UP_EVALUATION --> RESOLVED_COLLECT_FEEDBACK : Resolved or improving, ask 1-10 rating
    FOLLOW_UP_EVALUATION --> ESCALATED_HIGH_STAKES : Worsened after treatment, check resistance or outbreak
    RESOLVED_COLLECT_FEEDBACK --> [*]
    OPTED_OUT --> [*]
```

---

## 2. State Rules and Invariants

| State | Entry Actions | Exit / Guard |
| :--- | :--- | :--- |
| `CONSENT_CHECK` | Check opt-out keywords (`STOP`, `MIRA`, `MANA`) first, then active `Consent` row for `(tenant_id, farmer_id)`, then `conversation.mode`. | Exactly one of `OPTED_OUT`, `AWAITING_CONSENT`, `HUMAN_TAKEOVER`, `INTENT_ROUTING`. |
| `OPTED_OUT` | Mark `consents.revoked_at`, suppress all outbound campaigns/follow-ups, send opt-out confirmation (`Reply START to re-enable`). | Only transitions back to `CONSENT_CHECK` if farmer sends `START` / `TANGA` / `QALA`. |
| `AWAITING_CONSENT` | Send a short consent prompt in the farmer's language (`1` Yes, `2` No) explaining what data is kept and why under the Cyber and Data Protection Act [Cap 12:07]. | `1`/Yes writes a `Consent` row and replays the original buffered message into `INTENT_ROUTING`. `2`/No transitions to `OPTED_OUT`. |
| `INTENT_ROUTING` | Classify intent (`problem_diagnosis`, `profiling`, `programme_or_event`, `buy_where_stock`, `general_qa`, `human_request`). | Problem diagnosis and buy/stock intents always take priority over pending progressive profiling questions. |
| `PROFILING_FLOW` | Save progressive profile answer via `update_farmer_profile`, update conversation `context_chip`, and emit a `profile_updated` card listing saved fields. | Returns to `INTENT_ROUTING`. |
| `PROGRAMME_FLOW` | Record programme milestone reply, campaign response, or `event_rsvps` confirmation. | Returns to `INTENT_ROUTING`. |
| `GENERAL_QA` | Retrieve tenant-scoped RAG chunks (`search_knowledge`) and answer general product or agronomy question. | If commercial buying signal is detected, transition to `QUALIFYING_BUYING_INTENT`; otherwise complete turn. |
| `INTAKE_PROCESSING` | Voice: transcribe via `VoiceTranscriptionService`. Image: strip EXIF (keep GPS only with active `location_consents`), compress, compute blur/brightness/resolution. | Failed quality gate -> `AWAITING_BETTER_IMAGE`. Valid photo -> `VISION_IDENTIFICATION`. Text/voice symptom only -> `TEXT_SYMPTOM_PARSING` (and ask for photo). |
| `AWAITING_BETTER_IMAGE` | Send framing guidance (leaf top and underside, insect on plant, whole plant, affected field area) in `en`/`sn`/`nd`. | After 2 poor photos (`poor_photo_retries >= 2`), fall back to `TEXT_SYMPTOM_PARSING` with an explicit low-confidence caveat. |
| `VISION_IDENTIFICATION` | `AiAgentService::identifyFromImage()`. Persist a `Diagnosis` with `category`, `crop`, `growth_stage`, top 3 `candidates_json` (with `sn`/`nd` names), `image_quality`, `missing_information_json`. | Never present one answer as certain if `confidence < 0.80`. Route to `ESCALATED_HIGH_STAKES`, `CLARIFYING_QUESTIONS`, or `DETERMINISTIC_TOOL_EXECUTION`. |
| `CLARIFYING_QUESTIONS` | `AdaptiveQuestionEngine::selectNextQuestion()`. Persist each `DiagnosisQuestion` (`order = 1..5`). | Loop while `confidence < 0.80` and `questions_asked < 5`. |
| `DETERMINISTIC_TOOL_EXECUTION` | Run `lookup_products`, `calculate_application_rate`, `search_knowledge`, `get_weather`. | Pass structured tool results to `GUARDRAIL_VALIDATION`. |
| `GUARDRAIL_VALIDATION` | Verify every SKU, dose per 16 L knapsack, dose per ha, PHI, REI and fertiliser rate against DB rows. Attach PPE and safety text. Write `AuditLog`. | No registered product for `(crop, target)` goes to `ESCALATED_NO_REGISTERED_PRODUCT`. Verified advisory goes to `ADVISORY_DELIVERED`. |
| `ADVISORY_DELIVERED` | Send formatted WhatsApp/Web rich cards (`diagnosis_result`, `recommended_product`, `application_guidance`). | Immediately transition to `QUALIFYING_BUYING_INTENT` and prompt on quantity, stock check, and dealer connection. |
| `QUALIFYING_BUYING_INTENT` | Score lead (`cold`/`warm`/`hot`) with stored reasons. | `wants_to_buy_or_check_stock` -> `AWAITING_LOCATION`; `hot_intent_or_rep_requested` -> `LEAD_ROUTED_TO_REP`; `credit_or_discount_requested` -> `HANDOFF_COMMERCIAL_NEGOTIATION`; `declines_now` -> `FOLLOW_UP_SCHEDULED`. |
| `AWAITING_LOCATION` | Ask for a WhatsApp location pin or use the saved farm location (`farmer_locations`), with explicit `location_consents`. Fallback: ask for ward or business centre. | Resolve to coordinates (accuracy & source stored) or a `ward_centroids` point marked approximate -> `OUTLET_RESULTS_SENT` (or after 24h timeout using saved location). |
| `OUTLET_RESULTS_SENT` | Execute `OutletFinderService` (PostGIS radius `25 -> 50 -> 100 km -> province`, ranked by stock confidence/freshness, distance, open now, delivery, price). Send `outlet_list` card (top 3). | Farmer clicks "Hold this for me" -> `RESERVATION_PENDING`. No outlet in range -> `LEAD_ROUTED_TO_REP`. Will decide later -> `FOLLOW_UP_SCHEDULED`. |
| `RESERVATION_PENDING` | Create `Reservation` + `Lead` with quantity from `calculate_application_rate`. Notify outlet manager via WhatsApp/push. Start 30-min outlet SLA timer. | Outlet confirms -> `FOLLOW_UP_SCHEDULED` (with pickup reminder). Outlet rejects or SLA expires -> offer next nearest (`OUTLET_RESULTS_SENT`), or if all fail -> `LEAD_ROUTED_TO_REP`. |
| `LEAD_ROUTED_TO_REP` | Auto-assign to nearest rep/dealer by ward/district with full context summary (`handoff_summary`). Start 2h rep SLA timer. | Farmer wants call/visit -> `MEETING_SCHEDULING`. Rep claims live chat -> `HUMAN_TAKEOVER`. Otherwise -> `FOLLOW_UP_SCHEDULED`. |
| `MEETING_SCHEDULING` | Propose 2–3 rep availability slots via `schedule_meeting`. On confirmation, create `Meeting`, generate `.ics` file, schedule WhatsApp reminders. | Transitions to `FOLLOW_UP_SCHEDULED`. |
| `HUMAN_TAKEOVER` | Set `conversation.mode = human_takeover`. Suppress AI replies. Forward farmer messages to Filament live chat and rep's WhatsApp. | "Return to AI" or resolve goes to `FOLLOW_UP_SCHEDULED`. |
| `FOLLOW_UP_SCHEDULED` | Dispatch `SendFollowUpCheckInJob` (`+7d` pest/disease, `+14d` nutrient, `+3d` livestock). Run `OutbreakDetectionService` for the ward. | A new unrelated photo before the timer starts a new `Diagnosis`. Timer firing transitions to `AWAITING_FOLLOW_UP_RESPONSE`. |

---

## 3. `ConversationStateMachine` Implementation Plan (Phase 3 Core)

1. **State Enum (`App\Enums\ConversationState`)**:
   - Strictly typed PHP 8.3 backed string enum representing all 24 states in the authoritative diagram above.
2. **Transition Map & Guards (`App\Services\Diagnosis\ConversationStateMachine`)**:
   - `canTransition(Conversation $conversation, ConversationState $to, array $context = []): bool` validates that `$from -> $to` is an allowed edge in the transition matrix and that guard predicates (e.g., `hasActiveConsent()`, `isConfidenceAboveThreshold()`, `hasVerifiedGuardrails()`) hold.
   - `transitionTo(Conversation $conversation, ConversationState $to, string $reason, array $metadata = []): void`:
     - Wraps state update in a DB transaction with row-level lock (`SELECT ... FOR UPDATE` or Redis lock `conv_lock:{id}`).
     - Updates `conversations.state = $to` and `conversations.state_updated_at = now()`.
     - Writes an immutable entry to `audit_logs` (`event_type = 'state_transition'`, `from_state`, `to_state`, `reason`, `metadata`).
     - Executes the target state's entry action handler.
3. **Per-Tenant Configurable Thresholds (`tenants.boundaries_config`)**:
   - `high_confidence_threshold`: default `0.80`
   - `min_confidence_threshold`: default `0.55`
   - `max_clarifying_questions`: default `5`
   - `followup_days_pest_disease`: default `7`
   - `followup_days_nutrient`: default `14`
   - `followup_days_livestock`: default `3`
   - `stock_freshness_limit_hours`: default `48`
   - `outlet_sla_minutes`: default `30`
   - `rep_sla_minutes`: default `120`

---

## 4. Adaptive Question Selection Flow (`AdaptiveQuestionEngine`)

```mermaid
flowchart TD
    START["Enter AdaptiveQuestionEngine (Turn N of max 5)"] --> CHECK_CONF{"Top Candidate<br/>Confidence >= 0.80?"}
    CHECK_CONF -- Yes --> CHECK_MAND{"Crop, Growth Stage,<br/>Ward & Field Size /<br/>Pfumvudza Known?"}
    CHECK_MAND -- Yes --> EXIT_READY["Transition to DETERMINISTIC_TOOL_EXECUTION"]
    CHECK_MAND -- No --> ASK_MAND["Ask Missing Mandatory Context Question<br/>(Crop / Stage / Ward / Field Size)"]

    CHECK_CONF -- No --> CHECK_COUNT{"Questions Asked<br/>>= 5?"}
    CHECK_COUNT -- Yes --> CHECK_MIN{"Confidence >=<br/>Tenant Min (0.55)?"}
    CHECK_MIN -- Yes --> EXIT_UNCERTAIN["Transition to DETERMINISTIC_TOOL_EXECUTION<br/>(with stated uncertainty caveat)"]
    CHECK_MIN -- No --> EXIT_ESCALATE["Transition to ESCALATED_LOW_CONFIDENCE<br/>(Human Agronomist / Vet Queue)"]

    CHECK_COUNT -- No --> PICK_DISC["Score Unasked Questions in Category Bank<br/>by Discriminative Power between Candidate #1 and #2"]
    PICK_DISC --> FORMAT_WA["Localise Question (EN / SN / ND) +<br/>Attach 2-4 Quick-Reply or Numbered Options"]
```

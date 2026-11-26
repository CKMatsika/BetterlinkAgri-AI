import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'planner_controller.dart';
import 'planner_models.dart';

/// P1c Mobile Farm Planner Screen:
/// - Phone OTP authentication
/// - Enterprise selector shortcuts
/// - One-question-at-a-time resumable interview
/// - Deterministic financial projection & What-If scale simulation
/// - Accept Plan & one-tap requirement activation to Marketplace
class FarmPlannerScreen extends ConsumerStatefulWidget {
  const FarmPlannerScreen({super.key});

  @override
  ConsumerState<FarmPlannerScreen> createState() => _FarmPlannerScreenState();
}

class _FarmPlannerScreenState extends ConsumerState<FarmPlannerScreen> {
  final TextEditingController _phoneCtrl =
      TextEditingController(text: '+263772100200');
  final TextEditingController _otpCtrl = TextEditingController();
  final TextEditingController _answerCtrl = TextEditingController();
  final TextEditingController _whatIfCtrl = TextEditingController();
  bool _otpRequested = false;

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _otpCtrl.dispose();
    _answerCtrl.dispose();
    _whatIfCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(plannerControllerProvider);
    final controller = ref.read(plannerControllerProvider.notifier);

    return Scaffold(
      backgroundColor: const Color(0xFFF8FAFC),
      appBar: AppBar(
        backgroundColor: const Color(0xFF14532D),
        foregroundColor: Colors.white,
        leading: IconButton(
          tooltip: 'Back to Farmer Home',
          icon: const Icon(Icons.arrow_back),
          onPressed: () => context.go('/'),
        ),
        title: const Text('Farm Planner'),
        actions: [
          TextButton.icon(
            onPressed: () => context.go('/marketplace'),
            icon: const Icon(Icons.storefront, color: Colors.white),
            label: const Text(
              'Marketplace',
              style: TextStyle(color: Colors.white),
            ),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (state.statusBanner != null)
            Container(
              margin: const EdgeInsets.only(bottom: 12),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFDCFCE7),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                state.statusBanner!,
                style: const TextStyle(
                  color: Color(0xFF166534),
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          if (state.errorMessage != null)
            Container(
              margin: const EdgeInsets.only(bottom: 12),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFFEE2E2),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                state.errorMessage!,
                style: const TextStyle(
                  color: Color(0xFF991B1B),
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          if (!state.isAuthenticated)
            _buildOtpAuthCard(controller, state)
          else ...[
            _buildEnterpriseSelector(controller, state),
            if (state.activePlan != null) ...[
              const SizedBox(height: 16),
              _buildActivePlanCard(controller, state, state.activePlan!),
            ],
          ],
        ],
      ),
    );
  }

  Widget _buildOtpAuthCard(
    PlannerController controller,
    PlannerSessionState state,
  ) {
    return Card(
      elevation: 1,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Sign in with Zimbabwe Phone OTP',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 8),
            const Text(
              'Your farm plans stay private and synced across Web, WhatsApp, and Mobile.',
              style: TextStyle(color: Colors.black54),
            ),
            const SizedBox(height: 14),
            TextField(
              key: const Key('planner_phone_input'),
              controller: _phoneCtrl,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(
                labelText: 'Phone Number (+263...)',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 12),
            ElevatedButton(
              key: const Key('planner_request_otp_btn'),
              onPressed: state.isLoading
                  ? null
                  : () async {
                      await controller.requestOtp(_phoneCtrl.text);
                      if (mounted) setState(() => _otpRequested = true);
                    },
              child: const Text('Request OTP'),
            ),
            if (_otpRequested || state.debugOtpHint != null) ...[
              const SizedBox(height: 14),
              TextField(
                key: const Key('planner_otp_input'),
                controller: _otpCtrl,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: '6-digit OTP Code',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              FilledButton(
                key: const Key('planner_verify_otp_btn'),
                onPressed: state.isLoading
                    ? null
                    : () => controller.verifyOtp(_phoneCtrl.text, _otpCtrl.text),
                child: const Text('Verify & Continue'),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildEnterpriseSelector(
    PlannerController controller,
    PlannerSessionState state,
  ) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Start a New Farm Plan',
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: state.enterprises.map((e) {
                return ActionChip(
                  key: Key('enterprise_chip_${e.code}'),
                  avatar: const Icon(Icons.agriculture, size: 18),
                  label: Text(e.name),
                  onPressed: state.isLoading
                      ? null
                      : () => controller.startPlan(e.code),
                );
              }).toList(),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildActivePlanCard(
    PlannerController controller,
    PlannerSessionState state,
    FarmPlanDetail plan,
  ) {
    final projection = state.whatIfProjection ?? plan.projection;
    final question = plan.nextQuestion;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Text(
                    plan.title,
                    style: const TextStyle(
                      fontSize: 17,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
                Chip(label: Text(plan.status.toUpperCase())),
              ],
            ),
            if (question != null) ...[
              const Divider(height: 24),
              Text(
                question.prompt,
                style: const TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 10),
              if (question.choices.isNotEmpty)
                Wrap(
                  spacing: 8,
                  children: question.choices
                      .map(
                        (c) => FilledButton.tonal(
                          onPressed: state.isLoading
                              ? null
                              : () => controller.submitAnswer(question.key, c),
                          child: Text(c),
                        ),
                      )
                      .toList(),
                )
              else
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        key: const Key('planner_answer_input'),
                        controller: _answerCtrl,
                        keyboardType: question.inputType == 'number'
                            ? TextInputType.number
                            : TextInputType.text,
                        decoration: const InputDecoration(
                          hintText: 'Enter your answer',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    FilledButton(
                      key: const Key('planner_submit_answer_btn'),
                      onPressed: state.isLoading
                          ? null
                          : () {
                              final raw = _answerCtrl.text.trim();
                              if (raw.isEmpty) return;
                              final parsed = question.inputType == 'number'
                                  ? (double.tryParse(raw) ?? raw)
                                  : raw;
                              _answerCtrl.clear();
                              controller.submitAnswer(question.key, parsed);
                            },
                      child: const Text('Next'),
                    ),
                  ],
                ),
            ],
            if (projection != null) ...[
              const Divider(height: 24),
              const Text(
                'Deterministic Financial Projection',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 8),
              if (projection.numbersWithheld)
                const Text(
                  'Unverified demo prices withheld until local agronomic catalogue is verified.',
                  style: TextStyle(color: Color(0xFFB45309)),
                )
              else
                Text(
                  'Scale: ${projection.scaleValue.toStringAsFixed(0)} ${projection.scaleUnit} | '
                  'Cost: \$${(projection.totalCostUsd ?? 0).toStringAsFixed(2)} | '
                  'Margin: \$${(projection.expectedMarginUsd ?? 0).toStringAsFixed(2)}',
                  key: const Key('planner_projection_summary'),
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: TextField(
                      key: const Key('planner_whatif_input'),
                      controller: _whatIfCtrl,
                      keyboardType: TextInputType.number,
                      decoration: InputDecoration(
                        labelText: 'What-If Scale (${projection.scaleUnit})',
                        border: const OutlineInputBorder(),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  OutlinedButton(
                    key: const Key('planner_whatif_btn'),
                    onPressed: state.isLoading
                        ? null
                        : () {
                            final v = double.tryParse(_whatIfCtrl.text.trim());
                            if (v != null && v > 0) {
                              controller.runWhatIf(v);
                            }
                          },
                    child: const Text('What-If'),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              FilledButton.icon(
                key: const Key('planner_accept_plan_btn'),
                onPressed: state.isLoading || plan.status == 'accepted'
                    ? null
                    : () => controller.acceptActivePlan(),
                icon: const Icon(Icons.check_circle_outline),
                label: const Text('Accept Plan'),
              ),
            ],
            if (plan.requirements.isNotEmpty) ...[
              const Divider(height: 24),
              const Text(
                'Scheduled Input Requirements',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 8),
              ...plan.requirements.map(
                (r) => ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(r.itemName),
                  subtitle: Text(
                    '${(r.quantityScaled / 100).toStringAsFixed(1)} ${r.unit} • ${r.status}',
                  ),
                  trailing: FilledButton.tonal(
                    key: Key('activate_req_${r.id}'),
                    onPressed: state.isLoading || r.status == 'activated'
                        ? null
                        : () =>
                            controller.activateRequirementToMarketplace(r.id),
                    child: const Text('Find Stock'),
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

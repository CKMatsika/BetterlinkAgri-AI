import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../planner/planner_controller.dart';
import '../planner/planner_models.dart';

/// P1c Mobile Farmer Marketplace Screen:
/// - Broadcast stock request to nearby shops (Ring 1/2/3)
/// - Live counters (sent, opened, offers)
/// - Deterministically ranked offers with plain-language explanations
/// - Explicit contact-reveal consent checkbox before accepting an offer
class MarketplaceScreen extends ConsumerStatefulWidget {
  const MarketplaceScreen({super.key});

  @override
  ConsumerState<MarketplaceScreen> createState() => _MarketplaceScreenState();
}

class _MarketplaceScreenState extends ConsumerState<MarketplaceScreen> {
  final TextEditingController _itemCtrl = TextEditingController();
  final TextEditingController _qtyCtrl = TextEditingController(text: '2');
  String _unit = 'bags';
  bool _grantContactConsent = true;

  @override
  void dispose() {
    _itemCtrl.dispose();
    _qtyCtrl.dispose();
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
        title: const Text('Input Marketplace'),
        actions: [
          IconButton(
            tooltip: 'Refresh Requests',
            icon: const Icon(Icons.refresh),
            onPressed:
                state.isAuthenticated ? () => controller.refreshMarketplace() : null,
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
          if (!state.isAuthenticated)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Text(
                      'Sign in via Phone OTP to request quotes from nearby shops.',
                      style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(height: 12),
                    FilledButton(
                      onPressed: () => context.go('/planner'),
                      child: const Text('Go to Phone OTP Sign-In'),
                    ),
                  ],
                ),
              ),
            )
          else ...[
            _buildNewRequestCard(controller, state),
            const SizedBox(height: 16),
            const Text(
              'Your Live Requests & Ranked Offers',
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 8),
            CheckboxListTile(
              key: const Key('marketplace_consent_checkbox'),
              contentPadding: EdgeInsets.zero,
              title: const Text(
                'Share my phone number ONLY with the winning shop when I accept an offer (CDPA Consent)',
                style: TextStyle(fontSize: 13),
              ),
              value: _grantContactConsent,
              onChanged: (v) => setState(() => _grantContactConsent = v ?? false),
            ),
            const SizedBox(height: 8),
            if (state.marketplaceRequests.isEmpty)
              const Card(
                child: Padding(
                  padding: EdgeInsets.all(16),
                  child: Text('No requests yet. Create one above to broadcast to Ring 1 stockists.'),
                ),
              )
            else
              ...state.marketplaceRequests.map(
                (req) => _buildRequestCard(controller, state, req),
              ),
          ],
        ],
      ),
    );
  }

  Widget _buildNewRequestCard(
    PlannerController controller,
    PlannerSessionState state,
  ) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Broadcast New Input Request',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 10),
            TextField(
              key: const Key('marketplace_item_input'),
              controller: _itemCtrl,
              decoration: const InputDecoration(
                labelText: 'Product / Input (e.g. Compound D 50kg)',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    key: const Key('marketplace_qty_input'),
                    controller: _qtyCtrl,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'Quantity',
                      border: OutlineInputBorder(),
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                DropdownButton<String>(
                  value: _unit,
                  items: const [
                    DropdownMenuItem(value: 'bags', child: Text('bags')),
                    DropdownMenuItem(value: 'bottles', child: Text('bottles')),
                    DropdownMenuItem(value: 'litres', child: Text('litres')),
                    DropdownMenuItem(value: 'kg', child: Text('kg')),
                  ],
                  onChanged: (v) {
                    if (v != null) setState(() => _unit = v);
                  },
                ),
              ],
            ),
            const SizedBox(height: 12),
            FilledButton.icon(
              key: const Key('marketplace_broadcast_btn'),
              onPressed: state.isLoading
                  ? null
                  : () {
                      final name = _itemCtrl.text.trim();
                      final qty = double.tryParse(_qtyCtrl.text.trim()) ?? 1;
                      if (name.isEmpty) return;
                      _itemCtrl.clear();
                      controller.createStockRequest(
                        itemName: name,
                        quantityScaled: (qty * 100).round(),
                        unit: _unit,
                      );
                    },
              icon: const Icon(Icons.campaign),
              label: const Text('Send to Nearby Shops'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildRequestCard(
    PlannerController controller,
    PlannerSessionState state,
    MarketplaceRequestItemModel req,
  ) {
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Text(
                    req.itemSummary,
                    style: const TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
                Chip(label: Text(req.status)),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              'Ring ${req.currentRing} • Sent: ${req.sentCount} • Opened: ${req.openedCount} • Offers: ${req.offersCount}',
              style: const TextStyle(color: Colors.black54, fontSize: 13),
            ),
            if (req.offers.isNotEmpty) ...[
              const Divider(height: 20),
              ...req.offers.map(
                (offer) => ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(
                    '${offer.outletName} — ${offer.currency} ${(offer.totalPriceMinor / 100).toStringAsFixed(2)}',
                    style: const TextStyle(fontWeight: FontWeight.w600),
                  ),
                  subtitle: Text(offer.summary),
                  trailing: offer.status == 'pending'
                      ? FilledButton(
                          key: Key('accept_offer_${offer.id}'),
                          onPressed: state.isLoading
                              ? null
                              : () => controller.acceptOffer(
                                    req.id,
                                    offer.id,
                                    grantContactRevealConsent:
                                        _grantContactConsent,
                                  ),
                          child: const Text('Accept'),
                        )
                      : Chip(label: Text(offer.status.toUpperCase())),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

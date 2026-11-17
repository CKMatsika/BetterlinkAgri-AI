import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/offline_sync_service.dart';

/// Section 15: Farmer Mode Screen
/// Light, high-contrast UI, large touch targets, low-literacy-friendly icons,
/// Language selector (EN / ChiShona / isiNdebele), Context Chip, Camera/Voice/Text chat,
/// Nearest Outlets, Weather & Tips, Events, and Talk to a Rep.
class FarmerHomeScreen extends ConsumerStatefulWidget {
  const FarmerHomeScreen({super.key});

  @override
  ConsumerState<FarmerHomeScreen> createState() => _FarmerHomeScreenState();
}

class _FarmerHomeScreenState extends ConsumerState<FarmerHomeScreen> {
  String _selectedLanguage = 'en';
  String _contextChip = 'Maize / V6 vegetative / NR IIa';
  final TextEditingController _inputController = TextEditingController();
  final List<Map<String, dynamic>> _messages = [
    {
      'sender': 'agent',
      'text':
          'Mhoroi / Salibonani / Welcome! Tap the Camera button to photograph any crop pest, leaf disease, nutrient problem, or livestock issue.',
      'buttons': [
        {'id': '1', 'title': '1. Yes, I agree (CDPA Consent)'},
      ],
    }
  ];

  Future<void> _sendMessage(String text) async {
    if (text.trim().isEmpty) return;
    setState(() {
      _messages.add({'sender': 'farmer', 'text': text, 'buttons': []});
    });
    _inputController.clear();

    final syncService = ref.read(offlineSyncProvider);
    final result = await syncService.sendOrQueueTurn(
      QueuedOutgoingTurn(
        id: DateTime.now().millisecondsSinceEpoch.toString(),
        phone: '+263772999000',
        language: _selectedLanguage,
        messageText: text,
        queuedAt: DateTime.now(),
      ),
    );

    if (!mounted) return;
    setState(() {
      _contextChip = (result['context_chip'] ?? _contextChip).toString();
      _messages.add({
        'sender': 'agent',
        'text': (result['reply_text'] ?? '').toString(),
        'buttons': (result['buttons'] as List?) ?? [],
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    final pendingOfflineCount = ref.watch(offlineSyncProvider).pendingQueue.length;

    return Scaffold(
      backgroundColor: const Color(0xFFF8FAFC),
      appBar: AppBar(
        backgroundColor: const Color(0xFF14532D),
        foregroundColor: Colors.white,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'BetterlinkAgr AI Mudhumeni',
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
            ),
            Text(
              '🌱 $_contextChip',
              style: const TextStyle(fontSize: 12, color: Color(0xFFBBF7D0)),
            ),
          ],
        ),
        actions: [
          DropdownButton<String>(
            value: _selectedLanguage,
            dropdownColor: const Color(0xFF14532D),
            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
            underline: const SizedBox.shrink(),
            items: const [
              DropdownMenuItem(value: 'en', child: Text('EN')),
              DropdownMenuItem(value: 'sn', child: Text('Shona')),
              DropdownMenuItem(value: 'nd', child: Text('Ndebele')),
            ],
            onChanged: (val) {
              if (val != null) setState(() => _selectedLanguage = val);
            },
          ),
          IconButton(
            tooltip: 'Switch to Field Rep Mode',
            icon: const Icon(Icons.badge_outlined),
            onPressed: () => context.go('/rep'),
          ),
        ],
      ),
      body: Column(
        children: [
          if (pendingOfflineCount > 0)
            Container(
              width: double.infinity,
              color: const Color(0xFFFEF3C7),
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: Text(
                '📶 Offline Queue: $pendingOfflineCount message(s) waiting for connection',
                style: const TextStyle(
                  color: Color(0xFF92400E),
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),

          // High-Contrast Quick Action Tiles (Large Touch Targets for Low-Literacy Usability)
          Padding(
            padding: const EdgeInsets.all(10),
            child: Row(
              children: [
                Expanded(
                  child: _LargeActionTile(
                    icon: Icons.photo_camera,
                    label: 'Photo Diagnosis',
                    color: const Color(0xFF15803D),
                    onTap: () => _sendMessage('Caterpillar in maize funnel'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _LargeActionTile(
                    icon: Icons.storefront,
                    label: 'Nearest Outlets',
                    color: const Color(0xFF0369A1),
                    onTap: () => _sendMessage('Where to buy in Glendale'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _LargeActionTile(
                    icon: Icons.support_agent,
                    label: 'Talk to a Rep',
                    color: const Color(0xFFB45309),
                    onTap: () => _sendMessage('Speak to a rep'),
                  ),
                ),
              ],
            ),
          ),

          // Chat & Rich Cards Stream
          Expanded(
            child: ListView.builder(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              itemCount: _messages.length,
              itemBuilder: (context, idx) {
                final m = _messages[idx];
                final isFarmer = m['sender'] == 'farmer';
                final buttons = (m['buttons'] as List?) ?? [];

                return Align(
                  alignment: isFarmer ? Alignment.centerRight : Alignment.centerLeft,
                  child: Container(
                    margin: const EdgeInsets.only(bottom: 10),
                    padding: const EdgeInsets.all(14),
                    constraints: const BoxConstraints(maxWidth: 330),
                    decoration: BoxDecoration(
                      color: isFarmer ? const Color(0xFF15803D) : Colors.white,
                      borderRadius: BorderRadius.circular(14),
                      border: isFarmer ? null : Border.all(color: const Color(0xFFCBD5E1)),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          (m['text'] ?? '').toString(),
                          style: TextStyle(
                            fontSize: 15,
                            height: 1.4,
                            color: isFarmer ? Colors.white : const Color(0xFF0F172A),
                          ),
                        ),
                        if (buttons.isNotEmpty) ...[
                          const SizedBox(height: 10),
                          Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: buttons.map((b) {
                              final btnMap = b as Map;
                              return ElevatedButton(
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: const Color(0xFFECFDF5),
                                  foregroundColor: const Color(0xFF047857),
                                  minimumSize: const Size(100, 44),
                                ),
                                onPressed: () => _sendMessage(btnMap['id'].toString()),
                                child: Text(btnMap['title'].toString()),
                              );
                            }).toList(),
                          ),
                        ],
                      ],
                    ),
                  ),
                );
              },
            ),
          ),

          // Bottom Input Bar (Camera, Voice Note, Text)
          Container(
            padding: const EdgeInsets.all(10),
            color: Colors.white,
            child: Row(
              children: [
                IconButton(
                  iconSize: 30,
                  color: const Color(0xFF15803D),
                  icon: const Icon(Icons.camera_alt),
                  onPressed: () => _sendMessage('Caterpillar in maize funnel'),
                ),
                IconButton(
                  iconSize: 30,
                  color: const Color(0xFF0369A1),
                  icon: const Icon(Icons.mic),
                  onPressed: () => _sendMessage('Chibage changu chine makonye'),
                ),
                Expanded(
                  child: TextField(
                    controller: _inputController,
                    decoration: InputDecoration(
                      hintText: 'Type or tap microphone...',
                      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(28)),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF15803D),
                    foregroundColor: Colors.white,
                    minimumSize: const Size(64, 48),
                  ),
                  onPressed: () => _sendMessage(_inputController.text),
                  child: const Text('Send'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _LargeActionTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback onTap;

  const _LargeActionTile({
    required this.icon,
    required this.label,
    required this.color,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.1),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: color, width: 1.5),
        ),
        child: Column(
          children: [
            Icon(icon, color: color, size: 28),
            const SizedBox(height: 4),
            Text(
              label,
              textAlign: TextAlign.center,
              style: TextStyle(
                color: color,
                fontWeight: FontWeight.bold,
                fontSize: 12,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

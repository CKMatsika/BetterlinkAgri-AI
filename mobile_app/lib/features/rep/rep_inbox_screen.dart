import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

/// Section 15: Field Sales Representative Mode Screen
/// Lead inbox with Hot/Warm/Cold badges, Accept/Decline actions, Connected Farmer Profile
/// context, Call and WhatsApp buttons, Meeting Calendar, and Farm Visit Notes with photos.
class RepInboxScreen extends StatefulWidget {
  const RepInboxScreen({super.key});

  @override
  State<RepInboxScreen> createState() => _RepInboxScreenState();
}

class _RepInboxScreenState extends State<RepInboxScreen> {
  final List<Map<String, dynamic>> _leads = [
    {
      'id': 'LEAD-901',
      'farmer_name': 'Rumbidzai Moyo (FRM-88A12C)',
      'phone': '+263775888999',
      'ward': 'Mazowe Ward 14 (Glendale) • NR IIa',
      'crop_stage': 'Maize / V6 vegetative (1.5 ha)',
      'diagnosis': 'Fall Armyworm (91% confidence)',
      'products': '2x CoragenPro 200ml (US$76.00 / ZiG 1,938.00)',
      'temperature': 'HOT',
      'sla_remaining': '18 mins remaining',
      'status': 'pending',
    },
    {
      'id': 'LEAD-902',
      'farmer_name': 'Taurai Chikwanda (FRM-44B09D)',
      'phone': '+263772111222',
      'ward': 'Bindura Communal Ward 8 • NR IIa',
      'crop_stage': 'Maize / V4 top-dress (2 Pfumvudza plots)',
      'diagnosis': 'Nitrogen (N) Deficiency (85% confidence)',
      'products': '1x Sable AN 50kg (US$38.00 / ZiG 969.00)',
      'temperature': 'WARM',
      'sla_remaining': '1h 12m remaining',
      'status': 'accepted',
    },
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF1F5F9),
      appBar: AppBar(
        backgroundColor: const Color(0xFF0F172A),
        foregroundColor: Colors.white,
        title: const Text('Field Rep Portal — Lead Inbox & Visits'),
        actions: [
          TextButton.icon(
            onPressed: () => context.go('/'),
            icon: const Icon(Icons.agriculture, color: Colors.white),
            label: const Text('Farmer View', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
      body: ListView.builder(
        padding: const EdgeInsets.all(14),
        itemCount: _leads.length,
        itemBuilder: (context, idx) {
          final lead = _leads[idx];
          final isHot = lead['temperature'] == 'HOT';

          return Card(
            margin: const EdgeInsets.only(bottom: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: isHot ? const Color(0xFFFEE2E2) : const Color(0xFFFEF3C7),
                          borderRadius: BorderRadius.circular(99),
                        ),
                        child: Text(
                          '${lead['temperature']} LEAD • ${lead['sla_remaining']}',
                          style: TextStyle(
                            fontWeight: FontWeight.bold,
                            fontSize: 12,
                            color: isHot ? const Color(0xFF991B1B) : const Color(0xFF92400E),
                          ),
                        ),
                      ),
                      Text(
                        'Status: ${lead['status'].toString().toUpperCase()}',
                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Text(
                    lead['farmer_name'].toString(),
                    style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
                  ),
                  Text('📍 ${lead['ward']} • 🌱 ${lead['crop_stage']}'),
                  const SizedBox(height: 6),
                  Text(
                    '🔍 Diagnosis: ${lead['diagnosis']}',
                    style: const TextStyle(fontWeight: FontWeight.w600, color: Color(0xFF14532D)),
                  ),
                  Text('📦 Basket: ${lead['products']}'),
                  const Divider(height: 24),
                  Wrap(
                    spacing: 10,
                    runSpacing: 8,
                    children: [
                      if (lead['status'] == 'pending')
                        ElevatedButton.icon(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF15803D),
                            foregroundColor: Colors.white,
                          ),
                          onPressed: () {
                            setState(() => lead['status'] = 'accepted');
                          },
                          icon: const Icon(Icons.check),
                          label: const Text('Accept Lead'),
                        ),
                      OutlinedButton.icon(
                        onPressed: () {},
                        icon: const Icon(Icons.phone),
                        label: const Text('Call Farmer'),
                      ),
                      OutlinedButton.icon(
                        onPressed: () {},
                        icon: const Icon(Icons.chat),
                        label: const Text('WhatsApp Takeover'),
                      ),
                      OutlinedButton.icon(
                        onPressed: () {},
                        icon: const Icon(Icons.note_add),
                        label: const Text('Log Farm Visit & Photo'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

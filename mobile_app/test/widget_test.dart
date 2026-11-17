import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:betterlink_agr_mobile/main.dart';

void main() {
  testWidgets('FarmerHomeScreen and RepInboxScreen render and navigate cleanly', (WidgetTester tester) async {
    await tester.pumpWidget(const ProviderScope(child: BetterlinkAgrApp()));
    await tester.pumpAndSettle();

    // Verify Farmer Mode Screen renders Context Chip, Quick Action Tiles, and Chat Stream
    expect(find.text('BetterlinkAgr AI Mudhumeni'), findsOneWidget);
    expect(find.text('Photo Diagnosis'), findsOneWidget);
    expect(find.text('Nearest Outlets'), findsOneWidget);
    expect(find.text('Talk to a Rep'), findsOneWidget);

    // Navigate to Field Rep Mode
    await tester.tap(find.byTooltip('Switch to Field Rep Mode'));
    await tester.pumpAndSettle();

    // Verify Rep Mode Lead Inbox renders Hot/Warm Leads and Accept Lead button
    expect(find.text('Field Rep Portal — Lead Inbox & Visits'), findsOneWidget);
    expect(find.textContaining('Rumbidzai Moyo'), findsOneWidget);
    expect(find.text('Accept Lead'), findsOneWidget);
  });
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'features/farmer/farmer_home_screen.dart';
import 'features/rep/rep_inbox_screen.dart';

final _router = GoRouter(
  initialLocation: '/',
  routes: [
    GoRoute(
      path: '/',
      builder: (context, state) => const FarmerHomeScreen(),
    ),
    GoRoute(
      path: '/rep',
      builder: (context, state) => const RepInboxScreen(),
    ),
  ],
);

void main() {
  runApp(const ProviderScope(child: BetterlinkAgrApp()));
}

class BetterlinkAgrApp extends StatelessWidget {
  const BetterlinkAgrApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'BetterlinkAgr AI Zimbabwe',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xFF15803D),
          brightness: Brightness.light,
        ),
        useMaterial3: true,
      ),
      routerConfig: _router,
    );
  }
}

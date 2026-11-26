import 'package:betterlink_agr_mobile/features/planner/planner_api_client.dart';
import 'package:betterlink_agr_mobile/features/planner/planner_models.dart';
import 'package:betterlink_agr_mobile/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

class _FakePlannerApiClient implements PlannerApiClient {
  bool acceptedOffer = false;
  bool? lastContactConsent;
  double lastWhatIfScale = 500;

  @override
  Future<String?> requestOtp(String phone) async => '123456';

  @override
  Future<String> verifyOtp(String phone, String otp) async => 'test_token_p1c';

  @override
  Future<List<PlanEnterpriseItem>> fetchEnterprises() async => const [
        PlanEnterpriseItem(
          code: 'broilers',
          name: 'Broilers',
          category: 'livestock',
          defaultScaleUnit: 'birds',
        ),
        PlanEnterpriseItem(
          code: 'maize',
          name: 'Maize',
          category: 'crop',
          defaultScaleUnit: 'ha',
        ),
      ];

  @override
  Future<List<FarmPlanDetail>> fetchPlans(String token) async => const [];

  @override
  Future<FarmPlanDetail> createPlan(String token, String enterpriseCode) async {
    return const FarmPlanDetail(
      id: 'plan-1',
      title: 'Broilers Production Plan',
      enterpriseCode: 'broilers',
      status: 'interviewing',
      scaleValue: 0,
      scaleUnit: 'birds',
      nextQuestion: PlanInterviewQuestion(
        key: 'scale_value',
        prompt: 'How many broiler chicks do you plan to raise?',
        inputType: 'number',
      ),
    );
  }

  @override
  Future<FarmPlanDetail> answerQuestion(
    String token,
    String planId,
    String questionKey,
    dynamic answerValue,
  ) async {
    final scale = (answerValue is num) ? answerValue.toDouble() : 500.0;
    return FarmPlanDetail(
      id: planId,
      title: 'Broilers Production Plan',
      enterpriseCode: 'broilers',
      status: 'projected',
      scaleValue: scale,
      scaleUnit: 'birds',
      projection: PlanProjectionSummary(
        scaleValue: scale,
        scaleUnit: 'birds',
        totalCostUsd: scale * 3.5,
        expectedRevenueUsd: scale * 5.5,
        expectedMarginUsd: scale * 2.0,
      ),
      requirements: const [
        PlanRequirementItem(
          id: 'req-1',
          itemName: 'Broiler Starter Mash 50kg',
          quantityScaled: 1000,
          unit: 'bags',
          status: 'scheduled',
        ),
      ],
    );
  }

  @override
  Future<PlanProjectionSummary> runWhatIf(
    String token,
    String planId,
    double scaleValue,
  ) async {
    lastWhatIfScale = scaleValue;
    return PlanProjectionSummary(
      scaleValue: scaleValue,
      scaleUnit: 'birds',
      totalCostUsd: scaleValue * 3.5,
      expectedRevenueUsd: scaleValue * 5.5,
      expectedMarginUsd: scaleValue * 2.0,
    );
  }

  @override
  Future<FarmPlanDetail> acceptPlan(String token, String planId) async {
    return const FarmPlanDetail(
      id: 'plan-1',
      title: 'Broilers Production Plan',
      enterpriseCode: 'broilers',
      status: 'accepted',
      scaleValue: 500,
      scaleUnit: 'birds',
      projection: PlanProjectionSummary(
        scaleValue: 500,
        scaleUnit: 'birds',
        totalCostUsd: 1750,
        expectedRevenueUsd: 2750,
        expectedMarginUsd: 1000,
      ),
      requirements: [
        PlanRequirementItem(
          id: 'req-1',
          itemName: 'Broiler Starter Mash 50kg',
          quantityScaled: 1000,
          unit: 'bags',
          status: 'scheduled',
        ),
      ],
    );
  }

  @override
  Future<MarketplaceRequestItemModel> activatePlanRequirement(
    String token,
    String requirementId,
    double latitude,
    double longitude,
  ) async {
    return _sampleRequest();
  }

  @override
  Future<List<MarketplaceRequestItemModel>> fetchMarketplaceRequests(
    String token,
  ) async =>
      [_sampleRequest()];

  @override
  Future<MarketplaceRequestItemModel> createMarketplaceRequest(
    String token, {
    required String itemName,
    required int quantityScaled,
    required String unit,
    required double latitude,
    required double longitude,
    String deliveryPreference = 'either',
  }) async =>
      _sampleRequest();

  @override
  Future<Map<String, dynamic>> acceptOffer(
    String token,
    String requestId,
    String offerId, {
    required bool grantContactRevealConsent,
  }) async {
    acceptedOffer = true;
    lastContactConsent = grantContactRevealConsent;
    return {'order': {'id': 'order-1', 'status': 'created'}};
  }

  MarketplaceRequestItemModel _sampleRequest() => MarketplaceRequestItemModel(
        id: 'mreq-1',
        status: acceptedOffer ? 'OFFER_ACCEPTED' : 'OFFERS_RECEIVED',
        currentRing: 1,
        itemSummary: 'Broiler Starter Mash 50kg (10.0 bags)',
        sentCount: 3,
        openedCount: 2,
        offersCount: 1,
        offers: [
          MarketplaceOfferItem(
            id: 'offer-1',
            outletName: 'Glendale Agro Hub',
            totalPriceMinor: 31000,
            currency: 'USD',
            fulfilmentType: 'pickup',
            status: acceptedOffer ? 'accepted' : 'pending',
            rankScoreScaled: 9200,
            summary: 'Ring 1 shop, total USD 310.00, fresh verified stock.',
          ),
        ],
      );
}

void main() {
  testWidgets(
    'P1c end-to-end Flutter flow: OTP sign-in, Farm Planner interview + What-If + Accept, and Marketplace offer acceptance with CDPA consent',
    (WidgetTester tester) async {
      final fakeApi = _FakePlannerApiClient();

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            plannerApiClientProvider.overrideWithValue(fakeApi),
          ],
          child: const BetterlinkAgrApp(),
        ),
      );
      await tester.pumpAndSettle();

      // 1. Navigate from FarmerHomeScreen to FarmPlannerScreen
      await tester.tap(find.byKey(const Key('nav_farm_planner_btn')));
      await tester.pumpAndSettle();
      expect(find.text('Sign in with Zimbabwe Phone OTP'), findsOneWidget);

      // 2. Request & verify OTP
      await tester.tap(find.byKey(const Key('planner_request_otp_btn')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('planner_otp_input')), '123456');
      await tester.tap(find.byKey(const Key('planner_verify_otp_btn')));
      await tester.pumpAndSettle();

      // 3. Start Broilers plan & answer interview question
      await tester.tap(find.byKey(const Key('enterprise_chip_broilers')));
      await tester.pumpAndSettle();
      expect(
        find.text('How many broiler chicks do you plan to raise?'),
        findsOneWidget,
      );

      await tester.enterText(
        find.byKey(const Key('planner_answer_input')),
        '500',
      );
      await tester.tap(find.byKey(const Key('planner_submit_answer_btn')));
      await tester.pumpAndSettle();

      expect(
        find.textContaining('Scale: 500 birds | Cost: \$1750.00 | Margin: \$1000.00'),
        findsOneWidget,
      );

      // 4. Run What-If scale simulation (1000 birds)
      await tester.enterText(
        find.byKey(const Key('planner_whatif_input')),
        '1000',
      );
      await tester.tap(find.byKey(const Key('planner_whatif_btn')));
      await tester.pumpAndSettle();
      expect(fakeApi.lastWhatIfScale, 1000);
      expect(
        find.textContaining('Scale: 1000 birds | Cost: \$3500.00 | Margin: \$2000.00'),
        findsOneWidget,
      );

      // 5. Accept plan
      await tester.tap(find.byKey(const Key('planner_accept_plan_btn')));
      await tester.pumpAndSettle();
      expect(find.text('ACCEPTED'), findsOneWidget);

      // 6. Navigate to Marketplace and accept ranked offer with explicit consent
      await tester.tap(find.text('Marketplace'));
      await tester.pumpAndSettle();
      expect(find.text('Input Marketplace'), findsOneWidget);
      expect(find.textContaining('Glendale Agro Hub'), findsOneWidget);

      final acceptBtn = find.byKey(const Key('accept_offer_offer-1'));
      await tester.ensureVisible(acceptBtn);
      await tester.pumpAndSettle();
      await tester.tap(acceptBtn);
      await tester.pumpAndSettle();

      expect(fakeApi.acceptedOffer, isTrue);
      expect(fakeApi.lastContactConsent, isTrue);
      expect(find.text('OFFER_ACCEPTED'), findsOneWidget);
    },
  );
}

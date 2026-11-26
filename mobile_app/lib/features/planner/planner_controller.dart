import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'planner_api_client.dart';
import 'planner_models.dart';

class PlannerSessionState {
  final String? token;
  final String? phone;
  final String? debugOtpHint;
  final bool isLoading;
  final String? errorMessage;
  final String? statusBanner;
  final List<PlanEnterpriseItem> enterprises;
  final List<FarmPlanDetail> plans;
  final FarmPlanDetail? activePlan;
  final PlanProjectionSummary? whatIfProjection;
  final List<MarketplaceRequestItemModel> marketplaceRequests;

  const PlannerSessionState({
    this.token,
    this.phone,
    this.debugOtpHint,
    this.isLoading = false,
    this.errorMessage,
    this.statusBanner,
    this.enterprises = const [],
    this.plans = const [],
    this.activePlan,
    this.whatIfProjection,
    this.marketplaceRequests = const [],
  });

  bool get isAuthenticated => token != null && token!.isNotEmpty;

  PlannerSessionState copyWith({
    String? token,
    String? phone,
    String? debugOtpHint,
    bool? isLoading,
    String? errorMessage,
    String? statusBanner,
    List<PlanEnterpriseItem>? enterprises,
    List<FarmPlanDetail>? plans,
    FarmPlanDetail? activePlan,
    PlanProjectionSummary? whatIfProjection,
    List<MarketplaceRequestItemModel>? marketplaceRequests,
    bool clearError = false,
    bool clearBanner = false,
  }) {
    return PlannerSessionState(
      token: token ?? this.token,
      phone: phone ?? this.phone,
      debugOtpHint: debugOtpHint ?? this.debugOtpHint,
      isLoading: isLoading ?? this.isLoading,
      errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
      statusBanner: clearBanner ? null : (statusBanner ?? this.statusBanner),
      enterprises: enterprises ?? this.enterprises,
      plans: plans ?? this.plans,
      activePlan: activePlan ?? this.activePlan,
      whatIfProjection: whatIfProjection ?? this.whatIfProjection,
      marketplaceRequests: marketplaceRequests ?? this.marketplaceRequests,
    );
  }
}

class PlannerController extends StateNotifier<PlannerSessionState> {
  final PlannerApiClient _api;

  PlannerController(this._api) : super(const PlannerSessionState());

  Future<void> loadEnterprises() async {
    state = state.copyWith(isLoading: true, clearError: true);
    try {
      final list = await _api.fetchEnterprises();
      state = state.copyWith(isLoading: false, enterprises: list);
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Unable to load enterprises: $e',
      );
    }
  }

  Future<void> requestOtp(String phone) async {
    state = state.copyWith(
      isLoading: true,
      phone: phone.trim(),
      clearError: true,
      clearBanner: true,
    );
    try {
      final debugOtp = await _api.requestOtp(phone.trim());
      state = state.copyWith(
        isLoading: false,
        debugOtpHint: debugOtp,
        statusBanner: 'OTP sent to ${phone.trim()}. Enter your 6-digit code.',
      );
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'OTP request failed: $e',
      );
    }
  }

  Future<void> verifyOtp(String phone, String otp) async {
    state = state.copyWith(isLoading: true, clearError: true, clearBanner: true);
    try {
      final token = await _api.verifyOtp(phone.trim(), otp.trim());
      final enterprises = state.enterprises.isEmpty
          ? await _api.fetchEnterprises()
          : state.enterprises;
      final plans = await _api.fetchPlans(token);
      final requests = await _api.fetchMarketplaceRequests(token);

      state = state.copyWith(
        isLoading: false,
        token: token,
        phone: phone.trim(),
        enterprises: enterprises,
        plans: plans,
        marketplaceRequests: requests,
        statusBanner: 'Signed in as ${phone.trim()}.',
      );
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'OTP verification failed: $e',
      );
    }
  }

  Future<void> startPlan(String enterpriseCode) async {
    final token = state.token;
    if (token == null) return;

    state = state.copyWith(isLoading: true, clearError: true, clearBanner: true);
    try {
      final created = await _api.createPlan(token, enterpriseCode);
      state = state.copyWith(
        isLoading: false,
        activePlan: created,
        whatIfProjection: created.projection,
        plans: [created, ...state.plans],
      );
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Could not start plan: $e',
      );
    }
  }

  Future<void> submitAnswer(String questionKey, dynamic value) async {
    final token = state.token;
    final plan = state.activePlan;
    if (token == null || plan == null) return;

    state = state.copyWith(isLoading: true, clearError: true);
    try {
      final updated = await _api.answerQuestion(
        token,
        plan.id,
        questionKey,
        value,
      );
      state = state.copyWith(
        isLoading: false,
        activePlan: updated,
        whatIfProjection: updated.projection,
      );
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Could not save answer: $e',
      );
    }
  }

  Future<void> runWhatIf(double newScaleValue) async {
    final token = state.token;
    final plan = state.activePlan;
    if (token == null || plan == null) return;

    state = state.copyWith(isLoading: true, clearError: true);
    try {
      final projection = await _api.runWhatIf(token, plan.id, newScaleValue);
      state = state.copyWith(
        isLoading: false,
        whatIfProjection: projection,
      );
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'What-If calculation failed: $e',
      );
    }
  }

  Future<void> acceptActivePlan() async {
    final token = state.token;
    final plan = state.activePlan;
    if (token == null || plan == null) return;

    state = state.copyWith(isLoading: true, clearError: true);
    try {
      final accepted = await _api.acceptPlan(token, plan.id);
      state = state.copyWith(
        isLoading: false,
        activePlan: accepted,
        statusBanner: 'Plan accepted! Requirements scheduled for marketplace.',
      );
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Could not accept plan: $e',
      );
    }
  }

  Future<void> activateRequirementToMarketplace(String requirementId) async {
    final token = state.token;
    if (token == null) return;

    state = state.copyWith(isLoading: true, clearError: true);
    try {
      final req = await _api.activatePlanRequirement(
        token,
        requirementId,
        -17.8292,
        31.0522,
      );
      state = state.copyWith(
        isLoading: false,
        marketplaceRequests: [req, ...state.marketplaceRequests],
        statusBanner: 'Broadcasted requirement to nearby stockists!',
      );
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Activation failed: $e',
      );
    }
  }

  Future<void> refreshMarketplace() async {
    final token = state.token;
    if (token == null) return;

    state = state.copyWith(isLoading: true, clearError: true);
    try {
      final requests = await _api.fetchMarketplaceRequests(token);
      state = state.copyWith(isLoading: false, marketplaceRequests: requests);
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Unable to refresh marketplace: $e',
      );
    }
  }

  Future<void> createStockRequest({
    required String itemName,
    required int quantityScaled,
    required String unit,
  }) async {
    final token = state.token;
    if (token == null) return;

    state = state.copyWith(isLoading: true, clearError: true);
    try {
      final req = await _api.createMarketplaceRequest(
        token,
        itemName: itemName,
        quantityScaled: quantityScaled,
        unit: unit,
        latitude: -17.8292,
        longitude: 31.0522,
      );
      state = state.copyWith(
        isLoading: false,
        marketplaceRequests: [req, ...state.marketplaceRequests],
        statusBanner: 'Request sent to Ring 1 stockists!',
      );
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Could not create request: $e',
      );
    }
  }

  Future<void> acceptOffer(
    String requestId,
    String offerId, {
    required bool grantContactRevealConsent,
  }) async {
    final token = state.token;
    if (token == null) return;

    state = state.copyWith(isLoading: true, clearError: true);
    try {
      await _api.acceptOffer(
        token,
        requestId,
        offerId,
        grantContactRevealConsent: grantContactRevealConsent,
      );
      final refreshed = await _api.fetchMarketplaceRequests(token);
      state = state.copyWith(
        isLoading: false,
        marketplaceRequests: refreshed,
        statusBanner: 'Offer accepted! Order confirmed with winning stockist.',
      );
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Could not accept offer: $e',
      );
    }
  }
}

final plannerControllerProvider =
    StateNotifierProvider<PlannerController, PlannerSessionState>((ref) {
  return PlannerController(ref.watch(plannerApiClientProvider));
});

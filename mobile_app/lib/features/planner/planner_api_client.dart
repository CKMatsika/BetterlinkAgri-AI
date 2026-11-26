import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/offline_sync_service.dart';
import 'planner_models.dart';

/// Contract for P1c Farmer Planner & Marketplace API operations.
abstract class PlannerApiClient {
  Future<String?> requestOtp(String phone);
  Future<String> verifyOtp(String phone, String otp);
  Future<List<PlanEnterpriseItem>> fetchEnterprises();
  Future<List<FarmPlanDetail>> fetchPlans(String token);
  Future<FarmPlanDetail> createPlan(String token, String enterpriseCode);
  Future<FarmPlanDetail> answerQuestion(
    String token,
    String planId,
    String questionKey,
    dynamic answerValue,
  );
  Future<PlanProjectionSummary> runWhatIf(
    String token,
    String planId,
    double scaleValue,
  );
  Future<FarmPlanDetail> acceptPlan(String token, String planId);
  Future<MarketplaceRequestItemModel> activatePlanRequirement(
    String token,
    String requirementId,
    double latitude,
    double longitude,
  );
  Future<List<MarketplaceRequestItemModel>> fetchMarketplaceRequests(
    String token,
  );
  Future<MarketplaceRequestItemModel> createMarketplaceRequest(
    String token, {
    required String itemName,
    required int quantityScaled,
    required String unit,
    required double latitude,
    required double longitude,
    String deliveryPreference = 'either',
  });
  Future<Map<String, dynamic>> acceptOffer(
    String token,
    String requestId,
    String offerId, {
    required bool grantContactRevealConsent,
  });
}

class DioPlannerApiClient implements PlannerApiClient {
  final Dio _dio;

  DioPlannerApiClient(this._dio);

  Options _authOptions(String token) => Options(
        headers: {
          'Authorization': 'Bearer $token',
          'Accept': 'application/json',
        },
      );

  @override
  Future<String?> requestOtp(String phone) async {
    final res = await _dio.post(
      '/api/v1/planner/auth/request-otp',
      data: {'phone': phone},
    );
    final data = res.data as Map<String, dynamic>;
    return data['debug_otp']?.toString();
  }

  @override
  Future<String> verifyOtp(String phone, String otp) async {
    final res = await _dio.post(
      '/api/v1/planner/auth/verify-otp',
      data: {'phone': phone, 'otp': otp},
    );
    final data = res.data as Map<String, dynamic>;
    return (data['token'] ?? '').toString();
  }

  @override
  Future<List<PlanEnterpriseItem>> fetchEnterprises() async {
    final res = await _dio.get('/api/v1/planner/enterprises');
    final list = ((res.data as Map<String, dynamic>)['enterprises'] as List?) ??
        const [];
    return list
        .whereType<Map<String, dynamic>>()
        .map(PlanEnterpriseItem.fromJson)
        .toList();
  }

  @override
  Future<List<FarmPlanDetail>> fetchPlans(String token) async {
    final res = await _dio.get(
      '/api/v1/planner/plans',
      options: _authOptions(token),
    );
    final list =
        ((res.data as Map<String, dynamic>)['plans'] as List?) ?? const [];
    return list
        .whereType<Map<String, dynamic>>()
        .map(FarmPlanDetail.fromJson)
        .toList();
  }

  @override
  Future<FarmPlanDetail> createPlan(String token, String enterpriseCode) async {
    final res = await _dio.post(
      '/api/v1/planner/plans',
      data: {'enterprise_code': enterpriseCode},
      options: _authOptions(token),
    );
    final body = res.data as Map<String, dynamic>;
    return FarmPlanDetail.fromJson(
      (body['plan'] ?? body) as Map<String, dynamic>,
    );
  }

  @override
  Future<FarmPlanDetail> answerQuestion(
    String token,
    String planId,
    String questionKey,
    dynamic answerValue,
  ) async {
    final res = await _dio.post(
      '/api/v1/planner/plans/$planId/answer',
      data: {'question_key': questionKey, 'answer': answerValue},
      options: _authOptions(token),
    );
    final body = res.data as Map<String, dynamic>;
    return FarmPlanDetail.fromJson(
      (body['plan'] ?? body) as Map<String, dynamic>,
    );
  }

  @override
  Future<PlanProjectionSummary> runWhatIf(
    String token,
    String planId,
    double scaleValue,
  ) async {
    final res = await _dio.post(
      '/api/v1/planner/plans/$planId/what-if',
      data: {'scale_value': scaleValue},
      options: _authOptions(token),
    );
    final body = res.data as Map<String, dynamic>;
    return PlanProjectionSummary.fromJson(
      (body['projection'] ?? body) as Map<String, dynamic>,
    );
  }

  @override
  Future<FarmPlanDetail> acceptPlan(String token, String planId) async {
    final res = await _dio.post(
      '/api/v1/planner/plans/$planId/accept',
      options: _authOptions(token),
    );
    final body = res.data as Map<String, dynamic>;
    return FarmPlanDetail.fromJson(
      (body['plan'] ?? body) as Map<String, dynamic>,
    );
  }

  @override
  Future<MarketplaceRequestItemModel> activatePlanRequirement(
    String token,
    String requirementId,
    double latitude,
    double longitude,
  ) async {
    final res = await _dio.post(
      '/api/v1/marketplace/plan-requirements/$requirementId/activate',
      data: {
        'approx_latitude': latitude,
        'approx_longitude': longitude,
      },
      options: _authOptions(token),
    );
    final body = res.data as Map<String, dynamic>;
    return MarketplaceRequestItemModel.fromJson(
      (body['request'] ?? body) as Map<String, dynamic>,
    );
  }

  @override
  Future<List<MarketplaceRequestItemModel>> fetchMarketplaceRequests(
    String token,
  ) async {
    final res = await _dio.get(
      '/api/v1/marketplace/requests',
      options: _authOptions(token),
    );
    final list =
        ((res.data as Map<String, dynamic>)['requests'] as List?) ?? const [];
    return list
        .whereType<Map<String, dynamic>>()
        .map(MarketplaceRequestItemModel.fromJson)
        .toList();
  }

  @override
  Future<MarketplaceRequestItemModel> createMarketplaceRequest(
    String token, {
    required String itemName,
    required int quantityScaled,
    required String unit,
    required double latitude,
    required double longitude,
    String deliveryPreference = 'either',
  }) async {
    final res = await _dio.post(
      '/api/v1/marketplace/requests',
      data: {
        'approx_latitude': latitude,
        'approx_longitude': longitude,
        'delivery_preference': deliveryPreference,
        'items': [
          {
            'item_name': itemName,
            'quantity_scaled': quantityScaled,
            'unit': unit,
          },
        ],
      },
      options: _authOptions(token),
    );
    final body = res.data as Map<String, dynamic>;
    return MarketplaceRequestItemModel.fromJson(
      (body['request'] ?? body) as Map<String, dynamic>,
    );
  }

  @override
  Future<Map<String, dynamic>> acceptOffer(
    String token,
    String requestId,
    String offerId, {
    required bool grantContactRevealConsent,
  }) async {
    final res = await _dio.post(
      '/api/v1/marketplace/requests/$requestId/offers/$offerId/accept',
      data: {'grant_contact_reveal_consent': grantContactRevealConsent},
      options: _authOptions(token),
    );
    return (res.data as Map<String, dynamic>);
  }
}

final plannerApiClientProvider = Provider<PlannerApiClient>((ref) {
  return DioPlannerApiClient(ref.watch(dioProvider));
});

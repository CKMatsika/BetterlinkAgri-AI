/// Typed P1c models for Farm Planning & Marketplace Mobile Client.
/// Strictly matches the Laravel `/api/v1/planner/*` and `/api/v1/marketplace/*` contracts.
class PlanEnterpriseItem {
  final String code;
  final String name;
  final String category;
  final String defaultScaleUnit;

  const PlanEnterpriseItem({
    required this.code,
    required this.name,
    required this.category,
    required this.defaultScaleUnit,
  });

  factory PlanEnterpriseItem.fromJson(Map<String, dynamic> json) {
    return PlanEnterpriseItem(
      code: (json['code'] ?? '').toString(),
      name: (json['name'] ?? '').toString(),
      category: (json['category'] ?? 'crop').toString(),
      defaultScaleUnit: (json['default_scale_unit'] ?? 'units').toString(),
    );
  }
}

class PlanInterviewQuestion {
  final String key;
  final String prompt;
  final String inputType; // number|choice|text
  final List<String> choices;

  const PlanInterviewQuestion({
    required this.key,
    required this.prompt,
    required this.inputType,
    this.choices = const [],
  });

  factory PlanInterviewQuestion.fromJson(Map<String, dynamic> json) {
    return PlanInterviewQuestion(
      key: (json['key'] ?? '').toString(),
      prompt: (json['prompt'] ?? '').toString(),
      inputType: (json['input_type'] ?? 'text').toString(),
      choices: ((json['choices'] as List?) ?? const [])
          .map((e) => e.toString())
          .toList(),
    );
  }
}

class PlanProjectionSummary {
  final double scaleValue;
  final String scaleUnit;
  final double? totalCostUsd;
  final double? expectedRevenueUsd;
  final double? expectedMarginUsd;
  final bool numbersWithheld;

  const PlanProjectionSummary({
    required this.scaleValue,
    required this.scaleUnit,
    this.totalCostUsd,
    this.expectedRevenueUsd,
    this.expectedMarginUsd,
    this.numbersWithheld = false,
  });

  factory PlanProjectionSummary.fromJson(Map<String, dynamic> json) {
    return PlanProjectionSummary(
      scaleValue: (json['scale_value'] as num?)?.toDouble() ?? 0,
      scaleUnit: (json['scale_unit'] ?? 'units').toString(),
      totalCostUsd: (json['total_cost_usd'] as num?)?.toDouble(),
      expectedRevenueUsd: (json['expected_revenue_usd'] as num?)?.toDouble(),
      expectedMarginUsd: (json['expected_margin_usd'] as num?)?.toDouble(),
      numbersWithheld: json['numbers_withheld'] == true,
    );
  }
}

class PlanRequirementItem {
  final String id;
  final String itemName;
  final int quantityScaled;
  final String unit;
  final String status;

  const PlanRequirementItem({
    required this.id,
    required this.itemName,
    required this.quantityScaled,
    required this.unit,
    required this.status,
  });

  factory PlanRequirementItem.fromJson(Map<String, dynamic> json) {
    return PlanRequirementItem(
      id: (json['id'] ?? '').toString(),
      itemName: (json['item_name'] ?? '').toString(),
      quantityScaled: (json['quantity_scaled'] as num?)?.toInt() ?? 100,
      unit: (json['unit'] ?? 'units').toString(),
      status: (json['status'] ?? 'scheduled').toString(),
    );
  }
}

class FarmPlanDetail {
  final String id;
  final String title;
  final String enterpriseCode;
  final String status;
  final double scaleValue;
  final String scaleUnit;
  final PlanInterviewQuestion? nextQuestion;
  final PlanProjectionSummary? projection;
  final List<PlanRequirementItem> requirements;

  const FarmPlanDetail({
    required this.id,
    required this.title,
    required this.enterpriseCode,
    required this.status,
    required this.scaleValue,
    required this.scaleUnit,
    this.nextQuestion,
    this.projection,
    this.requirements = const [],
  });

  factory FarmPlanDetail.fromJson(Map<String, dynamic> json) {
    return FarmPlanDetail(
      id: (json['id'] ?? '').toString(),
      title: (json['title'] ?? 'Farm Plan').toString(),
      enterpriseCode: (json['enterprise_code'] ?? '').toString(),
      status: (json['status'] ?? 'draft').toString(),
      scaleValue: (json['scale_value'] as num?)?.toDouble() ?? 0,
      scaleUnit: (json['scale_unit'] ?? 'units').toString(),
      nextQuestion: json['next_question'] is Map<String, dynamic>
          ? PlanInterviewQuestion.fromJson(
              json['next_question'] as Map<String, dynamic>,
            )
          : null,
      projection: json['projection'] is Map<String, dynamic>
          ? PlanProjectionSummary.fromJson(
              json['projection'] as Map<String, dynamic>,
            )
          : null,
      requirements: ((json['requirements'] as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(PlanRequirementItem.fromJson)
          .toList(),
    );
  }
}

class MarketplaceOfferItem {
  final String id;
  final String outletName;
  final int totalPriceMinor;
  final String currency;
  final String fulfilmentType;
  final String status;
  final int? rankScoreScaled;
  final String summary;

  const MarketplaceOfferItem({
    required this.id,
    required this.outletName,
    required this.totalPriceMinor,
    required this.currency,
    required this.fulfilmentType,
    required this.status,
    this.rankScoreScaled,
    required this.summary,
  });

  factory MarketplaceOfferItem.fromJson(Map<String, dynamic> json) {
    final explanation =
        (json['rank_explanation_json'] as Map<String, dynamic>?) ?? const {};
    return MarketplaceOfferItem(
      id: (json['id'] ?? '').toString(),
      outletName: (json['outlet_name'] ?? 'Verified Stockist').toString(),
      totalPriceMinor: (json['total_price_minor'] as num?)?.toInt() ?? 0,
      currency: (json['currency'] ?? 'USD').toString(),
      fulfilmentType: (json['fulfilment_type'] ?? 'pickup').toString(),
      status: (json['status'] ?? 'pending').toString(),
      rankScoreScaled: (json['rank_score_scaled'] as num?)?.toInt(),
      summary: (explanation['summary'] ?? 'Ranked by price, distance & stock')
          .toString(),
    );
  }
}

class MarketplaceRequestItemModel {
  final String id;
  final String status;
  final int currentRing;
  final String itemSummary;
  final int sentCount;
  final int openedCount;
  final int offersCount;
  final List<MarketplaceOfferItem> offers;

  const MarketplaceRequestItemModel({
    required this.id,
    required this.status,
    required this.currentRing,
    required this.itemSummary,
    required this.sentCount,
    required this.openedCount,
    required this.offersCount,
    this.offers = const [],
  });

  factory MarketplaceRequestItemModel.fromJson(Map<String, dynamic> json) {
    final counters = (json['counters'] as Map<String, dynamic>?) ?? const {};
    final items = ((json['items'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    final firstItem = items.isNotEmpty
        ? '${items.first['item_name'] ?? 'Input'} (${((items.first['quantity_scaled'] as num?)?.toDouble() ?? 100) / 100} ${items.first['unit'] ?? ''})'
        : (json['item_summary'] ?? 'Marketplace Request').toString();

    return MarketplaceRequestItemModel(
      id: (json['id'] ?? '').toString(),
      status: (json['status'] ?? 'BROADCAST_RING_1').toString(),
      currentRing: (json['current_ring'] as num?)?.toInt() ?? 1,
      itemSummary: firstItem.trim(),
      sentCount: (counters['sent'] as num?)?.toInt() ?? 0,
      openedCount: (counters['opened'] as num?)?.toInt() ?? 0,
      offersCount: (counters['offers'] as num?)?.toInt() ?? 0,
      offers: ((json['offers'] as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(MarketplaceOfferItem.fromJson)
          .toList(),
    );
  }
}

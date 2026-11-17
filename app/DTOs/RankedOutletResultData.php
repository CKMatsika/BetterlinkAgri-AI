<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class RankedOutletResultData
{
    /**
     * @param list<array{product_id: string, sku: string, product_name: string, pack_size: string, quantity_band: string, price_usd: ?float, price_zig: ?float, is_fresh: bool, last_updated_human: string, stale_warning: ?string}> $basketStockItems
     */
    public function __construct(
        public string $outletId,
        public string $outletName,
        public string $outletType,
        public string $address,
        public string $wardName,
        public float $distanceKm,
        public string $distanceBandLabel,
        public int $estimatedTravelMinutes,
        public bool $isOpenNow,
        public bool $suppliesWholeBasket,
        public bool $hasDelivery,
        public float $deliveryRadiusKm,
        public float $deliveryFeeAmount,
        public string $deliveryFeeCurrency,
        public string $phone,
        public string $whatsappLink,
        public string $mapLink,
        public float $compositeRankScore,
        public array $basketStockItems,
        public string $farmerLocationDisclosureForOutlet, // Privacy-safe ward + distance band ONLY (never raw GPS before consent)
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outlet_id' => $this->outletId,
            'outlet_name' => $this->outletName,
            'outlet_type' => $this->outletType,
            'address' => $this->address,
            'ward_name' => $this->wardName,
            'distance_km' => round($this->distanceKm, 1),
            'distance_band_label' => $this->distanceBandLabel,
            'estimated_travel_minutes' => $this->estimatedTravelMinutes,
            'is_open_now' => $this->isOpenNow,
            'supplies_whole_basket' => $this->suppliesWholeBasket,
            'has_delivery' => $this->hasDelivery,
            'delivery_radius_km' => $this->deliveryRadiusKm,
            'delivery_fee_amount' => $this->deliveryFeeAmount,
            'delivery_fee_currency' => $this->deliveryFeeCurrency,
            'phone' => $this->phone,
            'whatsapp_link' => $this->whatsappLink,
            'map_link' => $this->mapLink,
            'composite_rank_score' => round($this->compositeRankScore, 4),
            'basket_stock_items' => $this->basketStockItems,
            'farmer_location_disclosure_for_outlet' => $this->farmerLocationDisclosureForOutlet,
        ];
    }
}

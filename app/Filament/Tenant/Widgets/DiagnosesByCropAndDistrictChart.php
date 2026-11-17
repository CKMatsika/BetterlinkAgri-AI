<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Models\Diagnosis;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

final class DiagnosesByCropAndDistrictChart extends ChartWidget
{
    protected static ?string $heading = 'Diagnoses by Category (Pests, Diseases, Nutrients & Livestock)';

    protected function getData(): array
    {
        /** @var Tenant|null $tenant */
        $tenant = Filament::getTenant() ?? Tenant::query()->first();

        $rows = Diagnosis::withoutGlobalScopes()
            ->select(['category', DB::raw('COUNT(*) as total')])
            ->when($tenant !== null, fn ($q) => $q->where('tenant_id', '=', $tenant->id))
            ->groupBy('category')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Diagnoses Count',
                    'data' => $rows->pluck('total')->map(fn ($v) => (int) $v)->all(),
                ],
            ],
            'labels' => $rows->pluck('category')->map(fn ($c) => is_object($c) ? $c->value : (string) $c)->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}

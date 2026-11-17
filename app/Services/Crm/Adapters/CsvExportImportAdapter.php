<?php

declare(strict_types=1);

namespace App\Services\Crm\Adapters;

use App\Contracts\CrmAdapterContract;
use App\Models\Lead;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class CsvExportImportAdapter implements CrmAdapterContract
{
    public function adapterKey(): string
    {
        return 'csv';
    }

    public function pushLead(Lead $lead): array
    {
        $disk = (string) config('betterlink.media.disk', 'local');
        $csvPath = sprintf('tenants/%s/crm_exports/leads_export_%s.csv', $lead->tenant_id, now()->format('Y_m'));

        $header = "lead_id,farmer_phone,farmer_name,crop,area_ha,need_summary,temperature,intent_score,purchase_window,estimated_value,currency,status,created_at\n";
        $row = sprintf(
            "\"%s\",\"%s\",\"%s\",\"%s\",%.2f,\"%s\",\"%s\",%d,\"%s\",%.2f,\"%s\",\"%s\",\"%s\"\n",
            $lead->id,
            $lead->farmer?->phone_encrypted ?? '',
            $lead->farmer?->full_name_encrypted ?? '',
            $lead->crop?->name_en ?? '',
            (float) ($lead->area_ha ?? 0.0),
            str_replace('"', '""', (string) $lead->need_summary),
            $lead->temperature->value,
            $lead->intent_score,
            $lead->purchase_window,
            (float) $lead->estimated_value,
            $lead->currency->value,
            $lead->status,
            $lead->created_at?->toIso8601String() ?? now()->toIso8601String()
        );

        if (!Storage::disk($disk)->exists($csvPath)) {
            Storage::disk($disk)->put($csvPath, $header . $row);
        } else {
            Storage::disk($disk)->append($csvPath, rtrim($row, "\n"));
        }

        $externalId = 'CSV-' . strtoupper(substr($lead->id, 0, 8));
        $lead->update([
            'crm_status' => 'synced',
            'external_crm_id' => $externalId,
        ]);

        return [
            'synced' => true,
            'external_crm_id' => $externalId,
            'metadata' => ['csv_path' => $csvPath],
        ];
    }

    /**
     * Export a collection of leads as a CSV string.
     *
     * @param Collection<int, Lead> $leads
     */
    public function exportLeadsCollectionToCsv(Collection $leads): string
    {
        $lines = ['lead_id,farmer_phone,farmer_name,crop,area_ha,need_summary,temperature,intent_score,estimated_value,currency,status'];
        foreach ($leads as $lead) {
            $lines[] = sprintf(
                '"%s","%s","%s","%s",%.2f,"%s","%s",%d,%.2f,"%s","%s"',
                $lead->id,
                $lead->farmer?->phone_encrypted ?? '',
                $lead->farmer?->full_name_encrypted ?? '',
                $lead->crop?->name_en ?? '',
                (float) ($lead->area_ha ?? 0.0),
                str_replace('"', '""', (string) $lead->need_summary),
                $lead->temperature->value,
                $lead->intent_score,
                (float) $lead->estimated_value,
                $lead->currency->value,
                $lead->status
            );
        }

        return implode("\n", $lines) . "\n";
    }
}

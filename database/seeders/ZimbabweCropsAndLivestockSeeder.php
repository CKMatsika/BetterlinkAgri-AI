<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Crop;
use App\Models\Livestock;
use Illuminate\Database\Seeder;

final class ZimbabweCropsAndLivestockSeeder extends Seeder
{
    public function run(): void
    {
        $crops = [
            ['code' => 'maize', 'name_en' => 'Maize', 'name_sn' => 'Chibage', 'name_nd' => 'Umumbu', 'season_type' => 'summer', 'row_cm' => 75, 'inrow_cm' => 60, 'pfumvudza' => true],
            ['code' => 'tobacco', 'name_en' => 'Flue-Cured Tobacco', 'name_sn' => 'Fodya', 'name_nd' => 'Igwayi', 'season_type' => 'summer', 'row_cm' => 120, 'inrow_cm' => 55, 'pfumvudza' => false],
            ['code' => 'soya_beans', 'name_en' => 'Soya Beans', 'name_sn' => 'Soya bhinzi', 'name_nd' => 'Indumba zesoya', 'season_type' => 'summer', 'row_cm' => 45, 'inrow_cm' => 5, 'pfumvudza' => true],
            ['code' => 'cotton', 'name_en' => 'Cotton', 'name_sn' => 'Donje', 'name_nd' => 'Utshinda', 'season_type' => 'summer', 'row_cm' => 90, 'inrow_cm' => 30, 'pfumvudza' => true],
            ['code' => 'sorghum', 'name_en' => 'Sorghum', 'name_sn' => 'Mapfunde', 'name_nd' => 'Amabele', 'season_type' => 'summer', 'row_cm' => 75, 'inrow_cm' => 20, 'pfumvudza' => true],
            ['code' => 'millet', 'name_en' => 'Pearl & Finger Millet', 'name_sn' => 'Mhunga / Zviyo', 'name_nd' => 'Inyawuthi / Uphoko', 'season_type' => 'summer', 'row_cm' => 75, 'inrow_cm' => 15, 'pfumvudza' => true],
            ['code' => 'groundnuts', 'name_en' => 'Groundnuts', 'name_sn' => 'Nzungu', 'name_nd' => 'Amazambane', 'season_type' => 'summer', 'row_cm' => 45, 'inrow_cm' => 15, 'pfumvudza' => true],
            ['code' => 'sugar_beans', 'name_en' => 'Sugar Beans', 'name_sn' => 'Bhinzi', 'name_nd' => 'Indumba', 'season_type' => 'all_year', 'row_cm' => 45, 'inrow_cm' => 10, 'pfumvudza' => true],
            ['code' => 'wheat', 'name_en' => 'Winter Wheat', 'name_sn' => 'Gorosi', 'name_nd' => 'Ingqoloyi', 'season_type' => 'winter', 'row_cm' => 20, 'inrow_cm' => 5, 'pfumvudza' => false],
            ['code' => 'tomatoes', 'name_en' => 'Tomatoes', 'name_sn' => 'Madomasi', 'name_nd' => 'Amatamatisi', 'season_type' => 'all_year', 'row_cm' => 100, 'inrow_cm' => 40, 'pfumvudza' => false],
            ['code' => 'vegetables', 'name_en' => 'Leafy Vegetables (Cabbage / Rape / Covo)', 'name_sn' => 'Muriwo (Kabichi / Rugare)', 'name_nd' => 'Imbhida (Ikhabishi / Irape)', 'season_type' => 'all_year', 'row_cm' => 50, 'inrow_cm' => 40, 'pfumvudza' => false],
            ['code' => 'citrus', 'name_en' => 'Citrus (Oranges / Lemons)', 'name_sn' => 'Maranjisi / Mandimu', 'name_nd' => 'Amalentjisi', 'season_type' => 'perennial', 'row_cm' => 600, 'inrow_cm' => 400, 'pfumvudza' => false],
            ['code' => 'bananas', 'name_en' => 'Bananas', 'name_sn' => 'Mabhanana', 'name_nd' => 'Amabhanana', 'season_type' => 'perennial', 'row_cm' => 300, 'inrow_cm' => 200, 'pfumvudza' => false],
            ['code' => 'macadamia', 'name_en' => 'Macadamia Nuts', 'name_sn' => 'Makadhamiya', 'name_nd' => 'Amakhadamiya', 'season_type' => 'perennial', 'row_cm' => 800, 'inrow_cm' => 400, 'pfumvudza' => false],
        ];

        foreach ($crops as $c) {
            Crop::query()->updateOrCreate(
                ['code' => $c['code']],
                [
                    'name_en' => $c['name_en'],
                    'name_sn' => $c['name_sn'],
                    'name_nd' => $c['name_nd'],
                    'season_type' => $c['season_type'],
                    'default_row_spacing_cm' => $c['row_cm'],
                    'default_inrow_spacing_cm' => $c['inrow_cm'],
                    'pfumvudza_supported' => $c['pfumvudza'],
                ]
            );
        }

        $livestockList = [
            ['code' => 'cattle', 'name_en' => 'Beef & Dairy Cattle', 'name_sn' => 'Mombe', 'name_nd' => 'Inkomo', 'category' => 'cattle'],
            ['code' => 'goats', 'name_en' => 'Goats', 'name_sn' => 'Mbudzi', 'name_nd' => 'Imbuzi', 'category' => 'goats'],
            ['code' => 'poultry', 'name_en' => 'Poultry (Broilers, Layers & Roadrunners)', 'name_sn' => 'Huku', 'name_nd' => 'Inkukhu', 'category' => 'poultry'],
        ];

        foreach ($livestockList as $lv) {
            Livestock::query()->updateOrCreate(
                ['code' => $lv['code']],
                $lv
            );
        }
    }
}

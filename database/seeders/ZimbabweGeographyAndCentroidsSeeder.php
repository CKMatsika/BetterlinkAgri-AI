<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\NaturalRegion;
use App\Models\District;
use App\Models\Province;
use App\Models\Ward;
use App\Models\WardCentroid;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ZimbabweGeographyAndCentroidsSeeder extends Seeder
{
    public function run(): void
    {
        $provincesData = [
            'ZW-HA' => [
                'name' => 'Harare Metropolitan',
                'districts' => [
                    'ZW-HA-HAR' => [
                        'name' => 'Harare Rural & Peri-Urban',
                        'wards' => [
                            [1, 'Harare South Peri-Urban Ward 1', NaturalRegion::IIA, 'Southerton / Beatrice Rd', -17.8685, 31.0214],
                            [2, 'Epworth / Ruwa Peri-Urban Ward 2', NaturalRegion::IIA, 'Ruwa Growth Point', -17.8897, 31.2447],
                        ],
                    ],
                ],
            ],
            'ZW-MC' => [
                'name' => 'Mashonaland Central',
                'districts' => [
                    'ZW-MC-MAZ' => [
                        'name' => 'Mazowe',
                        'wards' => [
                            [14, 'Mazowe Ward 14 (Glendale)', NaturalRegion::IIA, 'Glendale Business Centre', -17.3561, 31.0669],
                            [20, 'Mazowe Ward 20 (Concession)', NaturalRegion::IIA, 'Concession Growth Point', -17.3869, 30.9514],
                        ],
                    ],
                    'ZW-MC-BIN' => [
                        'name' => 'Bindura',
                        'wards' => [
                            [8, 'Bindura Communal Ward 8', NaturalRegion::IIA, 'Manhenga Business Centre', -17.3019, 31.3306],
                        ],
                    ],
                ],
            ],
            'ZW-ME' => [
                'name' => 'Mashonaland East',
                'districts' => [
                    'ZW-ME-MAR' => [
                        'name' => 'Marondera',
                        'wards' => [
                            [6, 'Marondera Ward 6 (Wenimbi)', NaturalRegion::IIA, 'Marondera Town Centre', -18.1853, 31.5519],
                        ],
                    ],
                    'ZW-ME-GOR' => [
                        'name' => 'Goromonzi',
                        'wards' => [
                            [11, 'Goromonzi Ward 11 (Juru)', NaturalRegion::IIA, 'Juru Growth Point', -17.8012, 31.3845],
                        ],
                    ],
                ],
            ],
            'ZW-MW' => [
                'name' => 'Mashonaland West',
                'districts' => [
                    'ZW-MW-MAK' => [
                        'name' => 'Makonde',
                        'wards' => [
                            [9, 'Makonde Ward 9 (Chinhoyi)', NaturalRegion::IIA, 'Chinhoyi Town', -17.3667, 30.2000],
                        ],
                    ],
                    'ZW-MW-CHE' => [
                        'name' => 'Chegutu',
                        'wards' => [
                            [12, 'Chegutu Ward 12 (Mubaira)', NaturalRegion::IIB, 'Mubaira Growth Point', -18.1302, 30.1408],
                        ],
                    ],
                ],
            ],
            'ZW-MA' => [
                'name' => 'Manicaland',
                'districts' => [
                    'ZW-MA-CHI' => [
                        'name' => 'Chipinge',
                        'wards' => [
                            [7, 'Chipinge Highlands Ward 7', NaturalRegion::I, 'Chipinge Town', -20.1883, 32.6236],
                        ],
                    ],
                    'ZW-MA-MUT' => [
                        'name' => 'Mutasa',
                        'wards' => [
                            [10, 'Honde Valley Ward 10', NaturalRegion::I, 'Hauna Growth Point', -18.5122, 32.8114],
                        ],
                    ],
                ],
            ],
            'ZW-MI' => [
                'name' => 'Midlands',
                'districts' => [
                    'ZW-MI-GOK' => [
                        'name' => 'Gokwe South',
                        'wards' => [
                            [15, 'Gokwe South Ward 15', NaturalRegion::III, 'Gokwe Centre', -18.2048, 28.9349],
                        ],
                    ],
                    'ZW-MI-KWE' => [
                        'name' => 'Kwekwe',
                        'wards' => [
                            [5, 'Kwekwe Ward 5 (Sherwood)', NaturalRegion::III, 'Kwekwe Central', -18.9281, 29.8149],
                        ],
                    ],
                ],
            ],
            'ZW-MV' => [
                'name' => 'Masvingo',
                'districts' => [
                    'ZW-MV-GUT' => [
                        'name' => 'Gutu',
                        'wards' => [
                            [18, 'Gutu Ward 18 (Mupandawana)', NaturalRegion::IV, 'Mupandawana Growth Point', -19.6500, 31.1667],
                        ],
                    ],
                    'ZW-MV-CRD' => [
                        'name' => 'Chiredzi',
                        'wards' => [
                            [4, 'Chiredzi Lowveld Ward 4', NaturalRegion::V, 'Chiredzi Town', -21.0500, 31.6667],
                        ],
                    ],
                ],
            ],
            'ZW-MN' => [
                'name' => 'Matabeleland North',
                'districts' => [
                    'ZW-MN-LUP' => [
                        'name' => 'Lupane',
                        'wards' => [
                            [11, 'Lupane Ward 11', NaturalRegion::IV, 'Lupane Centre', -18.9314, 27.8069],
                        ],
                    ],
                ],
            ],
            'ZW-MS' => [
                'name' => 'Matabeleland South',
                'districts' => [
                    'ZW-MS-UMZ' => [
                        'name' => 'Umzingwane',
                        'wards' => [
                            [3, 'Esigodini Ward 3', NaturalRegion::IV, 'Esigodini Business Centre', -20.2892, 28.9286],
                        ],
                    ],
                    'ZW-MS-BEI' => [
                        'name' => 'Beitbridge',
                        'wards' => [
                            [6, 'Beitbridge Rural Ward 6', NaturalRegion::V, 'Lutumba Growth Point', -22.0667, 30.0000],
                        ],
                    ],
                ],
            ],
            'ZW-BU' => [
                'name' => 'Bulawayo Metropolitan',
                'districts' => [
                    'ZW-BU-BUL' => [
                        'name' => 'Bulawayo Peri-Urban',
                        'wards' => [
                            [1, 'Umguza / Bulawayo Peri-Urban Ward 1', NaturalRegion::IV, 'Belmont / Khami Rd', -20.1569, 28.5678],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($provincesData as $provCode => $provInfo) {
            $province = Province::query()->updateOrCreate(
                ['code' => $provCode],
                ['name' => $provInfo['name']]
            );

            foreach ($provInfo['districts'] as $distCode => $distInfo) {
                $district = District::query()->updateOrCreate(
                    ['code' => $distCode],
                    [
                        'province_id' => $province->id,
                        'name' => $distInfo['name'],
                    ]
                );

                foreach ($distInfo['wards'] as [$wardNumber, $wardName, $naturalRegion, $growthPoint, $lat, $lng]) {
                    $ward = Ward::query()->updateOrCreate(
                        [
                            'district_id' => $district->id,
                            'ward_number' => $wardNumber,
                        ],
                        [
                            'name' => $wardName,
                            'natural_region' => $naturalRegion,
                        ]
                    );

                    $centroid = WardCentroid::query()->updateOrCreate(
                        ['ward_id' => $ward->id],
                        [
                            'growth_point_name' => $growthPoint,
                            'latitude' => $lat,
                            'longitude' => $lng,
                        ]
                    );

                    if (DB::getDriverName() === 'pgsql') {
                        DB::statement(
                            'UPDATE ward_centroids SET centroid = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                            [$lng, $lat, $centroid->id]
                        );
                    }
                }
            }
        }
    }
}

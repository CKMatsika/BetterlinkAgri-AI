<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\KnowledgeChunk;
use App\Models\ProductDocument;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

final class ZimbabweKnowledgeBaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->first();
        if ($tenant === null) {
            return;
        }

        $doc = ProductDocument::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'title' => 'Zimbabwe Agronomy, Pest, Disease & Nutrient Field Manual (2026)',
            ],
            [
                'document_type' => 'agronomy_guide',
                'language' => 'en',
                'file_path' => 'docs/zw-agronomy-pest-nutrient-manual-2026.pdf',
                'file_hash' => hash('sha256', 'zw-agronomy-pest-nutrient-manual-2026'),
                'ingestion_status' => 'completed',
                'chunks_count' => 16,
            ]
        );

        $chunks = [
            [
                'document_type' => 'pest_guide',
                'crop_code' => 'maize',
                'title' => 'Fall Armyworm (Spodoptera frugiperda) — Gonye remunhava / Amacimbi',
                'page_number' => 4,
                'content' => 'Fall Armyworm (FAW) identification in Zimbabwe: Look for ragged window-pane leaf feeding on V2-V8 maize and moist sawdust-like frass deep inside the funnel (whorl). The caterpillar has a dark head with a pale inverted Y-shape on the front and 4 black dots arranged in a square on the 8th abdominal segment (tail end). Action threshold: Spray when 20% of whorls show fresh damage or live early-instar caterpillars. Direct spray nozzle into the funnel early morning or late afternoon. Rotate IRAC Group 28 (Chlorantraniliprole) with IRAC Group 6 (Emamectin benzoate). Cultural control: early planting with first effective rains, intercropping with legumes, and crushing egg masses.',
                'metadata_json' => ['target_code' => 'fall_armyworm', 'local_sn' => 'Gonye remunhava', 'local_nd' => 'Impethu yomumbu'],
            ],
            [
                'document_type' => 'pest_guide',
                'crop_code' => 'maize',
                'title' => 'African Maize Stalk Borer (Busseola fusca) — Chiburi / Isibungu somumbu',
                'page_number' => 6,
                'content' => 'Maize Stalk Borer vs Fall Armyworm: Stalk borer larvae are pinkish-buff with a uniform smooth skin and a row of small black spiracle spots along each side (lacking the 4 square tail dots and inverted-Y head of FAW). Early damage appears as neat horizontal rows of pin-holes ("shot-holes") across newly unrolled funnel leaves, followed by dead-heart in young plants and stem tunnelling in older plants. Apply granules or funnel spray at 3-4 weeks after emergence BEFORE caterpillars bore into the stalk.',
                'metadata_json' => ['target_code' => 'maize_stalk_borer', 'local_sn' => 'Chiburi', 'local_nd' => 'Isibungu somumbu'],
            ],
            [
                'document_type' => 'pest_guide',
                'crop_code' => 'tomatoes',
                'title' => 'Tomato Leaf Miner (Tuta absoluta) — Tuta yemadomasi',
                'page_number' => 9,
                'content' => 'Tuta absoluta causes irregular silvery blotch mines on tomato leaves (containing dark frass inside the mine), pinholes around the calyx of green and ripe fruit, and stem dieback. Distinguish from serpentine leafminer (Liriomyza) which makes narrow winding trails rather than wide blotches. Integrated management: use pheromone water traps (20-30 traps/ha), remove and destroy infested leaves/fruit, and rotate IRAC Group 28 (Chlorantraniliprole), Group 6 (Emamectin benzoate), and Group 5 (Spinetoram). Never use pyrethroids alone due to widespread resistance.',
                'metadata_json' => ['target_code' => 'tuta_absoluta', 'local_sn' => 'Tuta yemadomasi', 'local_nd' => 'Impethu yamatamatisi'],
            ],
            [
                'document_type' => 'pest_guide',
                'crop_code' => 'tomatoes',
                'title' => 'Red Spider Mite (Tetranychus evansi) & Whitefly on Tomatoes and Cotton',
                'page_number' => 11,
                'content' => 'Red Spider Mite (Karikuni / Utulwa olubomvu) thrives in hot, dry spells (September-November and mid-season dry spells). Symptoms: fine yellow stippling/bronzing on leaves, tiny red/orange mites on the underside of leaves, and fine silk webbing in severe infestations. Whiteflies (Bemisia tabaci) rise in white clouds when foliage is shaken, excrete sticky honeydew (leading to black sooty mould), and transmit Tomato Curly Stunt / Leaf Curl Virus.',
                'metadata_json' => ['target_code' => 'red_spider_mite_whitefly'],
            ],
            [
                'document_type' => 'pest_guide',
                'crop_code' => 'vegetables',
                'title' => 'Aphids, Cutworms (cheka-cheka) & African Bollworm (Helicoverpa armigera)',
                'page_number' => 13,
                'content' => 'Aphids (Inda dzemuriwo) cluster on tender shoots and under leaves of brassicas (rape, covo, cabbage), cotton, and groundnuts, causing leaf curling, stunting, and honeydew. Cutworms (Agrotis spp. / Honye dzinocheka) feed at night at soil level, severing newly emerged maize, tobacco, and vegetable seedlings at the base; scout soil around cut stems at dawn. African Bollworm bores circular holes into cotton bolls, tomato fruit, soya pods, and maize ears.',
                'metadata_json' => ['target_code' => 'aphids_cutworms_bollworm'],
            ],
            [
                'document_type' => 'disease_guide',
                'crop_code' => 'maize',
                'title' => 'Maize Streak Virus (MSV), Grey Leaf Spot (GLS) & Maize Rust',
                'page_number' => 16,
                'content' => 'Maize Streak Virus (MSV): Transmitted by Cicadulina leafhoppers; causes broken, narrow pale-yellow to white streaks running parallel along the leaf veins on young leaves (unlike Nitrogen or Zinc deficiency which do not form broken dashes). Fungicides cannot cure MSV — control leafhopper vectors early and plant MSV-tolerant hybrids. Grey Leaf Spot (Cercospora zeae-maydis): Rectangular, sharp-edged tan-to-grey lesions strictly bounded by leaf veins starting on lower leaves during warm humid weather. Common/Southern Rust: Small raised cinnamon-brown to orange pustules (uredinia) that rub off as rusty powder on fingers.',
                'metadata_json' => ['target_code' => 'grey_leaf_spot', 'look_alike' => 'maize_streak_virus'],
            ],
            [
                'document_type' => 'disease_guide',
                'crop_code' => 'tomatoes',
                'title' => 'Tomato Early Blight, Late Blight & Bacterial/Fusarium Wilt',
                'page_number' => 19,
                'content' => 'Early Blight (Alternaria solani): Dark brown lesions with distinct concentric bullseye target rings on older lower leaves first, with yellow halos. Late Blight (Phytophthora infestans): Rapidly expanding water-soaked greenish-black blotches on leaves and stems with white downy fungal growth on leaf undersides in cool wet weather. Bacterial Wilt (Ralstonia solanacearum): Sudden green wilting of the whole tomato plant during the heat of the day without leaf yellowing; cut lower stem and suspend in clear water to see milky bacterial ooze streaming out.',
                'metadata_json' => ['target_code' => 'early_and_late_blight'],
            ],
            [
                'document_type' => 'disease_guide',
                'crop_code' => 'soya_beans',
                'title' => 'Soya Bean Frogeye Leaf Spot (Cercospora sojina) & Soya Rust (Phakopsora pachyrhizi)',
                'page_number' => 22,
                'content' => 'Frogeye Leaf Spot on soya beans: Circular to angular lesions with light grey-to-tan centres and distinct narrow reddish-brown to purple borders on upper leaves. Soya Bean Rust: Tan to reddish-brown pinpoint lesions on lower leaves first, producing volcano-like pustules on the underside; causes rapid yellowing and premature defoliation during R1-R5 pod filling if not sprayed with a Triazole + Strobilurin fungicide.',
                'metadata_json' => ['target_code' => 'frogeye_leaf_spot'],
            ],
            [
                'document_type' => 'nutrient_guide',
                'crop_code' => 'maize',
                'title' => 'Nitrogen (N) vs Sulphur (S) vs Potassium (K) Deficiencies in Maize',
                'page_number' => 25,
                'content' => 'Nitrogen (N) Deficiency: N is mobile in the plant. Symptoms start on OLDER lower leaves as a pale-yellow V-shaped yellowing starting at the leaf tip and moving down the midrib (central vein), while leaf margins stay green longer. Often worsened by waterlogging or sandy soil leaching in Natural Regions II-IV. Potassium (K) Deficiency: Also on OLDER lower leaves, but yellowing and scorching/necrosis run along the OUTER LEAF MARGINS (edges) while the midrib stays green. Sulphur (S) Deficiency: S is immobile; uniform pale yellowing appears on YOUNGER upper whorl leaves first.',
                'metadata_json' => ['nutrient' => 'N_K_S'],
            ],
            [
                'document_type' => 'nutrient_guide',
                'crop_code' => 'maize',
                'title' => 'Phosphorus (P), Zinc (Zn), Boron (B), Magnesium (Mg) & Soil Acidity (Ca/Lime)',
                'page_number' => 28,
                'content' => 'Phosphorus (P) Deficiency: Dark green stunted young plants with reddish-purple discolouration on older lower leaf tips and margins (especially in cold wet soils or acidic soils with pH < 5.0 CaCl2). Zinc (Zn) Deficiency: Broad whitish-yellow bands on either side of the midrib in the lower half of young emerging leaves (whorl), while the midrib and outer margins remain green; shortened internodes. Magnesium (Mg) Deficiency: Distinct interveinal striping (yellow/white beads-on-a-string streaks between green veins) on older lower leaves. Boron (B) Deficiency: Brittle distorted young leaves, poor pollination, and barren/gap-toothed maize cobs or hollow stems in brassicas.',
                'metadata_json' => ['nutrient' => 'P_Zn_B_Mg_Ca'],
            ],
            [
                'document_type' => 'fertiliser_table',
                'crop_code' => 'maize',
                'title' => 'Pfumvudza / Intwasa Conservation Farming Standard Plot & Application Rates',
                'page_number' => 31,
                'content' => 'A standard Zimbabwe Pfumvudza/Intwasa plot measures 39 m x 16 m (624 m2 = 0.0624 ha), with 52 rows spaced at 75 cm apart and 28 planting stations (holes) per row spaced at 60 cm in-row, giving exactly 1,456 planting stations. Thin to 2 maize plants per station (2,912 plants per plot = ~46,666 plants/ha). Standard inputs per Pfumvudza plot: (1) Agricultural Lime: 5g to 10g (1 level Nr. 5 or Nr. 8 cup) per hole at holing (1 x 10kg-15kg bag/plot); (2) Basal Compound D (7:14:7): 10g (1 level Nr. 8 cup) per planting station = 14.56 kg per plot; (3) Top-dressing Ammonium Nitrate (34.5% N): 10g per station (5g per plant = 1 level Nr. 5 cup per plant) at 4-6 weeks after emergence = 14.56 kg per plot (split into two 5g applications on sandy soils).',
                'metadata_json' => ['programme' => 'pfumvudza'],
            ],
            [
                'document_type' => 'livestock_guide',
                'crop_code' => null,
                'title' => 'Zimbabwe Cattle Tick-Borne Diseases (January Disease / Theileriosis, Redwater, Heartwater) & Poultry/Goats',
                'page_number' => 40,
                'content' => 'January Disease (Theileriosis caused by Theileria parva, transmitted by the brown ear tick Rhipicephalus appendiculatus): Peak incidence December-March in Zimbabwe. Symptoms: high fever (>40.5C), swollen parotid/prescapular lymph nodes below the ear, cloudy/white eyes (corneal opacity), tear staining, laboured breathing, and frothy nasal discharge before death. NOTIFIABLE DISEASE: Immediately isolate affected cattle, alert the Department of Veterinary Services, treat early cases with registered Buparvaquone (1 ml / 20 kg IM) + Oxytetracycline, and enforce strict 5-5-4 dipping intervals + tick grease inside ears and under tail. Poultry Newcastle Disease: twisted necks (torticollis), greenish diarrhoea, respiratory gasping, high mortality — escalate immediately to Vet Officer.',
                'metadata_json' => ['target_code' => 'theileriosis_january_disease', 'high_stakes' => true],
            ],
        ];

        foreach ($chunks as $idx => $chunk) {
            KnowledgeChunk::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'product_document_id' => $doc->id,
                    'chunk_index' => $idx + 1,
                ],
                [
                    'document_type' => $chunk['document_type'],
                    'crop_code' => $chunk['crop_code'],
                    'language' => 'en',
                    'title' => $chunk['title'],
                    'page_number' => $chunk['page_number'],
                    'content' => $chunk['content'],
                    'metadata_json' => $chunk['metadata_json'],
                    'is_active' => true,
                ]
            );
        }
    }
}

<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Multi-Panel Paths (Filament v3)
    |--------------------------------------------------------------------------
    */
    'panels' => [
        'platform_path' => env('FILAMENT_PLATFORM_PANEL_PATH', 'platform'),
        'tenant_path' => env('FILAMENT_TENANT_PANEL_PATH', 'admin'),
        'outlet_path' => env('FILAMENT_OUTLET_PANEL_PATH', 'outlet'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zimbabwe Localisation, Languages & Opt-Out/Opt-In Keywords
    |--------------------------------------------------------------------------
    */
    'localization' => [
        'country_code' => env('ZW_DEFAULT_COUNTRY_CODE', '263'),
        'enable_sn_nd' => (bool) env('APP_ENABLE_SN_ND_TRANSLATIONS', false),
        'translation_review_status' => [
            'en' => 'reviewed',
            'sn' => 'needs_native_review',
            'nd' => 'needs_native_review',
        ],
        'supported_locales' => env('APP_ENABLE_SN_ND_TRANSLATIONS', false) ? ['en', 'sn', 'nd'] : ['en'],
        'currencies' => ['USD', 'ZIG'],
        'default_currency' => env('ZW_DEFAULT_CURRENCY', 'USD'),
        'default_usd_to_zig_rate' => (float) env('ZW_DEFAULT_USD_TO_ZIG_RATE', 25.50),
        'opt_out_keywords' => array_map(
            'trim',
            explode(',', (string) env('ZW_OPT_OUT_KEYWORDS', 'STOP,MIRA,MANA'))
        ),
        'opt_in_keywords' => array_map(
            'trim',
            explode(',', (string) env('ZW_OPT_IN_KEYWORDS', 'START,TANGA,QALA'))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agronomy & Pfumvudza / Intwasa Standard Constants
    |--------------------------------------------------------------------------
    | Note: plants_per_station is configured per crop on crops.pfumvudza_plants_per_station.
    | Values without a verified local document page citation are marked 'unverified'.
    */
    'agronomy' => [
        'pfumvudza_plot_area_m2' => [
            'value' => (int) env('PFUMVUDZA_PLOT_AREA_M2', 624), // 39m x 16m
            'source_document' => 'Foundations for Farming / Zimbabwe Agritex Pfumvudza Field Guide',
            'source_page' => null,
            'status' => 'unverified',
        ],
        'pfumvudza_standard_stations' => [
            'value' => (int) env('PFUMVUDZA_STANDARD_STATIONS', 1456), // 52 rows x 28 stations
            'source_document' => 'Foundations for Farming / Zimbabwe Agritex Pfumvudza Field Guide',
            'source_page' => null,
            'status' => 'unverified',
        ],
        'standard_knapsack_litres' => (int) env('STANDARD_KNAPSACK_LITRES', 16),
        'standard_fertiliser_bag_kg' => (int) env('STANDARD_FERTILISER_BAG_KG', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Intake & Quality Gate Thresholds
    |--------------------------------------------------------------------------
    */
    'media' => [
        'disk' => env('MEDIA_DISK', 'local'),
        'max_upload_mb' => (int) env('IMAGE_MAX_UPLOAD_MB', 10),
        'target_max_width' => (int) env('IMAGE_TARGET_MAX_WIDTH', 1280),
        'jpeg_quality' => (int) env('IMAGE_JPEG_QUALITY', 82),
        'min_resolution_px' => (int) env('IMAGE_MIN_RESOLUTION_PX', 400),
        'min_brightness_score' => (float) env('IMAGE_MIN_BRIGHTNESS_SCORE', 35.0),
        'max_brightness_score' => (float) env('IMAGE_MAX_BRIGHTNESS_SCORE', 235.0),
        'min_laplacian_variance' => (float) env('IMAGE_MIN_LAPLACIAN_VARIANCE', 60.0),
        'max_poor_photo_retries' => (int) env('IMAGE_MAX_POOR_PHOTO_RETRIES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Diagnosis Engine & State Machine Default Thresholds
    |--------------------------------------------------------------------------
    */
    'diagnosis' => [
        'high_confidence_threshold' => (float) env('DIAGNOSIS_HIGH_CONFIDENCE_THRESHOLD', 0.80),
        'min_confidence_threshold' => (float) env('DIAGNOSIS_MIN_CONFIDENCE_THRESHOLD', 0.55),
        'max_clarifying_questions' => (int) env('DIAGNOSIS_MAX_CLARIFYING_QUESTIONS', 5),
        'top_candidates_count' => (int) env('DIAGNOSIS_TOP_CANDIDATES_COUNT', 3),
        'rag_top_k' => (int) env('DIAGNOSIS_RAG_TOP_K', 6),
        'rag_min_similarity' => (float) env('DIAGNOSIS_RAG_MIN_SIMILARITY', 0.72),
        'follow_up_days' => [
            'pest' => (int) env('FOLLOW_UP_PEST_DISEASE_DAYS', 7),
            'disease' => (int) env('FOLLOW_UP_PEST_DISEASE_DAYS', 7),
            'nutrient_deficiency' => (int) env('FOLLOW_UP_NUTRIENT_DAYS', 14),
            'nutrient_toxicity' => (int) env('FOLLOW_UP_NUTRIENT_DAYS', 14),
            'livestock_condition' => (int) env('FOLLOW_UP_LIVESTOCK_DAYS', 3),
            'default' => 7,
        ],
        'follow_up_max_reminders' => (int) env('FOLLOW_UP_MAX_REMINDERS', 2),
        'outbreak_case_threshold' => (int) env('OUTBREAK_ALERT_CASE_THRESHOLD', 5),
        'outbreak_window_days' => (int) env('OUTBREAK_ALERT_WINDOW_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Location-Aware Outlet Finder & SLAs
    |--------------------------------------------------------------------------
    */
    'outlets' => [
        'search_radii_km' => array_map(
            'intval',
            explode(',', (string) env('OUTLET_SEARCH_RADII_KM', '25,50,100'))
        ),
        'stock_freshness_limit_hours' => (int) env('OUTLET_STOCK_FRESHNESS_LIMIT_HOURS', 48),
        'reservation_sla_minutes' => (int) env('OUTLET_RESERVATION_SLA_MINUTES', 30),
        'location_await_timeout_hours' => (int) env('LOCATION_AWAIT_TIMEOUT_HOURS', 24),
        'ranking_weights' => [
            'stock_confidence' => (float) env('OUTLET_WEIGHT_STOCK_CONFIDENCE', 0.35),
            'distance' => (float) env('OUTLET_WEIGHT_DISTANCE', 0.30),
            'open_now' => (float) env('OUTLET_WEIGHT_OPEN_NOW', 0.15),
            'delivery' => (float) env('OUTLET_WEIGHT_DELIVERY', 0.10),
            'price' => (float) env('OUTLET_WEIGHT_PRICE', 0.10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead & Agronomist SLAs
    |--------------------------------------------------------------------------
    */
    'slas' => [
        'lead_rep_sla_minutes' => (int) env('LEAD_REP_SLA_MINUTES', 120),
        'lead_hot_sla_minutes' => (int) env('LEAD_HOT_SLA_MINUTES', 30),
        'agronomist_escalation_sla_minutes' => (int) env('AGRONOMIST_ESCALATION_SLA_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API Configuration
    |--------------------------------------------------------------------------
    */
    'whatsapp' => [
        'enabled' => (bool) env('WHATSAPP_ENABLED', true),
        'graph_uri' => rtrim((string) env('WHATSAPP_GRAPH_URI', 'https://graph.facebook.com'), '/'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
        'app_id' => env('WHATSAPP_APP_ID'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'default_phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'idempotency_ttl_seconds' => (int) env('WHATSAPP_IDEMPOTENCY_TTL_SECONDS', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Provider & Embeddings Configuration
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'default_provider' => env('AI_DEFAULT_PROVIDER', 'anthropic'),
        'embedding_provider' => env('AI_EMBEDDING_PROVIDER', 'voyage'),
        'vector_dimension' => (int) env('PGVECTOR_DIMENSION', 1024),
        'gemini' => [
            'enabled' => false,
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => rtrim((string) env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
            'vision_model' => env('GEMINI_VISION_MODEL', 'gemini-2.5-flash'),
            'reasoning_model' => env('GEMINI_REASONING_MODEL', 'gemini-2.5-flash'),
            'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'text-embedding-004'),
            'max_tokens' => (int) env('GEMINI_MAX_TOKENS', 2048),
            'temperature' => (float) env('GEMINI_TEMPERATURE', 0.1),
            'timeout' => (int) env('GEMINI_REQUEST_TIMEOUT', 45),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => rtrim((string) env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'), '/'),
            'api_version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
            'vision_model' => env('ANTHROPIC_VISION_MODEL', 'claude-sonnet-5-5'),
            'reasoning_model' => env('ANTHROPIC_REASONING_MODEL', 'claude-sonnet-5-5'),
            'fast_model' => env('ANTHROPIC_FAST_MODEL', 'claude-haiku-5-5'),
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 2048),
            'temperature' => (float) env('ANTHROPIC_TEMPERATURE', 0.1),
            'timeout' => (int) env('ANTHROPIC_REQUEST_TIMEOUT', 45),
        ],
        'voyage' => [
            'api_key' => env('VOYAGE_API_KEY'),
            'base_url' => rtrim((string) env('VOYAGE_BASE_URL', 'https://api.voyageai.com/v1'), '/'),
            'model' => env('VOYAGE_EMBEDDING_MODEL', 'voyage-3'),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
            'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'whisper_model' => env('VOICE_STT_MODEL', 'whisper-1'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Weather Spraying Window Thresholds
    |--------------------------------------------------------------------------
    */
    'weather' => [
        'provider' => env('WEATHER_PROVIDER', 'openweathermap'),
        'api_key' => env('OPENWEATHERMAP_API_KEY'),
        'base_url' => rtrim((string) env('OPENWEATHERMAP_BASE_URL', 'https://api.openweathermap.org/data/2.5'), '/'),
        'cache_ttl_minutes' => (int) env('WEATHER_CACHE_TTL_MINUTES', 60),
        'spray_max_wind_kmh' => (float) env('SPRAY_MAX_WIND_SPEED_KMH', 15.0),
        'spray_min_wind_kmh' => (float) env('SPRAY_MIN_WIND_SPEED_KMH', 3.0),
        'spray_max_rain_prob_pct' => (int) env('SPRAY_MAX_RAIN_PROBABILITY_PCT', 30),
        'spray_rain_free_window_hours' => (int) env('SPRAY_RAIN_FREE_WINDOW_HOURS', 4),
        'spray_max_temp_celsius' => (float) env('SPRAY_MAX_TEMP_CELSIUS', 30.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Farm Planning Engine Configuration
    |--------------------------------------------------------------------------
    */
    'planning' => [
        'allow_unverified_demo_numbers' => (bool) env(
            'PLANNING_ALLOW_UNVERIFIED_DEMO_NUMBERS',
            env('APP_ENV', 'production') !== 'production'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cyber and Data Protection Act [Chapter 12:07] Settings
    |--------------------------------------------------------------------------
    */
    'cdpa' => [
        'require_explicit_consent' => (bool) env('CDPA_REQUIRE_EXPLICIT_CONSENT', true),
        'retention_days' => (int) env('CDPA_RETENTION_DAYS', 1095),
        'dpo_email' => env('CDPA_DPO_EMAIL', 'dpo@betterlinkagr.co.zw'),
        'current_policy_version' => '2026.1',
    ],
];

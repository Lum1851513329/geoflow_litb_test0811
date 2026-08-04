<?php

return [
    'workbook' => [
        'max_file_size_bytes' => 10_485_760,
        'max_sheets' => 5,
        'max_rows_per_sheet' => 10_000,
        'max_columns_per_sheet' => 50,
        'max_sample_rows' => 10,
        'max_zip_entries' => 2_048,
        'max_uncompressed_bytes' => 104_857_600,
        'max_entry_uncompressed_bytes' => 26_214_400,
        'max_compression_ratio' => 100,
    ],

    'brand_variants' => [
        'lightinthebox',
        'light in the box',
        'light-in-the-box',
    ],

    'intent_phrases' => [
        'transactional' => [
            'coupon',
            'discount',
            'promo code',
            'sale',
            'buy',
        ],
        'trust' => [
            'legit',
            'review',
            'reviews',
            'scam',
            'safe',
        ],
        'support' => [
            'login',
            'contact',
            'customer service',
            'tracking',
        ],
        'comparison' => [
            'vs',
            'versus',
            'alternative',
            'compare',
            'comparison',
        ],
        'informational' => [
            'how',
            'what',
            'why',
            'when',
            'where',
            'is',
        ],
    ],

    'topic_clustering' => [
        'compatible_intents' => [
            'commercial' => ['commercial', 'transactional'],
            'transactional' => ['commercial', 'transactional'],
            'informational' => ['informational'],
            'navigational' => ['navigational'],
            'support' => ['support'],
        ],
    ],

    'opportunity_scoring' => [
        'version' => 'v1',
        'fresh_days' => 7,
        'stale_after_days' => 30,
        'missing_data_penalty' => 12.0,
        'stale_data_penalty' => 8.0,
        'demand_impressions_ceiling' => 10_000,
        'target_ctr' => 0.05,
        'target_conversion_rate' => 0.10,
        'weights' => [
            'non_brand' => [
                'demand' => 0.25,
                'click_performance' => 0.15,
                'ranking_opportunity' => 0.20,
                'commercial_relevance' => 0.15,
                'content_gap' => 0.15,
                'freshness' => 0.10,
            ],
            'branded' => [
                'demand' => 0.15,
                'click_performance' => 0.25,
                'ranking_opportunity' => 0.10,
                'commercial_relevance' => 0.25,
                'content_gap' => 0.10,
                'freshness' => 0.15,
            ],
        ],
    ],
];

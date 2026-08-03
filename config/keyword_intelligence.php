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
];

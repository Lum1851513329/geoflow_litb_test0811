<?php

return [
    'enabled' => filter_var(env('GEOFLOW_EXTERNAL_EVIDENCE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'base_url' => rtrim(trim((string) env('GEOFLOW_WIGOLO_BASE_URL', '')), '/'),
    'api_token' => (string) env('GEOFLOW_WIGOLO_API_TOKEN', ''),
    'timeout_seconds' => max(1, min(180, (int) env('GEOFLOW_WIGOLO_TIMEOUT_SECONDS', 30))),
    'connect_timeout_seconds' => max(1, min(30, (int) env('GEOFLOW_WIGOLO_CONNECT_TIMEOUT_SECONDS', 5))),
    'max_response_bytes' => max(1024, min(8_000_000, (int) env('GEOFLOW_WIGOLO_MAX_RESPONSE_BYTES', 2_000_000))),
    'max_results' => max(1, min(20, (int) env('GEOFLOW_WIGOLO_MAX_RESULTS', 5))),
    'max_fetches' => max(1, min(20, (int) env('GEOFLOW_WIGOLO_MAX_FETCHES', 3))),
    'max_pages' => max(1, min(20, (int) env('GEOFLOW_EXTERNAL_EVIDENCE_MAX_PAGES', 6))),
    'max_search_rounds' => max(1, min(4, (int) env('GEOFLOW_EXTERNAL_EVIDENCE_MAX_SEARCH_ROUNDS', 2))),
    'budget_seconds' => max(15, min(600, (int) env('GEOFLOW_EXTERNAL_EVIDENCE_BUDGET_SECONDS', 180))),
    'max_tokens_out' => max(256, min(20_000, (int) env('GEOFLOW_WIGOLO_MAX_TOKENS_OUT', 5000))),
    'official_domains' => [],
    'general_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEOFLOW_EXTERNAL_EVIDENCE_GENERAL_DOMAINS', ''))))),
];

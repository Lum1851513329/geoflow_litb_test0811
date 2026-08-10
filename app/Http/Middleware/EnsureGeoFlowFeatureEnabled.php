<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureGeoFlowFeatureEnabled
{
    /** @var list<string> */
    private const FEATURES = [
        'content_strategy_enabled',
        'modular_prompts_enabled',
        'generation_quality_gate_enabled',
    ];

    /**
     * Hide disabled incremental-delivery routes until their feature is enabled.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! in_array($feature, self::FEATURES, true)
            || ! (bool) config('geoflow.'.$feature, false)) {
            abort(404);
        }

        return $next($request);
    }
}

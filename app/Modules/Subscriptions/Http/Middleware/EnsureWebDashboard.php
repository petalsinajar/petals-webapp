<?php

namespace App\Modules\Subscriptions\Http\Middleware;

use App\Modules\Identity\Models\Vendor;
use App\Modules\Subscriptions\Services\Entitlements;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The vendor web dashboard is a plan feature (web_dashboard); the app works on every plan. */
class EnsureWebDashboard
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        $vendor = Filament::getTenant();

        if ($vendor instanceof Vendor && ! $this->entitlements->hasFeature($vendor, 'web_dashboard')) {
            abort(403, 'The web dashboard is part of the Growth plan and above. Upgrade in the app under Business → Plan.');
        }

        return $next($request);
    }
}

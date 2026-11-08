<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * For read endpoints anyone may call: signs the request in when it carries a
 * valid access token (so responses can be personalised), and otherwise lets
 * it through anonymously. Never answers 401. Refresh tokens, expired tokens
 * and suspended accounts are treated as anonymous.
 */
class OptionalSanctumUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();
        $viewer = $user instanceof User && $user->tokenCan('access') && $user->isActive() ? $user : null;

        // Decide the viewer here rather than leave it to whichever guard is
        // the default, so a refresh token can't sneak in through it.
        $request->setUserResolver(fn () => $viewer);
        if ($viewer) {
            Auth::setUser($viewer); // for Gate checks
        }

        return $next($request);
    }
}

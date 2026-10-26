<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;

/** Server-side, per-request role-based authorisation. Interface concealment is never relied upon. */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if ($user->status !== 'active') {
            return response()->json(['message' => 'Account suspended.'], 403);
        }
        foreach ($permissions as $p) {
            if ($user->can_($p)) {
                return $next($request);
            }
        }
        AuditService::log('access_denied', 'denied', null, null, $request->method().' '.$request->path().' requires '.implode('|', $permissions));
        return response()->json(['message' => 'You are not authorised to perform this action.'], 403);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $r = $next($request);
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('X-Frame-Options', 'DENY');
        $r->headers->set('Referrer-Policy', 'same-origin');
        $r->headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');
        $isLocal = app()->environment('local');
        $viteOrigin = $isLocal ? ' http://127.0.0.1:5173' : '';
        $inlineScript = $isLocal ? " 'unsafe-inline'" : '';
        $csp = "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'{$viteOrigin}; script-src 'self'{$inlineScript}{$viteOrigin}; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
        if ($isLocal) {
            $csp .= "; connect-src 'self'{$viteOrigin} ws://127.0.0.1:5173";
        }
        $r->headers->set('Content-Security-Policy', $csp);
        if ($request->isSecure()) {
            $r->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($request->is('api/*')) {
            $r->headers->set('Cache-Control', 'no-store, private');
        }
        return $r;
    }
}

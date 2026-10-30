<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        if ($request->is('api/v1/jobs', 'api/v1/jobs/*', 'api/v1/me/*', 'api/v1/conversions', 'api/v1/auth/*', 'api/v1/admin/*', 'api/v1/subscriptions/*', 'api/v1/social-downloads*', 'connexion', 'inscription', 'profil', 'mon-abonnement', 'administration', '*mot-de-passe*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (! app()->environment('local', 'testing')) {
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
            if ($request->isSecure()) {
                $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
            }
        }

        return $response;
    }
}

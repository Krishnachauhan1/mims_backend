<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->is('api/*')) {
            return;
        }

        $authHeader = $request->header('Authorization', '');
        $hasBearer = str_starts_with($authHeader, 'Bearer ');
        $tokenHint = null;

        if ($hasBearer) {
            $token = trim(substr($authHeader, 7));
            $tokenHint = $token !== '' ? substr($token, 0, 8).'…' : 'empty';
        }

        $user = $request->user();

        Log::info('[API]', [
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'status' => $response->getStatusCode(),
            'auth_header' => $hasBearer ? 'bearer' : ($authHeader !== '' ? 'other' : 'missing'),
            'token_hint' => $tokenHint,
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'ip' => $request->ip(),
        ]);

        if ($response->getStatusCode() === 403) {
            $body = (string) $response->getContent();
            $isHtml = str_contains(strtolower($body), '<html');
            Log::warning('[API 403]', [
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'ip' => $request->ip(),
                'source' => $isHtml ? 'hostinger_or_waf_html' : 'laravel_json',
                'body_preview' => substr(preg_replace('/\s+/', ' ', strip_tags($body)), 0, 240),
            ]);
        }
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures API CORS headers for Flutter web (mims subdomain → powerforceindustries.com/api).
 */
class HandlePreflightCors
{
    private const ALLOW_ORIGIN = '*';

    private const ALLOW_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';

    private const ALLOW_HEADERS = 'Content-Type, Authorization, Accept, X-Requested-With, X-XSRF-TOKEN';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return response('', 204)->withHeaders($this->corsHeaders());
        }

        $response = $next($request);

        foreach ($this->corsHeaders() as $name => $value) {
            $response->headers->set($name, $value, false);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => self::ALLOW_ORIGIN,
            'Access-Control-Allow-Methods' => self::ALLOW_METHODS,
            'Access-Control-Allow-Headers' => self::ALLOW_HEADERS,
            'Access-Control-Max-Age' => '86400',
        ];
    }
}

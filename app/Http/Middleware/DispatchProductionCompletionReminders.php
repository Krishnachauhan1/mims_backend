<?php

namespace App\Http\Middleware;

use App\Services\ProductionCompletionReminderService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class DispatchProductionCompletionReminders
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->user()) {
            return;
        }

        $cacheKey = 'production_completion_reminder_last_tick';

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, now()->timestamp, now()->addMinutes(5));

        try {
            app(ProductionCompletionReminderService::class)->sendDueReminders();
        } catch (\Throwable) {
            Cache::forget($cacheKey);
        }
    }
}

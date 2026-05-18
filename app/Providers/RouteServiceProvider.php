<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $apacheThreads = max((int) env('APACHE_THREADS', 150), 1);
        $averageRequestMs = max((int) env('APACHE_AVG_REQUEST_MS', 350), 1);
        $targetUtilization = max(min((float) env('RATE_LIMIT_UTILIZATION', 0.75), 0.95), 0.10);

        $estimatedRequestsPerMinute = (int) round(($apacheThreads / ($averageRequestMs / 1000)) * 60 * $targetUtilization);

        $apiLimit = $this->resolveRateLimit($estimatedRequestsPerMinute, (float) env('RATE_LIMIT_API_SHARE', 0.20), 1_500);
        $authLimit = $this->resolveRateLimit($estimatedRequestsPerMinute, (float) env('RATE_LIMIT_AUTH_SHARE', 0.20), 1_500);
        $cartLimit = $this->resolveRateLimit($estimatedRequestsPerMinute, (float) env('RATE_LIMIT_CART_SHARE', 0.35), 2_500);
        $checkoutLimit = $this->resolveRateLimit($estimatedRequestsPerMinute, (float) env('RATE_LIMIT_CHECKOUT_SHARE', 0.25), 1_200);

        RateLimiter::for('api', function (Request $request) use ($apiLimit) {
            return Limit::perMinute($apiLimit)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) use ($authLimit) {
            return Limit::perMinute($authLimit)->by($request->ip());
        });

        RateLimiter::for('cart-write', function (Request $request) use ($cartLimit) {
            return Limit::perMinute($cartLimit)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('checkout', function (Request $request) use ($checkoutLimit) {
            return Limit::perMinute($checkoutLimit)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    private function resolveRateLimit(int $estimatedRequestsPerMinute, float $share, int $minimum): int
    {
        $share = max(min($share, 1.0), 0.0);

        return max($minimum, (int) round($estimatedRequestsPerMinute * $share));
    }
}

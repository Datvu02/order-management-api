<?php

namespace App\Providers;

use App\Events\OrderCreated;
use App\Listeners\SendOrderCreatedNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Event::listen(OrderCreated::class, SendOrderCreatedNotification::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('app.api_rate_limit', 60))
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('orders', function (Request $request) {
            return Limit::perMinute((int) config('app.order_rate_limit', 10))
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}

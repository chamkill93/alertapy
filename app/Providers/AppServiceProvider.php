<?php

namespace App\Providers;

use App\Contracts\DecisionProviderInterface;
use App\Services\OpenCodeDecisionProvider;
use App\Services\RuleBasedDecisionProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DecisionProviderInterface::class, function ($app): DecisionProviderInterface {
            return config('alertapy.decision.provider', 'rules') === 'opencode' && config('alertapy.opencode.enabled', false)
                ? $app->make(OpenCodeDecisionProvider::class)
                : $app->make(RuleBasedDecisionProvider::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('database.default') === 'sqlite') {
            $pdo = DB::connection()->getPdo();

            if (method_exists($pdo, 'sqliteCreateFunction')) {
                $pdo->sqliteCreateFunction('SQRT', sqrt(...), 1);
                $pdo->sqliteCreateFunction('SIN', sin(...), 1);
                $pdo->sqliteCreateFunction('COS', cos(...), 1);
                $pdo->sqliteCreateFunction('ASIN', asin(...), 1);
                $pdo->sqliteCreateFunction('RADIANS', deg2rad(...), 1);
                $pdo->sqliteCreateFunction('POWER', pow(...), 2);
            }
        }

        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}

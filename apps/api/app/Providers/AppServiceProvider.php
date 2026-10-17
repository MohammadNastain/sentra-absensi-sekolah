<?php

namespace App\Providers;

use App\Domain\Identity\Listeners\RecordAuthenticationEvents;
use App\Domain\Identity\PasswordRules;
use App\Domain\Tenancy\DatabaseContext;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseContext::class);
        // Satu konteks per request (dan per job); dibersihkan oleh ResetDatabaseContext.
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Password::defaults(fn () => PasswordRules::default());
        Event::subscribe(RecordAuthenticationEvents::class);

        // Endpoint undangan publik: 10/menit per IP (TAHAP_1_FONDASI §6).
        RateLimiter::for('invitation-public', fn (Request $request) => Limit::perMinute(10)->by('invitation:'.$request->ip()));
    }
}

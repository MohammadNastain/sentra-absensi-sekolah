<?php

namespace App\Providers;

use App\Domain\Identity\Listeners\RecordAuthenticationEvents;
use App\Domain\Identity\PasswordRules;
use App\Domain\Tenancy\ClassScope;
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
        $this->app->scoped(ClassScope::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Password::defaults(fn () => PasswordRules::default());
        Event::subscribe(RecordAuthenticationEvents::class);

        // Endpoint undangan publik: 10/menit per IP (TAHAP_1_FONDASI §6).
        RateLimiter::for('terminal-pair', fn (Request $request) => Limit::perMinute(10)->by('terminal-pair:'.$request->ip()));
        RateLimiter::for('terminal', fn (Request $request) => Limit::perMinute(240)->by('terminal:'.sha1(is_string($request->cookie('sentra_device')) ? $request->cookie('sentra_device') : (string) $request->ip())));
        // Login petugas di terminal: 5/menit per perangkat (menahan tebakan password lewat terminal yang dicuri).
        RateLimiter::for('terminal-assist', fn (Request $request) => Limit::perMinute(5)->by('terminal-assist:'.sha1(is_string($request->cookie('sentra_device')) ? $request->cookie('sentra_device') : (string) $request->ip())));
        // Masuk demo satu klik: dibatasi per IP agar tidak dipakai membanjiri sesi.
        RateLimiter::for('demo-login', fn (Request $request) => Limit::perMinute(10)->by('demo-login:'.$request->ip()));
        RateLimiter::for('invitation-public', fn (Request $request) => Limit::perMinute(10)->by('invitation:'.$request->ip()));
    }
}

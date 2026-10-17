<?php

namespace App\Providers;

use App\Domain\Identity\Listeners\RecordAuthenticationEvents;
use App\Domain\Identity\PasswordRules;
use App\Domain\Tenancy\DatabaseContext;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
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
    }
}

<?php

namespace App\Domain\Identity\Listeners;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\SessionPolicy;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Audit & penanda sesi untuk event autentikasi (TAHAP_1_FONDASI §6). Baris audit autentikasi tidak
 * terikat sekolah (school_id NULL) sehingga hanya terlihat dalam konteks platform.
 */
final class RecordAuthenticationEvents
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, $this->onLogin(...));
        $events->listen(Failed::class, $this->onFailed(...));
        $events->listen(Logout::class, $this->onLogout(...));
        $events->listen(PasswordReset::class, fn (PasswordReset $e) => $this->recordFor($e->user, 'auth.password.reset'));
        $events->listen(PasswordUpdatedViaController::class, fn (PasswordUpdatedViaController $e) => $this->recordFor($e->user, 'auth.password.updated'));
        $events->listen(TwoFactorAuthenticationEnabled::class, fn (TwoFactorAuthenticationEnabled $e) => $this->recordFor($e->user, 'auth.two_factor.enabled'));
        $events->listen(TwoFactorAuthenticationDisabled::class, fn (TwoFactorAuthenticationDisabled $e) => $this->recordFor($e->user, 'auth.two_factor.disabled'));
        $events->listen(TwoFactorAuthenticationFailed::class, fn (TwoFactorAuthenticationFailed $e) => $this->recordFor($e->user, 'auth.two_factor.failed'));
        $events->listen(TwoFactorAuthenticationConfirmed::class, $this->onTwoFactorPassed(...));
        $events->listen(ValidTwoFactorAuthenticationCodeProvided::class, $this->onTwoFactorPassed(...));
    }

    public function onLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        SessionPolicy::markLogin(request());
        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->audit->record('auth.login.succeeded', null, 'user', $event->user->id, actor: $event->user);
    }

    public function onFailed(Failed $event): void
    {
        $this->audit->record(
            'auth.login.failed',
            null,
            'auth',
            $event->user instanceof User ? $event->user->id : null,
            after: ['email' => $event->credentials['email'] ?? null],
            actor: null,
        );
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record('auth.logout', null, 'user', $event->user->id, actor: $event->user);
        }
    }

    public function onTwoFactorPassed(TwoFactorAuthenticationConfirmed|ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        // Konfirmasi 2FA memakai kode TOTP yang valid, jadi juga dihitung sebagai tantangan yang lulus.
        SessionPolicy::markTwoFactorPassed(request(), $event->user);

        if ($event instanceof TwoFactorAuthenticationConfirmed) {
            $this->recordFor($event->user, 'auth.two_factor.confirmed');
        }
    }

    private function recordFor(mixed $user, string $action): void
    {
        if ($user instanceof User) {
            $this->audit->record($action, null, 'user', $user->id, actor: $user);
        }
    }
}

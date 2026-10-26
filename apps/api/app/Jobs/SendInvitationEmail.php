<?php

namespace App\Jobs;

use App\Domain\Demo\DemoMode;
use App\Domain\Tenancy\TenantAwareJob;
use App\Mail\InvitationMail;
use App\Models\MembershipInvitation;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mengirim email undangan dan mencatat hasil serah-terima ke transport (ADR 0008).
 * `handed_off` berarti transport menerima pesan, BUKAN bahwa pesan sampai di kotak masuk.
 * Payload dienkripsi karena memuat token mentah. Tanpa retry otomatis: admin dapat mengirim ulang.
 */
class SendInvitationEmail extends TenantAwareJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(string $schoolId, public readonly string $invitationId, public readonly string $token)
    {
        parent::__construct($schoolId);
    }

    protected function run(): void
    {
        $invitation = MembershipInvitation::query()->safe()->whereKey($this->invitationId)->first();

        if ($invitation === null || ! $invitation->isPending()) {
            return;
        }

        // Sekolah demo tidak boleh mengirim surel ke alamat sembarang (bisa dipakai untuk spam).
        if (DemoMode::isDemoSchoolId($this->schoolId)) {
            $this->markDelivery('failed', 'Pengiriman surel dinonaktifkan di mode demo.');

            return;
        }

        $school = DB::table('schools')->where('id', $this->schoolId)->first(['name']);
        $roleNames = DB::table('roles')->whereIn('code', $invitation->role_codes)->orderBy('code')->pluck('name')->all();

        try {
            Mail::to($invitation->email)->send(new InvitationMail(
                schoolName: (string) ($school->name ?? ''),
                roleNames: array_values(array_map('strval', $roleNames)),
                url: rtrim((string) config('app.frontend_url'), '/').'/invitations/'.$this->token,
                expiresAt: $invitation->expires_at->timezone('Asia/Jakarta')->translatedFormat('j F Y H:i').' WIB',
            ));
            $this->markDelivery('handed_off', null);
        } catch (Throwable $e) {
            report($e);
            $this->markDelivery('failed', Str::limit($e::class.': '.$e->getMessage(), 300, ''));
        }
    }

    private function markDelivery(string $status, ?string $error): void
    {
        DB::table('membership_invitations')->where('id', $this->invitationId)->update([
            'delivery_status' => $status,
            'delivery_error' => $error,
            'updated_at' => now(),
        ]);
    }
}

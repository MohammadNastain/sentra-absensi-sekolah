<?php

namespace App\Console\Commands;

use App\Domain\Identity\PasswordRules;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Membuat Super Admin pertama di server baru (tidak ada seeder di produksi). Sandi dibaca dari prompt tersembunyi atau dari
 * variabel lingkungan (`--password-env`), tidak pernah dari argumen agar tidak tertinggal di riwayat shell. Autentikasi dua
 * langkah diwajibkan pada login pertama Super Admin (ADR 0007).
 */
final class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'platform:create-admin {email : Surel Super Admin} {--name= : Nama tampilan} {--password-env= : Nama variabel lingkungan berisi sandi (bila tanpa prompt)}';

    protected $description = 'Buat akun Super Admin platform';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: 'Super Admin'));

        if (Validator::make(['email' => $email], ['email' => ['email:rfc', 'max:255']])->fails()) {
            $this->error('Surel tidak valid.');

            return self::FAILURE;
        }
        if (User::query()->where('email', $email)->exists()) {
            $this->error('Akun dengan surel ini sudah ada.');

            return self::FAILURE;
        }

        $password = $this->readPassword();
        if ($password === null) {
            return self::FAILURE;
        }
        $problems = Validator::make(['password' => $password], ['password' => [PasswordRules::default()]]);
        if ($problems->fails()) {
            foreach ($problems->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $name, $password): void {
            $user = new User;
            $user->forceFill(['email' => $email, 'name' => $name, 'password' => $password, 'status' => User::STATUS_ACTIVE, 'email_verified_at' => now()])->save();
            DB::table('platform_admins')->insert(['user_id' => $user->id]);
        });

        $this->info("Super Admin {$email} dibuat. Autentikasi dua langkah wajib disiapkan saat login pertama.");

        return self::SUCCESS;
    }

    private function readPassword(): ?string
    {
        $variable = $this->option('password-env');
        if (is_string($variable) && $variable !== '') {
            $value = getenv($variable);
            if ($value === false || $value === '') {
                $this->error("Variabel lingkungan {$variable} kosong.");

                return null;
            }

            return $value;
        }

        $password = (string) $this->secret('Sandi (minimal 10 karakter)');
        if ($password !== (string) $this->secret('Ulangi sandi')) {
            $this->error('Sandi tidak sama.');

            return null;
        }

        return $password;
    }
}

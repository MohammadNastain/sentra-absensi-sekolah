"use client";

import { LogoutButton } from "@/components/LogoutButton";
import { PendingInvitations } from "@/components/PendingInvitations";

/** User login tetapi belum punya keanggotaan aktif di sekolah mana pun. */
export default function NoAccessPage() {
  return (
    <main className="mx-auto w-full max-w-2xl flex-1 space-y-8 px-4 py-10">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Belum ada akses sekolah</h1>
        <LogoutButton />
      </header>
      <p className="text-zinc-600 dark:text-zinc-400">
        Akun Anda belum terhubung ke sekolah mana pun, atau keanggotaan Anda sedang tidak aktif. Minta admin sekolah
        mengirim undangan, atau terima undangan yang tertunda di bawah ini.
      </p>
      <PendingInvitations />
    </main>
  );
}

"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { AuthShell, FormAlert, SubmitButton, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, type FormFailure } from "@/lib/errors";
import { destinationPath, resolveDestination } from "@/lib/session";

/**
 * Penggantian kata sandi awal (siswa, ADR 0010). Selama belum diganti, API hanya melayani halaman ini, profil, dan keluar.
 * Berlaku juga bagi siapa pun yang ingin mengganti sandinya.
 */
export default function ChangePasswordPage() {
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const text = (name: string) => String(form.get(name) ?? "");
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.PUT("/auth/password", {
        body: { current_password: text("current_password"), password: text("password"), password_confirmation: text("password_confirmation") },
      });
    });

    if (!outcome.ok) {
      setFailure(outcome.failure);
      setPending(false);
      return;
    }
    router.replace(destinationPath(await resolveDestination()));
  }

  return (
    <AuthShell title="Ganti kata sandi" description="Buat kata sandi baru yang hanya Anda ketahui. Minimal 10 karakter.">
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {failure ? <FormAlert>{failure.message}</FormAlert> : null}
        <TextField
          label="Kata sandi saat ini"
          name="current_password"
          type="password"
          autoComplete="current-password"
          error={failure?.fields.current_password}
          required
          autoFocus
        />
        <TextField label="Kata sandi baru" name="password" type="password" autoComplete="new-password" error={failure?.fields.password} required />
        <TextField
          label="Ulangi kata sandi baru"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          error={failure?.fields.password_confirmation}
          required
        />
        <SubmitButton pending={pending}>Simpan kata sandi</SubmitButton>
      </form>
    </AuthShell>
  );
}

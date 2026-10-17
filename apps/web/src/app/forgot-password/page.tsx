"use client";

import { useState } from "react";
import { AuthShell, FormAlert, SubmitButton, TextField, TextLink } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, type FormFailure } from "@/lib/errors";

export default function ForgotPasswordPage() {
  const [pending, setPending] = useState(false);
  const [sent, setSent] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const email = String(new FormData(event.currentTarget).get("email") ?? "");
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/auth/forgot-password", { body: { email } });
    });
    setPending(false);

    if (outcome.ok) setSent(true);
    else setFailure(outcome.failure);
  }

  return (
    <AuthShell
      title="Lupa password"
      description="Masukkan email akun Anda. Bila terdaftar, kami kirim tautan untuk membuat password baru."
      footer={<TextLink href="/login">Kembali ke halaman masuk</TextLink>}
    >
      {sent ? (
        <FormAlert tone="success">
          Bila email terdaftar, tautan reset password telah dikirim. Tautan berlaku 60 menit dan hanya bisa dipakai sekali.
        </FormAlert>
      ) : (
        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          {failure ? <FormAlert>{failure.message}</FormAlert> : null}
          <TextField
            label="Email"
            name="email"
            type="email"
            autoComplete="email"
            error={failure?.fields.email}
            required
            autoFocus
          />
          <SubmitButton pending={pending}>Kirim tautan reset</SubmitButton>
        </form>
      )}
    </AuthShell>
  );
}

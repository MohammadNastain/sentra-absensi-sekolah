"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { AuthShell, FormAlert, SubmitButton, TextField, TextLink } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, type FormFailure } from "@/lib/errors";

function ResetPasswordForm() {
  const router = useRouter();
  const params = useSearchParams();
  const token = params.get("token") ?? "";
  const email = params.get("email") ?? "";
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  if (!token || !email) {
    return (
      <AuthShell title="Tautan tidak valid" footer={<TextLink href="/forgot-password">Minta tautan baru</TextLink>}>
        <FormAlert>Tautan reset password tidak lengkap. Minta tautan baru.</FormAlert>
      </AuthShell>
    );
  }

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/auth/reset-password", {
        body: {
          token,
          email,
          password: String(form.get("password") ?? ""),
          password_confirmation: String(form.get("password_confirmation") ?? ""),
        },
      });
    });

    if (!outcome.ok) {
      setFailure(outcome.failure);
      setPending(false);
      return;
    }
    router.replace("/login?reset=1");
  }

  return (
    <AuthShell
      title="Buat password baru"
      description={`Untuk akun ${email}.`}
      footer={<TextLink href="/forgot-password">Minta tautan baru</TextLink>}
    >
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {failure ? <FormAlert>{failure.message}</FormAlert> : null}
        <TextField
          label="Password baru"
          name="password"
          type="password"
          autoComplete="new-password"
          error={failure?.fields.password}
          hint="Minimal 10 karakter."
          required
          autoFocus
        />
        <TextField
          label="Ulangi password baru"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          required
        />
        <SubmitButton pending={pending}>Simpan password</SubmitButton>
      </form>
    </AuthShell>
  );
}

export default function ResetPasswordPage() {
  return (
    <Suspense>
      <ResetPasswordForm />
    </Suspense>
  );
}

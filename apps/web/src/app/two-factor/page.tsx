"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { AuthShell, FormAlert, SubmitButton, TextField, TextLink } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, type FormFailure } from "@/lib/errors";
import { destinationPath, resolveDestination } from "@/lib/session";

export default function TwoFactorPage() {
  const router = useRouter();
  const [useRecovery, setUseRecovery] = useState(false);
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const value = String(new FormData(event.currentTarget).get("code") ?? "").trim();
    setPending(true);
    setFailure(null);

    const outcome = await attempt(() =>
      api.POST("/auth/two-factor-challenge", {
        body: useRecovery ? { recovery_code: value } : { code: value },
      }),
    );

    if (!outcome.ok) {
      setFailure(outcome.failure);
      setPending(false);
      return;
    }
    router.replace(destinationPath(await resolveDestination()));
  }

  return (
    <AuthShell
      title="Verifikasi dua langkah"
      description={
        useRecovery
          ? "Masukkan salah satu kode pemulihan yang Anda simpan saat mengaktifkan 2FA."
          : "Masukkan 6 digit kode dari aplikasi authenticator Anda."
      }
      footer={
        <div className="flex justify-between">
          <button
            type="button"
            onClick={() => {
              setUseRecovery((v) => !v);
              setFailure(null);
            }}
            className="font-medium text-brand-700 hover:underline dark:text-brand-400"
          >
            {useRecovery ? "Pakai kode authenticator" : "Pakai kode pemulihan"}
          </button>
          <TextLink href="/login">Kembali</TextLink>
        </div>
      }
    >
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {failure ? <FormAlert>{failure.message}</FormAlert> : null}
        <TextField
          key={useRecovery ? "recovery" : "totp"}
          label={useRecovery ? "Kode pemulihan" : "Kode verifikasi"}
          name="code"
          inputMode={useRecovery ? "text" : "numeric"}
          autoComplete="one-time-code"
          required
          autoFocus
        />
        <SubmitButton pending={pending}>Verifikasi</SubmitButton>
      </form>
    </AuthShell>
  );
}

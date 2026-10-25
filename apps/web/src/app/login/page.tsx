"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { AuthShell, FormAlert, SubmitButton, TextField, TextLink } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, type FormFailure } from "@/lib/errors";
import { safeNext } from "@/lib/navigation";
import { destinationPath, resolveDestination } from "@/lib/session";

function LoginForm() {
  const router = useRouter();
  const params = useSearchParams();
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/auth/login", {
        body: { email: String(form.get("email") ?? ""), password: String(form.get("password") ?? "") },
      });
    });

    if (!outcome.ok) {
      // 422 pada login = kredensial salah atau format email salah; API sengaja tidak membedakan sebabnya.
      const rejected = Object.keys(outcome.failure.fields).length > 0;
      setFailure(rejected ? { message: "Email/ID siswa atau password salah.", fields: {} } : outcome.failure);
      setPending(false);
      return;
    }

    if (outcome.result.data?.two_factor) {
      router.replace("/two-factor");
      return;
    }
    const next = safeNext(params.get("next"));
    router.replace(next ?? destinationPath(await resolveDestination()));
  }

  return (
    <AuthShell
      title="Masuk"
      description="Gunakan akun yang didaftarkan oleh admin sekolah Anda."
      footer={<TextLink href="/forgot-password">Lupa password?</TextLink>}
    >
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {params.get("reset") === "1" ? (
          <FormAlert tone="success">Password berhasil diubah. Silakan masuk.</FormAlert>
        ) : null}
        {params.get("invited") === "1" ? (
          <FormAlert tone="success">Akun Anda sudah dibuat. Silakan masuk untuk mulai.</FormAlert>
        ) : null}
        {failure ? <FormAlert>{failure.message}</FormAlert> : null}
        <TextField
          label="Email atau ID siswa"
          name="email"
          type="text"
          autoComplete="username"
          autoCapitalize="none"
          spellCheck={false}
          hint="Siswa: kode-sekolah/NIS, mis. smpn1/2024001"
          required
          autoFocus
        />
        <TextField label="Password" name="password" type="password" autoComplete="current-password" required />
        <SubmitButton pending={pending}>Masuk</SubmitButton>
      </form>
    </AuthShell>
  );
}

export default function LoginPage() {
  return (
    <Suspense>
      <LoginForm />
    </Suspense>
  );
}

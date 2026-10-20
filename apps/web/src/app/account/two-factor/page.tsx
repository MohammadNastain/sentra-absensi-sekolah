"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { AppShell } from "@/components/AppShell";
import { FormAlert, PrimaryButton, SecondaryButton, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, type FormFailure } from "@/lib/errors";
import { destinationPath, resolveDestination, type Me } from "@/lib/session";

type Step =
  | { name: "loading" }
  | { name: "password"; purpose: "enable" | "disable" }
  | { name: "scan"; svg: string; secret: string }
  | { name: "codes"; codes: string[] }
  | { name: "enabled" };

/**
 * Pengaturan 2FA (TOTP). Wajib bagi Super Admin; opsional bagi user lain. Endpoint Fortify meminta
 * konfirmasi password yang baru; bila kedaluwarsa API menjawab 423 dan formulir password muncul lagi.
 */
export default function TwoFactorSetupPage() {
  const router = useRouter();
  const [me, setMe] = useState<Me | null>(null);
  const [step, setStep] = useState<Step>({ name: "loading" });
  const [failure, setFailure] = useState<FormFailure | null>(null);
  const [pending, setPending] = useState(false);

  const loadMe = useCallback(async () => {
    try {
      const { data } = await api.GET("/auth/me");
      if (!data) {
        router.replace("/login");
        return;
      }
      setMe(data.data);
      setStep(data.data.two_factor_enabled ? { name: "enabled" } : { name: "password", purpose: "enable" });
    } catch {
      router.replace("/login");
    }
  }, [router]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat status 2FA awal
    void loadMe();
  }, [loadMe]);

  async function confirmPassword(password: string) {
    return attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/auth/confirm-password", { body: { password } });
    });
  }

  async function startEnable(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const password = String(new FormData(event.currentTarget).get("password") ?? "");
    setPending(true);
    setFailure(null);

    const confirmed = await confirmPassword(password);
    if (!confirmed.ok) {
      setFailure(confirmed.failure);
      setPending(false);
      return;
    }

    try {
      const enabled = await api.POST("/auth/two-factor");
      if (!enabled.response.ok) throw new Error("enable");
      const [qr, key] = await Promise.all([api.GET("/auth/two-factor/qr-code"), api.GET("/auth/two-factor/secret-key")]);
      if (!qr.data?.svg || !key.data?.secretKey) throw new Error("secret");
      setStep({ name: "scan", svg: qr.data.svg, secret: key.data.secretKey });
    } catch {
      setFailure({ message: "Gagal menyiapkan 2FA. Coba lagi.", fields: {} });
    }
    setPending(false);
  }

  async function confirmCode(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const code = String(new FormData(event.currentTarget).get("code") ?? "").trim();
    setPending(true);
    setFailure(null);

    const outcome = await attempt(() => api.POST("/auth/two-factor/confirm", { body: { code } }));
    if (!outcome.ok) {
      setFailure(outcome.failure);
      setPending(false);
      return;
    }
    if (outcome.result.response.status === 423) {
      setStep({ name: "password", purpose: "enable" });
      setPending(false);
      return;
    }

    try {
      const { data } = await api.GET("/auth/two-factor/recovery-codes");
      setStep({ name: "codes", codes: data ?? [] });
    } catch {
      setStep({ name: "codes", codes: [] });
    }
    setPending(false);
  }

  async function disable(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const password = String(new FormData(event.currentTarget).get("password") ?? "");
    setPending(true);
    setFailure(null);

    const confirmed = await confirmPassword(password);
    if (!confirmed.ok) {
      setFailure(confirmed.failure);
      setPending(false);
      return;
    }
    try {
      const result = await api.DELETE("/auth/two-factor");
      if (!result.response.ok) throw new Error("disable");
      await loadMe();
    } catch {
      setFailure({ message: "Gagal mematikan 2FA. Coba lagi.", fields: {} });
    }
    setPending(false);
  }

  async function finish() {
    router.replace(destinationPath(await resolveDestination()));
  }

  return (
    <AppShell context="Keamanan akun" userLabel={me?.email}>
      <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Verifikasi dua langkah (2FA)</h1>

      <section className="max-w-xl rounded-2xl border border-zinc-200/70 bg-white shadow-card p-6 dark:border-zinc-800 dark:bg-zinc-900">
        {step.name === "loading" ? (
          <p role="status" className="text-zinc-600 dark:text-zinc-400">
            Memuat…
          </p>
        ) : null}

        {step.name === "password" && step.purpose === "enable" ? (
          <form onSubmit={startEnable} noValidate className="space-y-4">
            <p className="text-sm text-zinc-600 dark:text-zinc-400">
              {me?.is_platform_admin
                ? "Akun Super Admin wajib memakai 2FA sebelum dapat membuka area platform. "
                : "Tambahkan lapisan keamanan dengan kode dari aplikasi authenticator. "}
              Masukkan password Anda untuk memulai.
            </p>
            {failure ? <FormAlert>{failure.message}</FormAlert> : null}
            <TextField
              label="Password"
              name="password"
              type="password"
              autoComplete="current-password"
              error={failure?.fields.password}
              required
              autoFocus
            />
            <PrimaryButton type="submit" pending={pending}>
              Mulai aktivasi
            </PrimaryButton>
          </form>
        ) : null}

        {step.name === "scan" ? (
          <form onSubmit={confirmCode} noValidate className="space-y-4">
            <p className="text-sm text-zinc-600 dark:text-zinc-400">
              1. Pindai kode QR dengan aplikasi authenticator (Google Authenticator, Authy, 1Password, dll.).
              <br />
              2. Masukkan kode 6 digit yang muncul untuk menyelesaikan aktivasi.
            </p>
            {/* SVG dari server kita sendiri, ditampilkan lewat <img> agar tidak pernah dieksekusi sebagai markup. */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              alt="Kode QR untuk aplikasi authenticator"
              width={192}
              height={192}
              className="rounded-lg border border-zinc-200 bg-white p-2"
              src={`data:image/svg+xml;utf8,${encodeURIComponent(step.svg)}`}
            />
            <details className="text-sm">
              <summary className="cursor-pointer text-emerald-700 dark:text-emerald-400">Tidak bisa memindai? Masukkan kunci manual</summary>
              <p className="mt-2 break-all rounded-lg bg-zinc-100 p-3 font-mono text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">
                {step.secret}
              </p>
            </details>
            {failure ? <FormAlert>{failure.message}</FormAlert> : null}
            <TextField
              label="Kode verifikasi"
              name="code"
              inputMode="numeric"
              autoComplete="one-time-code"
              error={failure?.fields.code}
              required
              autoFocus
            />
            <PrimaryButton type="submit" pending={pending}>
              Aktifkan 2FA
            </PrimaryButton>
          </form>
        ) : null}

        {step.name === "codes" ? (
          <div className="space-y-4">
            <FormAlert tone="success">2FA aktif.</FormAlert>
            <p className="text-sm text-zinc-600 dark:text-zinc-400">
              Simpan kode pemulihan ini di tempat aman. Tiap kode hanya berlaku sekali dan dipakai bila Anda kehilangan
              akses ke aplikasi authenticator. Kode ini tidak akan ditampilkan lagi secara otomatis.
            </p>
            <ul className="grid gap-2 rounded-lg bg-zinc-100 p-4 font-mono text-sm text-zinc-900 sm:grid-cols-2 dark:bg-zinc-950 dark:text-zinc-100">
              {step.codes.map((code) => (
                <li key={code}>{code}</li>
              ))}
            </ul>
            <PrimaryButton type="button" onClick={() => void finish()}>
              Saya sudah menyimpannya
            </PrimaryButton>
          </div>
        ) : null}

        {step.name === "enabled" ? (
          <form onSubmit={disable} noValidate className="space-y-4">
            <FormAlert tone="success">2FA aktif untuk akun ini.</FormAlert>
            {me?.is_platform_admin ? (
              <p className="text-sm text-zinc-600 dark:text-zinc-400">
                Akun Super Admin wajib memakai 2FA. Bila dimatikan, area platform tidak dapat dibuka sampai 2FA diaktifkan
                lagi.
              </p>
            ) : null}
            {failure ? <FormAlert>{failure.message}</FormAlert> : null}
            <TextField
              label="Password (untuk mematikan 2FA)"
              name="password"
              type="password"
              autoComplete="current-password"
              error={failure?.fields.password}
              required
            />
            <div className="flex gap-2">
              <PrimaryButton type="submit" pending={pending}>
                Matikan 2FA
              </PrimaryButton>
              <SecondaryButton type="button" onClick={() => void finish()}>
                Kembali
              </SecondaryButton>
            </div>
          </form>
        ) : null}
      </section>
    </AppShell>
  );
}

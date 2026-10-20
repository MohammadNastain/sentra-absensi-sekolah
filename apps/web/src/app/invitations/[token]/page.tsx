"use client";

import type { Schemas } from "@sentra/api-client";
import { useRouter } from "next/navigation";
import { use, useEffect, useState } from "react";
import { AuthShell, FormAlert, SubmitButton, TextField, TextLink } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import type { Me } from "@/lib/session";

type PublicInvitation = Schemas["PublicInvitation"];

type Loaded =
  | { status: "loading" }
  | { status: "unavailable"; message: string }
  | { status: "ready"; invitation: PublicInvitation; me: Me | null };

/** Nama peran di undangan publik sudah berupa label tampilan dari server (bukan kode). */
export default function InvitationPage(props: PageProps<"/invitations/[token]">) {
  const { token } = use(props.params);
  const router = useRouter();
  const [state, setState] = useState<Loaded>({ status: "loading" });
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const [inv, me] = await Promise.all([
          api.GET("/invitations/{token}", { params: { path: { token } } }),
          api.GET("/auth/me"),
        ]);
        if (cancelled) return;
        if (inv.error !== undefined || !inv.data) {
          setState({ status: "unavailable", message: toFormFailure(inv.error).message });
          return;
        }
        setState({ status: "ready", invitation: inv.data.data, me: me.data?.data ?? null });
      } catch (e) {
        if (!cancelled) setState({ status: "unavailable", message: toFormFailure(e).message });
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [token]);

  async function accept(body: { name?: string; password?: string; password_confirmation?: string }) {
    setPending(true);
    setFailure(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/invitations/{token}/accept", { params: { path: { token } }, body });
    });
    setPending(false);

    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    const created = outcome.result.data?.data;
    // Akun baru belum punya sesi: arahkan masuk. Akun lama sudah login: langsung ke sekolah.
    router.replace(created?.account_created ? "/login?invited=1" : `/schools/${created?.school.code ?? ""}`);
  }

  if (state.status === "loading") {
    return (
      <AuthShell title="Undangan">
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat undangan…
        </p>
      </AuthShell>
    );
  }

  if (state.status === "unavailable") {
    return (
      <AuthShell title="Undangan tidak berlaku" footer={<TextLink href="/login">Ke halaman masuk</TextLink>}>
        <FormAlert>{state.message}</FormAlert>
      </AuthShell>
    );
  }

  const { invitation, me } = state;
  const title = `Bergabung dengan ${invitation.school.name}`;
  const summary = (
    <p className="text-sm text-zinc-600 dark:text-zinc-400">
      Peran: <strong className="text-zinc-900 dark:text-zinc-100">{invitation.roles.join(", ")}</strong>
      <br />
      Undangan untuk <span className="font-mono">{invitation.email}</span>
    </p>
  );

  // Email sudah terdaftar: harus login sebagai pemilik email itu.
  if (invitation.account_exists) {
    return (
      <AuthShell title={title}>
        <div className="space-y-4">
          {summary}
          {failure ? <FormAlert>{failure.message}</FormAlert> : null}
          {me ? (
            <>
              <p className="text-sm text-zinc-600 dark:text-zinc-400">
                Anda masuk sebagai <strong>{me.email}</strong>.
              </p>
              <button
                type="button"
                disabled={pending}
                onClick={() => accept({})}
                className="w-full rounded-lg bg-brand-700 px-4 py-2.5 font-medium text-white hover:bg-brand-800 disabled:opacity-60"
              >
                {pending ? "Memproses…" : "Terima undangan"}
              </button>
            </>
          ) : (
            <>
              <p className="text-sm text-zinc-600 dark:text-zinc-400">
                Email ini sudah punya akun. Masuk terlebih dahulu untuk menerima undangan.
              </p>
              <TextLink href={`/login?next=${encodeURIComponent(`/invitations/${token}`)}`}>Masuk untuk melanjutkan</TextLink>
            </>
          )}
        </div>
      </AuthShell>
    );
  }

  // Email baru: buat akun sekaligus.
  return (
    <AuthShell title={title} description="Buat akun Anda untuk menerima undangan ini.">
      <form
        className="space-y-4"
        noValidate
        onSubmit={(event) => {
          event.preventDefault();
          const form = new FormData(event.currentTarget);
          void accept({
            name: String(form.get("name") ?? ""),
            password: String(form.get("password") ?? ""),
            password_confirmation: String(form.get("password_confirmation") ?? ""),
          });
        }}
      >
        {summary}
        {failure ? <FormAlert>{failure.message}</FormAlert> : null}
        <TextField label="Nama lengkap" name="name" autoComplete="name" error={failure?.fields.name} required autoFocus />
        <TextField
          label="Password"
          name="password"
          type="password"
          autoComplete="new-password"
          error={failure?.fields.password}
          hint="Minimal 10 karakter."
          required
        />
        <TextField
          label="Ulangi password"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          required
        />
        <SubmitButton pending={pending}>Buat akun dan terima undangan</SubmitButton>
      </form>
    </AuthShell>
  );
}

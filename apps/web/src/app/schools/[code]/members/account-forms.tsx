"use client";

import type { Schemas } from "@sentra/api-client";
import { useState } from "react";
import { FormAlert, PrimaryButton, RowButton, SecondaryButton, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, type FormFailure } from "@/lib/errors";
import { ROLE_LABELS } from "@/lib/school-options";

type Member = Schemas["Member"];
type Role = Schemas["Role"];

/** Peran yang dapat diberikan lewat akun langsung (operator terminal memakai perangkat, bukan akun). */
const ACCOUNT_ROLES: Role[] = ["homeroom_teacher", "duty_officer", "principal", "school_admin"];

const ALPHABET = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789";

/** Password acak 12 karakter (tanpa huruf yang mudah tertukar) untuk diserahkan ke pemilik akun. */
export function generatePassword(): string {
  const bytes = crypto.getRandomValues(new Uint32Array(12));

  return Array.from(bytes, (n) => ALPHABET[n % ALPHABET.length]).join("");
}

function CopyButton({ text, label }: { text: string; label: string }) {
  const [copied, setCopied] = useState(false);

  return (
    <SecondaryButton
      type="button"
      onClick={async () => {
        try {
          await navigator.clipboard.writeText(text);
          setCopied(true);
          setTimeout(() => setCopied(false), 1800);
        } catch {
          // Clipboard diblokir: pengguna dapat menyalin manual dari kotak di atas.
        }
      }}
    >
      {copied ? "Tersalin ✓" : label}
    </SecondaryButton>
  );
}

/** Kotak kredensial yang diserahkan admin ke pemilik akun; password hanya tampil di layar ini. */
function CredentialBox({ email, password }: { email: string; password: string }) {
  return (
    <div className="space-y-3">
      <FormAlert tone="success">Akun dibuat. Catat atau salin sekarang: password tidak akan ditampilkan lagi.</FormAlert>
      <dl className="rounded-2xl bg-zinc-50 p-4 text-sm dark:bg-zinc-950">
        <div className="flex items-baseline justify-between gap-3">
          <dt className="text-zinc-500">Email (untuk login)</dt>
          <dd data-testid="new-account-email" className="font-mono font-semibold text-zinc-900 dark:text-zinc-50">{email}</dd>
        </div>
        <div className="mt-2 flex items-baseline justify-between gap-3">
          <dt className="text-zinc-500">Password</dt>
          <dd data-testid="new-account-password" className="font-mono font-semibold text-zinc-900 dark:text-zinc-50">{password}</dd>
        </div>
      </dl>
      <CopyButton text={`Email: ${email}\nPassword: ${password}`} label="Salin email dan password" />
    </div>
  );
}

/** Buat akun anggota langsung (tanpa undangan surel). Peran Wali Kelas otomatis membuat data guru yang tertaut. */
export function CreateAccountForm({ code, onCancel, onCreated }: { code: string; onCancel: () => void; onCreated: (member: Member) => void }) {
  const [roles, setRoles] = useState<Role[]>(["homeroom_teacher"]);
  const [password, setPassword] = useState(() => generatePassword());
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);
  const [done, setDone] = useState<{ email: string; password: string } | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const name = String(form.get("name") ?? "").trim();
    const email = String(form.get("email") ?? "").trim();
    const employee = String(form.get("employee_number") ?? "").trim();
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/schools/{school}/members/accounts", {
        params: { path: { school: code } },
        body: { name, email, password, roles, employee_number: employee === "" ? null : employee },
      });
    });
    setPending(false);

    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    if (outcome.result.data) {
      setDone({ email: outcome.result.data.data.user.email, password });
      onCreated(outcome.result.data.data);
    }
  }

  if (done) {
    return (
      <div className="space-y-4">
        <CredentialBox email={done.email} password={done.password} />
        <div className="flex justify-end">
          <PrimaryButton type="button" onClick={onCancel}>
            Selesai
          </PrimaryButton>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <TextField label="Nama lengkap" name="name" autoComplete="off" error={failure?.fields.name} required maxLength={200} />
      <TextField
        label="Email (dipakai untuk login)"
        name="email"
        type="email"
        autoComplete="off"
        error={failure?.fields.email}
        hint="Tidak perlu email asli yang aktif; cukup unik, misalnya nama@sekolah.test."
        required
      />
      <div>
        <TextField
          label="Password"
          name="password"
          autoComplete="off"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          error={failure?.fields.password}
          hint="Minimal 10 karakter. Sudah diisi otomatis; boleh diganti."
          required
        />
        <RowButton onClick={() => setPassword(generatePassword())}>
          Buat password acak baru
        </RowButton>
      </div>
      <fieldset>
        <legend className="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Jabatan</legend>
        <div className="mt-2 grid gap-2 sm:grid-cols-2">
          {ACCOUNT_ROLES.map((role) => (
            <label key={role} className="flex items-center gap-2 text-sm text-zinc-800 dark:text-zinc-200">
              <input
                type="checkbox"
                checked={roles.includes(role)}
                onChange={(event) => setRoles(event.target.checked ? [...roles, role] : roles.filter((r) => r !== role))}
              />
              {ROLE_LABELS[role]}
            </label>
          ))}
        </div>
        {failure?.fields.roles ? <p className="mt-1 text-sm text-red-700 dark:text-red-400">{failure.fields.roles}</p> : null}
        {roles.includes("homeroom_teacher") ? (
          <p className="mt-2 text-xs text-zinc-500">Wali Kelas: data guru dibuat otomatis. Tetapkan kelasnya di menu Kelas.</p>
        ) : null}
      </fieldset>
      {roles.includes("homeroom_teacher") ? (
        <TextField label="NIP / nomor pegawai (opsional)" name="employee_number" autoComplete="off" error={failure?.fields.employee_number} maxLength={50} />
      ) : null}
      <div className="flex justify-end gap-2">
        <SecondaryButton type="button" onClick={onCancel}>
          Batal
        </SecondaryButton>
        <PrimaryButton type="submit" pending={pending} disabled={roles.length === 0}>
          Buat akun
        </PrimaryButton>
      </div>
    </form>
  );
}

/** Admin menetapkan password baru anggota (tanpa surel) lalu menyerahkannya langsung. */
export function SetPasswordForm({ code, member, onCancel }: { code: string; member: Member; onCancel: () => void }) {
  const [password, setPassword] = useState(() => generatePassword());
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);
  const [done, setDone] = useState(false);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setFailure(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.PUT("/schools/{school}/members/{membership}/password", {
        params: { path: { school: code, membership: member.id } },
        body: { password },
      });
    });
    setPending(false);

    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    setDone(true);
  }

  if (done) {
    return (
      <div className="space-y-4">
        <CredentialBox email={member.user.email} password={password} />
        <div className="flex justify-end">
          <PrimaryButton type="button" onClick={onCancel}>
            Selesai
          </PrimaryButton>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      <p className="text-sm text-zinc-600 dark:text-zinc-400">
        {member.user.name} · {member.user.email}
      </p>
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <div>
        <TextField
          label="Password baru"
          name="password"
          autoComplete="off"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          error={failure?.fields.password}
          hint="Minimal 10 karakter. Serahkan langsung ke pemilik akun."
          required
        />
        <RowButton onClick={() => setPassword(generatePassword())}>
          Buat password acak baru
        </RowButton>
      </div>
      <div className="flex justify-end gap-2">
        <SecondaryButton type="button" onClick={onCancel}>
          Batal
        </SecondaryButton>
        <PrimaryButton type="submit" pending={pending}>
          Simpan password
        </PrimaryButton>
      </div>
    </form>
  );
}

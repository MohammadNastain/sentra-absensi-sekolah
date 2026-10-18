"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { CardSheet, type CardData } from "@/components/QrCard";
import { FormAlert, PrimaryButton, SecondaryButton, TextAreaField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { formatDateTime } from "@/lib/format";
import { useSchool } from "../school-context";

type Credential = Schemas["Credential"];

const STATUS_LABEL: Record<Schemas["CredentialStatus"], string> = { active: "Aktif", blocked: "Diblokir", revoked: "Dicabut" };

type Pending = { kind: "block" | "revoke"; credential: Credential } | { kind: "unblock"; credential: Credential };

/** Kartu satu siswa (QR dan RFID): riwayat, terbit/daftar, blokir, cabut. Token QR hanya tampil sekali setelah terbit. */
export function CredentialsPanel({
  studentId,
  studentName,
  studentNumber,
  className,
  onClose,
}: {
  studentId: string;
  studentName: string;
  studentNumber: string;
  className: string | null;
  onClose: () => void;
}) {
  const { code, profile } = useSchool();
  const [rows, setRows] = useState<Credential[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [card, setCard] = useState<CardData | null>(null);
  const [pending, setPending] = useState(false);
  const [action, setAction] = useState<Pending | null>(null);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/students/{student}/credentials", {
        params: { path: { school: code, student: studentId } },
      });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setRows(data.data);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, studentId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat riwayat kredensial
    void load();
  }, [load]);

  async function issue() {
    setPending(true);
    setFailure(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/schools/{school}/students/{student}/credentials/qr", {
        params: { path: { school: code, student: studentId } },
      });
    });
    setPending(false);
    if (!outcome.ok) return setFailure(outcome.failure);
    if (outcome.result.data) setCard({ studentName, studentNumber, className, token: outcome.result.data.data.token });
    void load();
  }

  const active = rows?.find((r) => r.credential_type === "qr" && (r.status === "active" || r.status === "blocked"));
  const activeRfid = rows?.find((r) => r.credential_type === "rfid" && (r.status === "active" || r.status === "blocked"));

  if (card) {
    return (
      <div className="space-y-4">
        <FormAlert tone="success">
          Kartu baru diterbitkan. Kode QR hanya tampil sekarang; cetak atau simpan sebelum menutup dialog ini.
        </FormAlert>
        <div className="mx-auto max-w-xs">
          <CardSheet schoolName={profile.name} cards={[card]} />
        </div>
        <div className="flex justify-end gap-2">
          <SecondaryButton type="button" onClick={() => window.print()}>
            Cetak
          </SecondaryButton>
          <PrimaryButton type="button" onClick={() => setCard(null)}>
            Selesai
          </PrimaryButton>
        </div>
      </div>
    );
  }

  if (action) {
    return (
      <ReasonForm
        action={action}
        code={code}
        onCancel={() => setAction(null)}
        onDone={() => {
          setAction(null);
          void load();
        }}
      />
    );
  }

  return (
    <div className="space-y-4">
      {error ? <FormAlert>{error}</FormAlert> : null}
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      {rows === null && !error ? <p role="status">Memuat…</p> : null}
      {rows && rows.length === 0 ? <p className="text-zinc-600 dark:text-zinc-400">Belum ada kartu.</p> : null}
      {rows && rows.length > 0 ? (
        <ul className="divide-y divide-zinc-100 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-800 dark:border-zinc-800">
          {rows.map((row) => (
            <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
              <div>
                <p className="font-medium">
                  {STATUS_LABEL[row.status]} · diterbitkan {formatDateTime(row.issued_at, profile.timezone)}
                  {row.credential_type === "rfid" ? ` · RFID ${row.value_hint ?? ""}` : ""}
                </p>
                {row.status_reason ? <p className="text-zinc-600 dark:text-zinc-400">Alasan: {row.status_reason}</p> : null}
              </div>
              <div className="flex gap-3">
                {row.status === "active" ? (
                  <button type="button" className="font-medium text-emerald-700 hover:underline dark:text-emerald-400" onClick={() => setAction({ kind: "block", credential: row })}>
                    Blokir
                  </button>
                ) : null}
                {row.status === "blocked" ? (
                  <button type="button" className="font-medium text-emerald-700 hover:underline dark:text-emerald-400" onClick={() => setAction({ kind: "unblock", credential: row })}>
                    Buka blokir
                  </button>
                ) : null}
                {row.status !== "revoked" ? (
                  <button type="button" className="font-medium text-red-700 hover:underline dark:text-red-400" onClick={() => setAction({ kind: "revoke", credential: row })}>
                    Cabut
                  </button>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      ) : null}
      <p className="text-sm text-zinc-600 dark:text-zinc-400">
        {active ? "Menerbitkan kartu baru mencabut kartu yang sekarang." : "Siswa belum memiliki kartu aktif."}
      </p>
      <RfidForm studentId={studentId} code={code} replacing={activeRfid !== undefined} onDone={() => void load()} />
      <div className="flex justify-end gap-2">
        <SecondaryButton type="button" onClick={onClose}>
          Tutup
        </SecondaryButton>
        <PrimaryButton type="button" pending={pending} onClick={issue}>
          {active ? "Terbitkan ulang kartu" : "Terbitkan kartu QR"}
        </PrimaryButton>
      </div>
    </div>
  );
}

function ReasonForm({ action, code, onCancel, onDone }: { action: Pending; code: string; onCancel: () => void; onDone: () => void }) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);
  const { kind, credential } = action;

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const reason = String(new FormData(event.currentTarget).get("reason") ?? "").trim();
    setPending(true);
    setFailure(null);
    const path = { params: { path: { school: code, credential: credential.id } } };
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      if (kind === "block") return api.POST("/schools/{school}/credentials/{credential}/block", { ...path, body: { reason } });
      if (kind === "revoke") return api.POST("/schools/{school}/credentials/{credential}/revoke", { ...path, body: { reason } });
      return api.POST("/schools/{school}/credentials/{credential}/unblock", { ...path, body: { reason: reason || null } });
    });
    setPending(false);
    if (!outcome.ok) setFailure(outcome.failure);
    else onDone();
  }

  const title = kind === "block" ? "Blokir kartu" : kind === "revoke" ? "Cabut kartu permanen" : "Buka blokir kartu";
  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      <p className="font-medium">{title}</p>
      {kind === "revoke" ? <FormAlert>Kartu yang dicabut tidak dapat dipakai lagi dan tidak bisa dipulihkan; terbitkan kartu baru bila perlu.</FormAlert> : null}
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <TextAreaField
        label={kind === "unblock" ? "Alasan (opsional)" : "Alasan"}
        name="reason"
        error={failure?.fields.reason}
        hint={kind === "unblock" ? undefined : "Minimal 5 karakter. Tercatat di audit."}
      />
      <div className="flex justify-end gap-2">
        <SecondaryButton type="button" onClick={onCancel}>
          Batal
        </SecondaryButton>
        <PrimaryButton type="submit" pending={pending}>
          Simpan
        </PrimaryButton>
      </div>
    </form>
  );
}

/** Pembaca RFID keyboard-wedge "mengetik" UID lalu Enter ke kolom ini; admin juga bisa mengetik manual. */
function RfidForm({ studentId, code, replacing, onDone }: { studentId: string; code: string; replacing: boolean; onDone: () => void }) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);
  const [saved, setSaved] = useState(false);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const uid = String(new FormData(form).get("uid") ?? "").trim();
    if (!uid) return;
    setPending(true);
    setFailure(null);
    setSaved(false);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/schools/{school}/students/{student}/credentials/rfid", {
        params: { path: { school: code, student: studentId } },
        body: { uid },
      });
    });
    setPending(false);
    if (!outcome.ok) return setFailure(outcome.failure);
    form.reset();
    setSaved(true);
    onDone();
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
      <p className="text-sm font-medium">Kartu RFID</p>
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      {saved ? <FormAlert tone="success">Kartu RFID terdaftar.</FormAlert> : null}
      <TextField label="UID kartu" name="uid" autoComplete="off" spellCheck={false} error={failure?.fields.uid} />
      <p className="text-sm text-zinc-600 dark:text-zinc-400">
        Tempelkan kartu ke pembaca saat kolom ini terfokus (pembaca mengisi UID dan menekan Enter).{" "}
        {replacing ? "Mendaftarkan kartu baru mencabut kartu RFID yang sekarang." : ""}
      </p>
      <div className="flex justify-end">
        <PrimaryButton type="submit" pending={pending}>
          Daftarkan kartu RFID
        </PrimaryButton>
      </div>
    </form>
  );
}

"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { terminalApi, type AssistSession, type AssistStudent, type TerminalFailure, type TerminalScan } from "@/lib/terminal-api";

const REASONS: [string, string][] = [
  ["card_forgotten", "Kartu tertinggal"],
  ["card_damaged", "Kartu rusak"],
  ["early_leave", "Pulang awal"],
  ["other", "Lainnya"],
];

const INPUT = "w-full rounded-lg bg-white px-4 py-3 text-lg text-zinc-900";
const BUTTON = "rounded-lg bg-zinc-700 px-5 py-3 text-lg font-medium hover:bg-zinc-600 disabled:opacity-60";
const PRIMARY = "rounded-lg bg-brand-600 px-5 py-3 text-lg font-semibold hover:bg-brand-500 disabled:opacity-60";

/**
 * Mode bantuan di terminal: petugas login, mencari siswa, memverifikasi visual, lalu mencatat. Token sesi hanya
 * disimpan di memori komponen ini; menutup layar atau sesi berakhir menghapusnya.
 */
export function AssistScreen({ onClose, onFailure, onRecorded }: { onClose: () => void; onFailure: (failure: TerminalFailure) => void; onRecorded: () => void }) {
  const [session, setSession] = useState<AssistSession | null>(null);
  const [error, setError] = useState<string | null>(null);
  const tokenRef = useRef<string | null>(null);

  // Kegagalan sesi mengembalikan ke login; kegagalan perangkat (dicabut, dsb.) diteruskan ke terminal.
  const handle = useCallback(
    (failure: TerminalFailure) => {
      if (failure.kind === "assist") {
        tokenRef.current = null;
        setSession(null);
        setError(failure.code === "ASSIST_SESSION_INVALID" ? "Sesi bantuan berakhir. Masuk kembali." : failure.message);
      } else if (failure.kind === "network") setError("Tidak terhubung ke server. Coba lagi.");
      else if (failure.kind === "invalid" || failure.kind === "unavailable") setError(failure.message);
      else onFailure(failure);
    },
    [onFailure],
  );

  const close = useCallback(() => {
    const token = tokenRef.current;
    tokenRef.current = null;
    if (token) void terminalApi.assistEnd(token);
    onClose();
  }, [onClose]);

  useEffect(
    () => () => {
      const token = tokenRef.current;
      if (token) void terminalApi.assistEnd(token);
    },
    [],
  );

  return (
    <main className="flex min-h-screen flex-col items-center gap-6 bg-zinc-950 p-6 text-white">
      <header className="flex w-full max-w-2xl items-center justify-between">
        <h1 className="text-2xl font-bold">Mode bantuan</h1>
        <button type="button" onClick={close} className={BUTTON}>
          {session ? "Akhiri sesi" : "Kembali"}
        </button>
      </header>
      {error ? (
        <p role="alert" className="w-full max-w-2xl rounded-lg bg-red-700 p-3">
          {error}
        </p>
      ) : null}
      {session === null ? (
        <LoginForm
          onSession={(next) => {
            tokenRef.current = next.token;
            setError(null);
            setSession(next);
          }}
          onFailure={handle}
        />
      ) : (
        <AssistForm session={session} onFailure={handle} onError={setError} onRecorded={onRecorded} />
      )}
    </main>
  );
}

function LoginForm({ onSession, onFailure }: { onSession: (session: AssistSession) => void; onFailure: (failure: TerminalFailure) => void }) {
  const [pending, setPending] = useState(false);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setPending(true);
    const code = String(form.get("code") ?? "").trim();
    const result = await terminalApi.assistStart(String(form.get("email") ?? "").trim(), String(form.get("password") ?? ""), code === "" ? null : code);
    setPending(false);
    if (result.ok) onSession(result.data);
    else onFailure(result.failure);
  }

  return (
    <form onSubmit={submit} className="flex w-full max-w-md flex-col gap-3">
      <p className="text-zinc-300">Masuk sebagai Admin atau Petugas Piket untuk mencatat kehadiran secara manual.</p>
      <label htmlFor="assist-email" className="sr-only">
        Email
      </label>
      <input id="assist-email" name="email" type="email" autoComplete="off" placeholder="Email" required className={INPUT} />
      <label htmlFor="assist-password" className="sr-only">
        Password
      </label>
      <input id="assist-password" name="password" type="password" autoComplete="off" placeholder="Password" required className={INPUT} />
      <label htmlFor="assist-code" className="sr-only">
        Kode verifikasi
      </label>
      <input id="assist-code" name="code" inputMode="numeric" autoComplete="off" placeholder="Kode verifikasi (bila 2FA aktif)" className={INPUT} />
      <button type="submit" disabled={pending} className={PRIMARY}>
        {pending ? "Memeriksa…" : "Masuk"}
      </button>
    </form>
  );
}

function AssistForm({
  session,
  onFailure,
  onError,
  onRecorded,
}: {
  session: AssistSession;
  onFailure: (failure: TerminalFailure) => void;
  onError: (message: string | null) => void;
  onRecorded: () => void;
}) {
  const [query, setQuery] = useState("");
  const [students, setStudents] = useState<AssistStudent[] | null>(null);
  const [selected, setSelected] = useState<AssistStudent | null>(null);
  const [reason, setReason] = useState("card_forgotten");
  const [note, setNote] = useState("");
  const [verified, setVerified] = useState(false);
  // Satu kunci per percobaan pencatatan: pengiriman ulang tidak membuat catatan ganda.
  const [key, setKey] = useState(() => crypto.randomUUID());
  const [pending, setPending] = useState(false);
  const [result, setResult] = useState<TerminalScan | null>(null);

  async function search(event: React.FormEvent) {
    event.preventDefault();
    onError(null);
    setResult(null);
    if (query.trim().length < 2) return onError("Ketik minimal 2 karakter nama atau nomor induk.");
    const found = await terminalApi.assistStudents(session.token, query.trim());
    if (found.ok) setStudents(found.data);
    else onFailure(found.failure);
  }

  async function record(event: React.FormEvent) {
    event.preventDefault();
    if (!selected) return;
    setPending(true);
    onError(null);
    const response = await terminalApi.assistRecord(session.token, {
      student_id: selected.id,
      reason_code: reason as "other",
      note: note.trim() === "" ? null : note.trim(),
      verified: true,
      idempotency_key: key,
    });
    setPending(false);
    if (!response.ok) return onFailure(response.failure);
    setResult(response.data);
    setSelected(null);
    setStudents(null);
    setQuery("");
    setNote("");
    setVerified(false);
    setKey(crypto.randomUUID());
    onRecorded();
  }

  return (
    <div className="flex w-full max-w-2xl flex-col gap-4">
      <p className="text-zinc-400">Petugas: {session.officer_name}</p>
      {result ? (
        <p role="status" className="rounded-lg bg-brand-700 p-4 text-xl font-semibold">
          {result.student?.display_name}: {result.message} ({result.recorded_at_local})
        </p>
      ) : null}

      {selected === null ? (
        <>
          <form onSubmit={search} className="flex gap-2">
            <label htmlFor="assist-q" className="sr-only">
              Cari siswa
            </label>
            <input id="assist-q" value={query} onChange={(e) => setQuery(e.target.value)} autoComplete="off" placeholder="Nama atau nomor induk siswa" className={INPUT} />
            <button type="submit" className={PRIMARY}>
              Cari
            </button>
          </form>
          {students?.length === 0 ? <p className="text-zinc-400">Tidak ada siswa aktif yang cocok.</p> : null}
          <ul className="space-y-2">
            {students?.map((s) => (
              <li key={s.id}>
                <button type="button" onClick={() => setSelected(s)} className="flex w-full items-center justify-between rounded-lg bg-zinc-800 px-4 py-3 text-left hover:bg-zinc-700">
                  <span className="text-lg font-medium">{s.full_name}</span>
                  <span className="text-zinc-400">
                    {s.student_number} · {s.class_name}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </>
      ) : (
        <form onSubmit={record} className="flex flex-col gap-3">
          <p className="text-2xl font-bold">
            {selected.full_name} <span className="text-lg font-normal text-zinc-400">{selected.class_name}</span>
          </p>
          <label htmlFor="assist-reason" className="text-sm text-zinc-300">
            Alasan
          </label>
          <select id="assist-reason" value={reason} onChange={(e) => setReason(e.target.value)} className={INPUT}>
            {REASONS.map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
          <label htmlFor="assist-note" className="text-sm text-zinc-300">
            {reason === "other" ? "Catatan (wajib)" : "Catatan (opsional)"}
          </label>
          <textarea id="assist-note" value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} rows={2} className={INPUT} />
          <label className="flex items-start gap-3 text-lg">
            <input type="checkbox" checked={verified} onChange={(e) => setVerified(e.target.checked)} className="mt-1.5 h-5 w-5" />
            <span>Saya telah memverifikasi identitas siswa ini secara langsung.</span>
          </label>
          <div className="flex gap-3">
            <button type="submit" disabled={pending || !verified || (reason === "other" && note.trim() === "")} className={PRIMARY}>
              {pending ? "Mencatat…" : "Catat kehadiran"}
            </button>
            <button type="button" onClick={() => setSelected(null)} className={BUTTON}>
              Batal
            </button>
          </div>
        </form>
      )}
    </div>
  );
}

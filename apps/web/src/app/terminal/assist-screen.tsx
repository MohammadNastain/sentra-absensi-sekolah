"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { terminalApi, type AssistSession, type AssistStudent, type TerminalFailure, type TerminalScan } from "@/lib/terminal-api";
import { Backdrop, Brand, GLASS, TERMINAL_BUTTON, TERMINAL_INPUT, TERMINAL_PRIMARY } from "./terminal-ui";

const REASONS: [string, string][] = [
  ["card_forgotten", "Kartu tertinggal"],
  ["card_damaged", "Kartu rusak"],
  ["early_leave", "Pulang awal"],
  ["other", "Lainnya"],
];

const INPUT = TERMINAL_INPUT;
const BUTTON = TERMINAL_BUTTON;
const PRIMARY = TERMINAL_PRIMARY;

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
    <Backdrop>
      <main className="flex flex-1 flex-col items-center gap-6 p-6">
        <header className={`${GLASS} flex w-full max-w-2xl items-center justify-between gap-4 px-5 py-4`}>
          <div className="flex items-center gap-4">
            <Brand />
            <h1 className="border-l border-white/15 pl-4 text-2xl font-bold">Mode bantuan</h1>
          </div>
          <button type="button" onClick={close} className={BUTTON}>
            {session ? "Akhiri sesi" : "Kembali"}
          </button>
        </header>
        {error ? (
          <p role="alert" className="w-full max-w-2xl animate-pop rounded-2xl border border-red-400/30 bg-red-500/20 p-3 text-red-100">
            {error}
          </p>
        ) : null}
        <div className={`${GLASS} w-full max-w-2xl animate-rise p-6`}>
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
        </div>
      </main>
    </Backdrop>
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
    <form onSubmit={submit} className="flex w-full flex-col gap-3">
      <p className="text-white/70">Masuk sebagai Admin atau Petugas Piket untuk mencatat kehadiran secara manual.</p>
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
      <p className="text-white/55">Petugas: {session.officer_name}</p>
      {result ? (
        <p role="status" className="animate-pop rounded-2xl border border-emerald-400/30 bg-emerald-500/20 p-4 text-xl font-semibold text-emerald-100">
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
          {students?.length === 0 ? <p className="text-white/55">Tidak ada siswa aktif yang cocok.</p> : null}
          <ul className="space-y-2">
            {students?.map((s) => (
              <li key={s.id}>
                <button type="button" onClick={() => setSelected(s)} className="flex w-full items-center justify-between rounded-2xl bg-white/[0.06] px-4 py-3 text-left transition hover:bg-white/[0.13]">
                  <span className="text-lg font-medium">{s.full_name}</span>
                  <span className="text-white/55">
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
            {selected.full_name} <span className="text-lg font-normal text-white/55">{selected.class_name}</span>
          </p>
          <label htmlFor="assist-reason" className="text-sm text-white/70">
            Alasan
          </label>
          <select id="assist-reason" value={reason} onChange={(e) => setReason(e.target.value)} className={INPUT}>
            {REASONS.map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
          <label htmlFor="assist-note" className="text-sm text-white/70">
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

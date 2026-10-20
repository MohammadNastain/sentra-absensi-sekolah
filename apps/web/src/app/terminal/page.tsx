"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { terminalApi, type TerminalConfig, type TerminalFailure, type TerminalRecent, type TerminalScan } from "@/lib/terminal-api";
import { AssistScreen } from "./assist-screen";
import { CameraScanner } from "./camera-scanner";
import { Backdrop, Brand, CenterScreen, GLASS, ResultIcon, ScanFrame, TERMINAL_BUTTON, TERMINAL_INPUT, TERMINAL_PRIMARY } from "./terminal-ui";

type Phase = "loading" | "pairing" | "ready" | "revoked" | "unavailable";
type Display =
  | { kind: "idle" }
  | { kind: "processing" }
  | { kind: "result"; scan: TerminalScan }
  | { kind: "error"; message: string };

const RESULT_VISIBLE_MS = 4000;
const HEARTBEAT_MS = 30_000;
const RECENT_MS = 15_000;
/** Pemindai keyboard-wedge mengetik puluhan karakter per detik; jeda lebih lama dari ini berarti ketikan manusia. */
const WEDGE_MAX_GAP_MS = 120;

type Tone = { card: string; glow: string; kind: "accepted" | "duplicate" | "rejected" | "needs_review" };

/** Warna hasil scan. Hijau = tercatat, biru = sudah tercatat, merah = ditolak, kuning = perlu tinjauan. */
const TONES: Record<string, Tone> = {
  accepted: { card: "from-emerald-500 to-emerald-700", glow: "from-emerald-500/35", kind: "accepted" },
  duplicate: { card: "from-sky-500 to-sky-700", glow: "from-sky-500/35", kind: "duplicate" },
  rejected: { card: "from-red-500 to-red-700", glow: "from-red-500/35", kind: "rejected" },
  needs_review: { card: "from-amber-400 to-amber-600", glow: "from-amber-400/35", kind: "needs_review" },
};

export default function TerminalPage() {
  const [phase, setPhase] = useState<Phase>("loading");
  const [unavailableMessage, setUnavailableMessage] = useState("");
  const [config, setConfig] = useState<TerminalConfig | null>(null);

  const handleFailure = useCallback((failure: TerminalFailure) => {
    if (failure.kind === "unpaired") setPhase("pairing");
    else if (failure.kind === "revoked") setPhase("revoked");
    else if (failure.kind === "unavailable") {
      setUnavailableMessage(failure.message);
      setPhase("unavailable");
    }
  }, []);

  const loadConfig = useCallback(async () => {
    // Jaringan putus saat membuka terminal: terus mencoba, jangan menyerah ke layar kosong.
    for (;;) {
      const result = await terminalApi.config();
      if (result.ok) {
        setConfig(result.data);
        setPhase("ready");
        return;
      }
      if (result.failure.kind !== "network") {
        handleFailure(result.failure);
        return;
      }
      await new Promise((resolve) => setTimeout(resolve, 5000));
    }
  }, [handleFailure]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- inisialisasi sekali saat halaman dibuka
    void loadConfig();
  }, [loadConfig]);

  if (phase === "loading") {
    return (
      <CenterScreen>
        <Brand />
        <p role="status" className="mt-6 animate-glow text-xl text-white/70">
          Memuat terminal…
        </p>
      </CenterScreen>
    );
  }
  if (phase === "pairing") return <PairingScreen onPaired={loadConfig} />;
  if (phase === "revoked") {
    return (
      <CenterScreen glow="from-red-500/30">
        <StatusBadge tone="red">
          <path d="M7 7l10 10M17 7 7 17" />
        </StatusBadge>
        <h1 className="mt-2 text-4xl font-bold">Perangkat dicabut</h1>
        <p className="max-w-lg text-xl text-white/70">Terminal ini tidak lagi berwenang. Hubungi admin sekolah untuk mendaftarkan ulang.</p>
        {/* Browser masih menyimpan identitas perangkat lama; kode pairing baru menimpanya. */}
        <button type="button" onClick={() => setPhase("pairing")} className={`${TERMINAL_PRIMARY} mt-4 text-xl`}>
          Pasangkan perangkat baru
        </button>
      </CenterScreen>
    );
  }
  if (phase === "unavailable" || config === null) {
    return (
      <CenterScreen glow="from-amber-400/30">
        <StatusBadge tone="amber">
          <path d="M12 7v6m0 4h.01" />
        </StatusBadge>
        <h1 className="mt-2 text-4xl font-bold">Terminal tidak tersedia</h1>
        <p className="max-w-lg text-xl text-white/70">{unavailableMessage || "Hubungi admin sekolah."}</p>
      </CenterScreen>
    );
  }
  return <ScanScreen config={config} onFailure={handleFailure} />;
}

function StatusBadge({ tone, children }: { tone: "red" | "amber"; children: React.ReactNode }) {
  const style = tone === "red" ? "bg-red-500/20 ring-red-500/10 text-red-300" : "bg-amber-400/20 ring-amber-400/10 text-amber-300";

  return (
    <span className={`grid size-20 animate-pop place-items-center rounded-full ring-8 ${style}`}>
      <svg viewBox="0 0 24 24" className="size-10" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" aria-hidden="true">
        {children}
      </svg>
    </span>
  );
}

function PairingScreen({ onPaired }: { onPaired: () => void }) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const code = String(new FormData(event.currentTarget).get("code") ?? "").trim();
    setPending(true);
    setError(null);
    const result = await terminalApi.pair(code);
    setPending(false);
    if (result.ok) onPaired();
    else if (result.failure.kind === "invalid") setError("Kode tidak valid atau sudah kedaluwarsa. Minta kode baru ke admin sekolah.");
    else setError("Tidak dapat terhubung ke server. Coba lagi.");
  }

  return (
    <CenterScreen>
      <div className={`${GLASS} flex w-full max-w-md animate-rise flex-col items-center gap-2 p-8`}>
        <Brand />
        <h1 className="mt-6 text-3xl font-bold">Pasangkan terminal</h1>
        <p className="max-w-sm text-lg text-white/65">Masukkan kode pairing dari menu Perangkat di dashboard sekolah.</p>
        <form onSubmit={submit} className="mt-4 flex w-full flex-col gap-3">
          <label htmlFor="code" className="sr-only">
            Kode pairing
          </label>
          <input
            id="code"
            name="code"
            autoFocus
            autoComplete="off"
            autoCapitalize="characters"
            spellCheck={false}
            maxLength={20}
            placeholder="XXXX-XXXX"
            className={`${TERMINAL_INPUT} text-center font-mono text-3xl tracking-[0.35em]`}
          />
          {error ? (
            <p role="alert" className="animate-pop rounded-2xl border border-red-400/30 bg-red-500/20 p-3 text-base text-red-100">
              {error}
            </p>
          ) : null}
          <button type="submit" disabled={pending} className={`${TERMINAL_PRIMARY} text-xl`}>
            {pending ? "Memasangkan…" : "Pasangkan"}
          </button>
        </form>
      </div>
    </CenterScreen>
  );
}

function ScanScreen({ config, onFailure }: { config: TerminalConfig; onFailure: (failure: TerminalFailure) => void }) {
  const [display, setDisplay] = useState<Display>({ kind: "idle" });
  const [recent, setRecent] = useState<TerminalRecent>([]);
  const [cameraOn, setCameraOn] = useState(false);
  const [assistOpen, setAssistOpen] = useState(false);
  const [online, setOnline] = useState(true);
  const [now, setNow] = useState(() => Date.now());
  const [offset, setOffset] = useState(() => new Date(config.server_time).getTime() - Date.now());
  const busy = useRef(false);
  const clearTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const refreshRecent = useCallback(async () => {
    const result = await terminalApi.recent();
    if (result.ok) setRecent(result.data);
    else if (result.failure.kind !== "network") onFailure(result.failure);
  }, [onFailure]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat transaksi awal
    void refreshRecent();
    const recentTimer = setInterval(() => void refreshRecent(), RECENT_MS);
    const clock = setInterval(() => setNow(Date.now()), 1000);
    const beat = setInterval(async () => {
      const result = await terminalApi.heartbeat();
      setOnline(result.ok || result.failure.kind !== "network");
      if (!result.ok && result.failure.kind !== "network") onFailure(result.failure);
      if (result.ok) setOffset(new Date(result.data.server_time).getTime() - Date.now());
    }, HEARTBEAT_MS);
    return () => {
      clearInterval(recentTimer);
      clearInterval(clock);
      clearInterval(beat);
    };
  }, [refreshRecent, onFailure]);

  const showFor = useCallback((next: Display, ms: number) => {
    if (clearTimer.current) clearTimeout(clearTimer.current);
    setDisplay(next);
    clearTimer.current = setTimeout(() => {
      busy.current = false;
      setDisplay({ kind: "idle" });
    }, ms);
  }, []);

  const submit = useCallback(
    async (raw: string) => {
      const value = raw.trim();
      if (!value || busy.current || assistOpen) return;
      busy.current = true;
      setDisplay({ kind: "processing" });

      // Kunci idempotensi dibuat sekali per scan dan dipakai ulang pada percobaan ulang jaringan.
      const key = crypto.randomUUID();
      let result = await terminalApi.scan(value, key);
      for (let attempt = 0; !result.ok && result.failure.kind === "network" && attempt < 2; attempt++) {
        await new Promise((resolve) => setTimeout(resolve, 1000));
        result = await terminalApi.scan(value, key);
      }

      if (result.ok) {
        setOnline(true);
        showFor({ kind: "result", scan: result.data }, RESULT_VISIBLE_MS);
        void refreshRecent();
        return;
      }
      if (result.failure.kind === "network") {
        setOnline(false);
        showFor({ kind: "error", message: "Tidak terhubung ke server. Scan belum tercatat, coba lagi." }, RESULT_VISIBLE_MS);
        return;
      }
      busy.current = false;
      setDisplay({ kind: "idle" });
      onFailure(result.failure);
    },
    [onFailure, refreshRecent, showFor, assistOpen],
  );

  // Pemindai keyboard-wedge: karakter datang cepat dan diakhiri Enter. Ketikan lambat (manusia) diabaikan.
  useEffect(() => {
    let buffer = "";
    let lastAt = 0;
    function onKey(event: KeyboardEvent) {
      const target = event.target as HTMLElement | null;
      if (target && (target.tagName === "INPUT" || target.tagName === "TEXTAREA")) return;
      const at = Date.now();
      if (at - lastAt > WEDGE_MAX_GAP_MS) buffer = "";
      lastAt = at;
      if (event.key === "Enter") {
        const value = buffer;
        buffer = "";
        if (value.length >= 8) {
          event.preventDefault();
          void submit(value);
        }
      } else if (event.key.length === 1) {
        buffer += event.key;
      }
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [submit]);

  const clock = new Intl.DateTimeFormat("id-ID", {
    timeZone: config.school.timezone,
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hourCycle: "h23",
  }).format(new Date(now + offset));
  const date = new Intl.DateTimeFormat("id-ID", { timeZone: config.school.timezone, dateStyle: "full" }).format(new Date(now + offset));

  const tone: Tone | null =
    display.kind === "result" ? (TONES[display.scan.result] ?? TONES.rejected) : display.kind === "error" ? TONES.rejected : null;

  if (assistOpen) {
    return <AssistScreen onClose={() => setAssistOpen(false)} onFailure={onFailure} onRecorded={() => void refreshRecent()} />;
  }

  return (
    <Backdrop glow={tone?.glow}>
      <header className="flex flex-wrap items-center justify-between gap-4 px-6 py-5 sm:px-10">
        <div className="flex items-center gap-4">
          <Brand />
          <span className="hidden h-10 w-px bg-white/15 sm:block" />
          <div className="hidden sm:block">
            <p className="text-lg font-semibold">{config.school.name}</p>
            <p className="flex items-center gap-2 text-sm text-white/55">
              <span className={`size-2 animate-glow rounded-full ${online ? "bg-emerald-400" : "bg-amber-400"}`} />
              {config.device.name}
            </p>
          </div>
        </div>
        <div className="text-right">
          <p className="font-mono text-5xl font-semibold tabular-nums tracking-tight" data-testid="clock">
            {clock}
          </p>
          <p className="text-sm capitalize text-white/55">{date}</p>
        </div>
      </header>

      {!online ? (
        <p role="alert" className="mx-6 animate-pop rounded-2xl bg-amber-400 px-6 py-2 text-center font-semibold text-zinc-950 sm:mx-10">
          Koneksi terputus. Scan tidak akan tercatat sampai tersambung kembali.
        </p>
      ) : null}

      <section className="grid flex-1 gap-5 px-6 pb-8 pt-3 sm:px-10 lg:grid-cols-[2fr_1fr]">
        <div className="flex flex-col gap-4">
          <div
            role="status"
            aria-live="assertive"
            data-testid="scan-panel"
            className={`relative flex min-h-[22rem] flex-1 flex-col items-center justify-center gap-3 overflow-hidden rounded-[2rem] p-8 text-center text-white transition-colors duration-500 ${
              tone ? `bg-gradient-to-br ${tone.card} shadow-2xl` : GLASS
            }`}
          >
            <Panel display={display} tone={tone} />
          </div>
          {cameraOn ? <CameraScanner onValue={(v) => void submit(v)} paused={display.kind !== "idle"} /> : null}
          <div className={`${GLASS} flex flex-wrap items-center gap-3 p-3`}>
            <button type="button" onClick={() => setCameraOn((v) => !v)} className={TERMINAL_BUTTON}>
              {cameraOn ? "Matikan kamera" : "Pakai kamera"}
            </button>
            <button type="button" onClick={() => setAssistOpen(true)} className={TERMINAL_BUTTON}>
              Mode bantuan
            </button>
            <form
              className="flex min-w-60 flex-1 gap-2"
              onSubmit={(event) => {
                event.preventDefault();
                const input = event.currentTarget.elements.namedItem("manual") as HTMLInputElement;
                void submit(input.value);
                input.value = "";
              }}
            >
              <label htmlFor="manual" className="sr-only">
                Kode kartu (uji manual)
              </label>
              <input
                id="manual"
                name="manual"
                autoComplete="off"
                spellCheck={false}
                placeholder="Tempel kode kartu (uji)"
                className={`${TERMINAL_INPUT} min-w-0 flex-1 py-2.5 font-mono text-sm`}
              />
              <button type="submit" className={TERMINAL_BUTTON}>
                Kirim
              </button>
            </form>
          </div>
        </div>

        <aside aria-label="Transaksi terakhir" className={`${GLASS} flex flex-col p-5`}>
          <h2 className="mb-4 flex items-center gap-2 text-lg font-semibold">
            <span className="size-2 animate-glow rounded-full bg-brand-300" />
            Transaksi terakhir
          </h2>
          {recent.length === 0 ? <p className="rounded-2xl bg-white/5 p-4 text-white/55">Belum ada.</p> : null}
          <ul className="space-y-2.5">
            {recent.map((row, index) => (
              <li
                key={index}
                className="flex animate-rise items-center gap-3 rounded-2xl bg-white/[0.06] px-3 py-2.5 transition hover:bg-white/10"
                style={{ animationDelay: `${index * 40}ms` }}
              >
                <span className={`h-10 w-1 shrink-0 rounded-full ${barOf(row)}`} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-semibold">{row.display_name ?? "—"}</span>
                  <span className="block truncate text-sm text-white/50">{row.class_name ?? ""}</span>
                </span>
                <span className="text-right text-sm">
                  <span className="block font-mono">{row.recorded_at_local}</span>
                  <span className="text-white/50">{label(row)}</span>
                </span>
              </li>
            ))}
          </ul>
        </aside>
      </section>
    </Backdrop>
  );
}

function barOf(row: TerminalRecent[number]): string {
  if (row.result === "needs_review") return "bg-amber-400";
  if (row.result === "duplicate" || row.event_type === "check_out") return "bg-sky-400";
  return row.is_late ? "bg-amber-400" : "bg-emerald-400";
}

function label(row: TerminalRecent[number]): string {
  if (row.result === "duplicate") return "Sudah tercatat";
  if (row.result === "needs_review") return "Perlu tinjauan";
  if (row.event_type === "check_out") return "Pulang";
  return row.is_late ? "Masuk (terlambat)" : "Masuk";
}

function Panel({ display, tone }: { display: Display; tone: Tone | null }) {
  if (display.kind === "processing") {
    return (
      <>
        <span className="size-14 animate-spin rounded-full border-4 border-white/20 border-t-white" aria-hidden="true" />
        <p className="text-3xl font-semibold">Memproses…</p>
      </>
    );
  }
  if (display.kind === "error") {
    return (
      <>
        <ResultIcon kind="rejected" />
        <p className="max-w-xl text-3xl font-semibold">{display.message}</p>
      </>
    );
  }
  if (display.kind === "idle") {
    return (
      <>
        <ScanFrame active />
        <p className="mt-2 text-4xl font-bold">Siap memindai</p>
        <p className="text-xl text-white/60">Dekatkan kartu QR ke pemindai</p>
      </>
    );
  }

  const { scan } = display;
  return (
    <>
      <ResultIcon kind={tone?.kind ?? "rejected"} />
      {scan.student ? (
        <p className="mt-2 text-5xl font-bold tracking-tight" data-testid="student-name">
          {scan.student.display_name}
        </p>
      ) : null}
      {scan.student?.class_name ? <p className="rounded-full bg-white/20 px-4 py-1 text-xl font-medium">{scan.student.class_name}</p> : null}
      <p className="mt-1 text-3xl font-semibold" data-testid="scan-code">
        {scan.message}
      </p>
    </>
  );
}

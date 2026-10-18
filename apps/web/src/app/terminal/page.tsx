"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { terminalApi, type TerminalConfig, type TerminalFailure, type TerminalRecent, type TerminalScan } from "@/lib/terminal-api";
import { CameraScanner } from "./camera-scanner";

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

const TONES: Record<string, string> = {
  accepted: "bg-emerald-600 text-white",
  duplicate: "bg-sky-600 text-white",
  rejected: "bg-red-700 text-white",
  needs_review: "bg-amber-500 text-zinc-950",
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

  if (phase === "loading") return <Screen><p role="status" className="text-xl">Memuat terminal…</p></Screen>;
  if (phase === "pairing") return <PairingScreen onPaired={loadConfig} />;
  if (phase === "revoked") {
    return (
      <Screen tone="bg-red-800">
        <h1 className="text-4xl font-bold">Perangkat dicabut</h1>
        <p className="mt-3 text-xl">Terminal ini tidak lagi berwenang. Hubungi admin sekolah untuk mendaftarkan ulang.</p>
      </Screen>
    );
  }
  if (phase === "unavailable" || config === null) {
    return (
      <Screen tone="bg-amber-600">
        <h1 className="text-4xl font-bold">Terminal tidak tersedia</h1>
        <p className="mt-3 text-xl">{unavailableMessage || "Hubungi admin sekolah."}</p>
      </Screen>
    );
  }
  return <ScanScreen config={config} onFailure={handleFailure} />;
}

function Screen({ children, tone = "bg-zinc-900" }: { children: React.ReactNode; tone?: string }) {
  return <main className={`flex min-h-screen flex-col items-center justify-center gap-2 p-8 text-center text-white ${tone}`}>{children}</main>;
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
    <Screen>
      <h1 className="text-3xl font-bold">Pasangkan terminal</h1>
      <p className="max-w-md text-lg text-zinc-300">Masukkan kode pairing dari menu Perangkat di dashboard sekolah.</p>
      <form onSubmit={submit} className="mt-4 flex w-full max-w-sm flex-col gap-3">
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
          className="rounded-lg bg-white px-4 py-3 text-center font-mono text-3xl tracking-widest text-zinc-900"
        />
        {error ? <p role="alert" className="rounded-lg bg-red-700 p-3 text-base">{error}</p> : null}
        <button type="submit" disabled={pending} className="rounded-lg bg-emerald-600 px-4 py-3 text-xl font-semibold disabled:opacity-60">
          {pending ? "Memasangkan…" : "Pasangkan"}
        </button>
      </form>
    </Screen>
  );
}

function ScanScreen({ config, onFailure }: { config: TerminalConfig; onFailure: (failure: TerminalFailure) => void }) {
  const [display, setDisplay] = useState<Display>({ kind: "idle" });
  const [recent, setRecent] = useState<TerminalRecent>([]);
  const [cameraOn, setCameraOn] = useState(false);
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
      if (!value || busy.current) return;
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
    [onFailure, refreshRecent, showFor],
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

  const tone = display.kind === "result" ? (TONES[display.scan.result] ?? TONES.rejected) : display.kind === "error" ? TONES.rejected : "bg-zinc-800 text-white";

  return (
    <main className="flex min-h-screen flex-col bg-zinc-950 text-white">
      <header className="flex flex-wrap items-center justify-between gap-2 px-6 py-4">
        <div>
          <p className="text-lg font-semibold">{config.school.name}</p>
          <p className="text-sm text-zinc-400">{config.device.name}</p>
        </div>
        <div className="text-right">
          <p className="font-mono text-4xl tabular-nums" data-testid="clock">{clock}</p>
          <p className="text-sm text-zinc-400">{date}</p>
        </div>
      </header>

      {!online ? <p role="alert" className="bg-amber-500 px-6 py-2 text-center font-semibold text-zinc-950">Koneksi terputus. Scan tidak akan tercatat sampai tersambung kembali.</p> : null}

      <section className="grid flex-1 gap-4 p-6 lg:grid-cols-[2fr_1fr]">
        <div className="flex flex-col gap-4">
          <div role="status" aria-live="assertive" data-testid="scan-panel" className={`flex min-h-72 flex-1 flex-col items-center justify-center gap-2 rounded-2xl p-8 text-center ${tone}`}>
            <Panel display={display} />
          </div>
          {cameraOn ? <CameraScanner onValue={(v) => void submit(v)} paused={display.kind !== "idle"} /> : null}
          <div className="flex flex-wrap items-center gap-3">
            <button type="button" onClick={() => setCameraOn((v) => !v)} className="rounded-lg bg-zinc-700 px-4 py-2 font-medium hover:bg-zinc-600">
              {cameraOn ? "Matikan kamera" : "Pakai kamera"}
            </button>
            <form
              className="flex flex-1 gap-2"
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
              <input id="manual" name="manual" autoComplete="off" spellCheck={false} placeholder="Tempel kode kartu (uji)" className="min-w-0 flex-1 rounded-lg bg-zinc-800 px-3 py-2 font-mono text-sm" />
              <button type="submit" className="rounded-lg bg-zinc-700 px-4 py-2 font-medium hover:bg-zinc-600">
                Kirim
              </button>
            </form>
          </div>
        </div>

        <aside aria-label="Transaksi terakhir" className="rounded-2xl bg-zinc-900 p-4">
          <h2 className="mb-3 text-lg font-semibold">Transaksi terakhir</h2>
          {recent.length === 0 ? <p className="text-zinc-400">Belum ada.</p> : null}
          <ul className="space-y-2">
            {recent.map((row, index) => (
              <li key={index} className="flex items-center justify-between gap-2 rounded-lg bg-zinc-800 px-3 py-2">
                <span>
                  <span className="block font-medium">{row.display_name ?? "—"}</span>
                  <span className="text-sm text-zinc-400">{row.class_name ?? ""}</span>
                </span>
                <span className="text-right text-sm">
                  <span className="block font-mono">{row.recorded_at_local}</span>
                  <span className="text-zinc-400">{label(row)}</span>
                </span>
              </li>
            ))}
          </ul>
        </aside>
      </section>
    </main>
  );
}

function label(row: TerminalRecent[number]): string {
  if (row.result === "duplicate") return "Sudah tercatat";
  if (row.result === "needs_review") return "Perlu tinjauan";
  if (row.event_type === "check_out") return "Pulang";
  return row.is_late ? "Masuk (terlambat)" : "Masuk";
}

function Panel({ display }: { display: Display }) {
  if (display.kind === "processing") return <p className="text-3xl font-semibold">Memproses…</p>;
  if (display.kind === "error") return <p className="text-3xl font-semibold">{display.message}</p>;
  if (display.kind === "idle") {
    return (
      <>
        <p className="text-4xl font-bold">Siap memindai</p>
        <p className="text-xl text-zinc-300">Dekatkan kartu QR ke pemindai</p>
      </>
    );
  }

  const { scan } = display;
  return (
    <>
      {scan.student ? <p className="text-5xl font-bold" data-testid="student-name">{scan.student.display_name}</p> : null}
      {scan.student?.class_name ? <p className="text-2xl opacity-90">{scan.student.class_name}</p> : null}
      <p className="mt-2 text-3xl font-semibold" data-testid="scan-code">{scan.message}</p>
    </>
  );
}

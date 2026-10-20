import type { ReactNode } from "react";
import { BrandMark } from "@/components/AppShell";
import { IconCheck, IconClock, IconShield } from "@/components/icons";

/** Latar layar terminal (kios, selalu gelap): kisi halus, dua cahaya yang bergerak pelan, dan vinyet. */
export function Backdrop({ children, glow = "from-brand-500/25" }: { children: ReactNode; glow?: string }) {
  return (
    <div className="relative min-h-dvh overflow-hidden bg-[#070d18] text-white">
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 opacity-[0.35]"
        style={{
          backgroundImage: "linear-gradient(rgb(255 255 255 / 0.05) 1px, transparent 1px), linear-gradient(90deg, rgb(255 255 255 / 0.05) 1px, transparent 1px)",
          backgroundSize: "44px 44px",
          maskImage: "radial-gradient(ellipse at 50% 40%, black 30%, transparent 75%)",
        }}
      />
      <div aria-hidden="true" className={`pointer-events-none absolute -left-24 -top-24 size-[28rem] animate-float rounded-full bg-gradient-to-br ${glow} to-transparent blur-3xl transition-colors duration-700`} />
      <div aria-hidden="true" className="pointer-events-none absolute -bottom-32 -right-20 size-[30rem] animate-float rounded-full bg-gradient-to-tl from-gold-400/15 to-transparent blur-3xl [animation-delay:-3s]" />
      <div className="relative z-10 flex min-h-dvh flex-col">{children}</div>
    </div>
  );
}

/** Kartu kaca: latar tembus pandang dengan bingkai tipis. */
export const GLASS = "rounded-3xl border border-white/10 bg-white/[0.06] shadow-2xl shadow-black/30 backdrop-blur-xl";

export const TERMINAL_INPUT =
  "w-full rounded-2xl border border-white/10 bg-white/[0.07] px-4 py-3 text-lg text-white placeholder:text-white/35 outline-none transition focus:border-brand-400 focus:bg-white/10 focus:ring-4 focus:ring-brand-500/25";
export const TERMINAL_PRIMARY =
  "rounded-2xl bg-gradient-to-b from-brand-500 to-brand-700 px-5 py-3 text-lg font-semibold text-white shadow-lg shadow-brand-950/40 transition hover:from-brand-400 hover:to-brand-600 active:scale-[0.98] disabled:opacity-60";
export const TERMINAL_BUTTON =
  "rounded-2xl border border-white/10 bg-white/[0.07] px-5 py-3 text-base font-medium text-white transition hover:bg-white/[0.14] active:scale-[0.98] disabled:opacity-60";

/** Kepala merek kecil untuk layar tunggal (pairing, status). */
export function Brand() {
  return (
    <div className="flex items-center gap-3">
      <BrandMark size={44} />
      <span className="text-left leading-tight">
        <span className="block text-xl font-bold tracking-tight">Sentra</span>
        <span className="block text-[11px] font-medium uppercase tracking-[0.16em] text-white/55">Terminal Absensi</span>
      </span>
    </div>
  );
}

/** Layar tunggal terpusat (memuat, dicabut, tidak tersedia). */
export function CenterScreen({ children, glow }: { children: ReactNode; glow?: string }) {
  return (
    <Backdrop glow={glow}>
      <main className="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center">{children}</main>
    </Backdrop>
  );
}

const ICON = "size-20";

/** Ikon hasil scan yang menggambar dirinya: centang, ulang, tolak, atau tinjau. */
export function ResultIcon({ kind }: { kind: "accepted" | "duplicate" | "rejected" | "needs_review" }) {
  const common = { fill: "none", stroke: "currentColor", strokeWidth: 2.4, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };

  return (
    <span className="relative inline-flex">
      <span aria-hidden="true" className="absolute inset-0 animate-ring rounded-full bg-white/30" />
      <span className="relative inline-flex size-28 animate-pop items-center justify-center rounded-full bg-white/20 ring-8 ring-white/10">
        <svg viewBox="0 0 24 24" className={ICON} aria-hidden="true" {...common}>
          {kind === "accepted" ? <path d="m6 12.5 4 4 8-9" pathLength={1} className="animate-draw [stroke-dasharray:1]" /> : null}
          {kind === "duplicate" ? <path d="M4 12a8 8 0 0 1 13.6-5.7L20 8.5M20 4v4.5h-4.5M20 12a8 8 0 0 1-13.6 5.7L4 15.5M4 20v-4.5h4.5" /> : null}
          {kind === "rejected" ? <path d="M7 7l10 10M17 7 7 17" pathLength={1} className="animate-draw [stroke-dasharray:1]" /> : null}
          {kind === "needs_review" ? <path d="M12 7v6m0 4h.01" /> : null}
        </svg>
      </span>
    </span>
  );
}

/** Bingkai pemindai: sudut menyala dan garis laser yang naik-turun saat menunggu kartu. */
export function ScanFrame({ active }: { active: boolean }) {
  return (
    <div className="relative mx-auto aspect-square w-64 max-w-full">
      <div aria-hidden="true" className="absolute inset-0 animate-ring rounded-[2rem] border border-brand-300/40" />
      {["left-0 top-0 rounded-tl-3xl border-l-4 border-t-4", "right-0 top-0 rounded-tr-3xl border-r-4 border-t-4", "bottom-0 left-0 rounded-bl-3xl border-b-4 border-l-4", "bottom-0 right-0 rounded-br-3xl border-b-4 border-r-4"].map((corner) => (
        <span key={corner} aria-hidden="true" className={`absolute size-14 animate-glow border-brand-300 ${corner}`} />
      ))}
      <div className="absolute inset-6 grid place-items-center rounded-2xl bg-white/[0.04]">
        <svg viewBox="0 0 24 24" className="size-24 text-white/30" fill="none" stroke="currentColor" strokeWidth="1.2" aria-hidden="true">
          <rect x="3.5" y="3.5" width="6.5" height="6.5" rx="1.2" />
          <rect x="14" y="3.5" width="6.5" height="6.5" rx="1.2" />
          <rect x="3.5" y="14" width="6.5" height="6.5" rx="1.2" />
          <path d="M14 14h2.5v2.5H14zM18 14h2.5M14 18h2.5v2.5M18 18h2.5v2.5" strokeLinecap="round" />
        </svg>
      </div>
      {active ? (
        <span
          aria-hidden="true"
          className="absolute inset-x-6 top-6 h-0.5 animate-scan rounded-full bg-gradient-to-r from-transparent via-brand-300 to-transparent shadow-[0_0_18px_4px_rgb(91_142_236/0.6)] [--scan-travel:13rem]"
        />
      ) : null}
    </div>
  );
}

export { IconCheck, IconClock, IconShield };

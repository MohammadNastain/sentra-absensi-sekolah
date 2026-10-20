import type { ReactNode } from "react";
import Link from "next/link";
import { BrandMark } from "@/components/AppShell";
import { IconCheck, IconClock, IconShield, IconUsers } from "@/components/icons";

const FIELD =
  "mt-1.5 block w-full rounded-2xl border border-zinc-200/70 bg-white shadow-card px-3.5 py-2.5 text-zinc-900 shadow-sm outline-none transition placeholder:text-zinc-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/15 aria-[invalid=true]:border-red-500 aria-[invalid=true]:ring-red-500/10 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50";

const HIGHLIGHTS = [
  { icon: IconClock, text: "Scan QR dan RFID tercatat real-time" },
  { icon: IconUsers, text: "Satu akun, banyak peran dan sekolah" },
  { icon: IconShield, text: "Data terisolasi per sekolah dan teraudit" },
];

/** Kerangka halaman autentikasi: panel merek bergerak di kiri, kartu formulir di kanan (menumpuk di layar kecil). */
export function AuthShell({
  title,
  description,
  children,
  footer,
}: {
  title: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <main className="grid min-h-dvh flex-1 lg:grid-cols-[1.05fr_1fr]">
      <section className="relative hidden overflow-hidden bg-gradient-to-br from-brand-sidebar-from via-emerald-900 to-brand-sidebar-to text-white lg:flex lg:flex-col lg:justify-between lg:p-14">
        <div aria-hidden="true" className="pointer-events-none absolute inset-0">
          <div className="absolute -right-24 -top-24 size-[28rem] animate-float rounded-full bg-emerald-400/15 blur-3xl" />
          <div className="absolute -bottom-32 -left-20 size-[26rem] animate-float rounded-full bg-gold-400/10 blur-3xl [animation-delay:-3s]" />
          <svg className="absolute inset-0 size-full opacity-[0.07]" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <pattern id="grid" width="44" height="44" patternUnits="userSpaceOnUse">
                <path d="M44 0H0V44" fill="none" stroke="white" strokeWidth="1" />
              </pattern>
            </defs>
            <rect width="100%" height="100%" fill="url(#grid)" />
          </svg>
        </div>

        <div className="relative flex items-center gap-3">
          <BrandMark size={46} />
          <span className="leading-tight">
            <span className="block text-2xl font-bold tracking-tight">Sentra</span>
            <span className="block text-xs font-medium uppercase tracking-[0.16em] text-emerald-200/80">Absensi Sekolah</span>
          </span>
        </div>

        <div className="relative max-w-md">
          <h2 className="animate-rise text-4xl font-bold leading-[1.15] tracking-tight">
            Kehadiran siswa, tercatat rapi dan <span className="text-gold-400">dapat dipercaya.</span>
          </h2>
          <p className="mt-4 animate-rise text-base leading-relaxed text-emerald-50/75 [animation-delay:100ms]">
            Dari gerbang sekolah sampai laporan bulanan. Satu sistem untuk petugas piket, wali kelas, dan pimpinan sekolah.
          </p>
          <ul className="mt-8 space-y-3">
            {HIGHLIGHTS.map(({ icon: Icon, text }, i) => (
              <li key={text} className="flex animate-rise items-center gap-3 text-sm text-emerald-50/90" style={{ animationDelay: `${200 + i * 90}ms` }}>
                <span className="flex size-9 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/15">
                  <Icon width={18} height={18} />
                </span>
                {text}
              </li>
            ))}
          </ul>
        </div>

        <p className="relative flex items-center gap-2 text-xs text-emerald-100/60">
          <IconCheck width={16} height={16} /> Sentra Absensi Sekolah
        </p>
      </section>

      <section className="flex items-center justify-center px-4 py-12 sm:px-8">
        <div className="w-full max-w-md animate-rise rounded-3xl border border-zinc-200/70 bg-white p-8 shadow-lift sm:p-10 dark:border-zinc-800 dark:bg-zinc-900">
          <div className="mb-6 flex items-center gap-2.5 lg:hidden">
            <BrandMark size={34} />
            <span className="font-bold tracking-tight text-zinc-900 dark:text-zinc-50">Sentra Absensi Sekolah</span>
          </div>
          <h1 className="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{title}</h1>
          {description ? <p className="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{description}</p> : null}
          <div className="mt-7">{children}</div>
          {footer ? <div className="mt-6 text-sm text-zinc-600 dark:text-zinc-400">{footer}</div> : null}
        </div>
      </section>
    </main>
  );
}

export function TextField({
  label,
  name,
  error,
  hint,
  ...input
}: {
  label: string;
  name: string;
  error?: string;
  hint?: string;
} & Omit<React.InputHTMLAttributes<HTMLInputElement>, "name">) {
  const describedBy = error ? `${name}-error` : hint ? `${name}-hint` : undefined;
  return (
    <div>
      <label htmlFor={name} className="block text-sm font-semibold text-zinc-800 dark:text-zinc-200">
        {label}
      </label>
      <input id={name} name={name} aria-invalid={error ? true : undefined} aria-describedby={describedBy} className={FIELD} {...input} />
      {error ? (
        <p id={`${name}-error`} className="mt-1.5 text-sm text-red-700 dark:text-red-400">
          {error}
        </p>
      ) : hint ? (
        <p id={`${name}-hint`} className="mt-1.5 text-sm text-zinc-500">
          {hint}
        </p>
      ) : null}
    </div>
  );
}

const BUTTON_BASE =
  "inline-flex items-center justify-center gap-2 rounded-xl font-semibold transition duration-200 focus-visible:outline-offset-2 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100";

export function SubmitButton({ pending, children }: { pending: boolean; children: ReactNode }) {
  return (
    <button
      type="submit"
      disabled={pending}
      className={`${BUTTON_BASE} w-full bg-gradient-to-b from-emerald-600 to-emerald-800 px-4 py-3 text-white shadow-md shadow-emerald-900/20 hover:-translate-y-0.5 hover:shadow-lg hover:shadow-emerald-900/25`}
    >
      {pending ? "Memproses…" : children}
    </button>
  );
}

export function FormAlert({ tone = "error", children }: { tone?: "error" | "success"; children: ReactNode }) {
  const styles =
    tone === "error"
      ? "border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
      : "border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200";
  return (
    <div role={tone === "error" ? "alert" : "status"} className={`animate-pop rounded-xl border px-4 py-2.5 text-sm ${styles}`}>
      {children}
    </div>
  );
}

export function SelectField({
  label,
  name,
  error,
  children,
  ...select
}: {
  label: string;
  name: string;
  error?: string;
  children: ReactNode;
} & Omit<React.SelectHTMLAttributes<HTMLSelectElement>, "name">) {
  return (
    <div>
      <label htmlFor={name} className="block text-sm font-semibold text-zinc-800 dark:text-zinc-200">
        {label}
      </label>
      <select id={name} name={name} aria-invalid={error ? true : undefined} className={FIELD} {...select}>
        {children}
      </select>
      {error ? <p className="mt-1.5 text-sm text-red-700 dark:text-red-400">{error}</p> : null}
    </div>
  );
}

export function TextAreaField({
  label,
  name,
  error,
  hint,
  ...area
}: {
  label: string;
  name: string;
  error?: string;
  hint?: string;
} & Omit<React.TextareaHTMLAttributes<HTMLTextAreaElement>, "name">) {
  return (
    <div>
      <label htmlFor={name} className="block text-sm font-semibold text-zinc-800 dark:text-zinc-200">
        {label}
      </label>
      <textarea id={name} name={name} rows={3} aria-invalid={error ? true : undefined} className={FIELD} {...area} />
      {error ? (
        <p className="mt-1.5 text-sm text-red-700 dark:text-red-400">{error}</p>
      ) : hint ? (
        <p className="mt-1.5 text-sm text-zinc-500">{hint}</p>
      ) : null}
    </div>
  );
}

const BADGE_TONES: Record<string, string> = {
  active: "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300",
  trial: "bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300",
  suspended: "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300",
  terminated: "bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300",
};

const BADGE_LABELS: Record<string, string> = {
  active: "Aktif",
  trial: "Uji coba",
  suspended: "Ditangguhkan",
  terminated: "Diakhiri",
};

export function StatusBadge({ status }: { status: string }) {
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${BADGE_TONES[status] ?? BADGE_TONES.terminated}`}>
      <span className="size-1.5 rounded-full bg-current opacity-70" />
      {BADGE_LABELS[status] ?? status}
    </span>
  );
}

export function PrimaryButton({
  pending,
  children,
  ...button
}: { pending?: boolean; children: ReactNode } & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      {...button}
      disabled={pending || button.disabled}
      className={`${BUTTON_BASE} bg-gradient-to-b from-emerald-600 to-emerald-800 px-4 py-2.5 text-sm text-white shadow-sm shadow-emerald-900/20 hover:-translate-y-0.5 hover:shadow-md`}
    >
      {pending ? "Memproses…" : children}
    </button>
  );
}

export function SecondaryButton({
  children,
  ...button
}: { children: ReactNode } & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      {...button}
      className={`${BUTTON_BASE} border border-zinc-200 bg-white px-4 py-2.5 text-sm text-zinc-700 hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800`}
    >
      {children}
    </button>
  );
}

export function TextLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link href={href} className="font-semibold text-emerald-700 underline-offset-4 hover:underline dark:text-emerald-400">
      {children}
    </Link>
  );
}

/** Judul halaman seragam: judul besar, keterangan opsional, dan slot aksi di kanan. */
export function PageHeader({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">{title}</h1>
        {description ? <p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{description}</p> : null}
      </div>
      {actions}
    </div>
  );
}

/** Blok penahan muat berkilau. */
export function Skeleton({ className = "" }: { className?: string }) {
  return <div aria-hidden="true" className={`skeleton ${className}`} />;
}

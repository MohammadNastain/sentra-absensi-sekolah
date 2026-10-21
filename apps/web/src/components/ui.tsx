import type { ReactNode } from "react";
import Link from "next/link";
import { BrandMark } from "@/components/AppShell";
import { IconArrowLeft, IconCheck, IconClock, IconInbox, IconShield, IconUsers } from "@/components/icons";
import { LogoStage } from "@/components/LogoStage";
import { ThemeToggle } from "@/components/ThemeToggle";

const FIELD =
  "mt-1.5 block w-full rounded-2xl border border-zinc-200/70 bg-white shadow-card px-3.5 py-2.5 text-zinc-900 shadow-sm outline-none transition placeholder:text-zinc-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 aria-[invalid=true]:border-red-500 aria-[invalid=true]:ring-red-500/10 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50";

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
    <main className="relative grid min-h-dvh flex-1 lg:grid-cols-[1.05fr_1fr]">
      <div className="absolute right-4 top-4 z-20 sm:right-8 sm:top-6">
        <ThemeToggle />
      </div>
      <section className="relative flex flex-col items-center overflow-hidden bg-gradient-to-br from-brand-sidebar-from via-brand-900 to-brand-sidebar-to px-6 pb-24 pt-5 text-white sm:px-10 lg:items-stretch lg:justify-between lg:p-14 lg:pb-14">
        <div aria-hidden="true" className="pointer-events-none absolute inset-0">
          <div className="absolute -right-24 -top-24 size-[28rem] animate-float rounded-full bg-brand-400/15 blur-3xl" />
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

        <div className="relative flex items-center gap-3 self-start">
          <BrandMark size={46} />
          <span className="leading-tight">
            <span className="block text-2xl font-bold tracking-tight">Sentra</span>
            <span className="block text-xs font-medium uppercase tracking-[0.16em] text-brand-200/80">Absensi Sekolah</span>
          </span>
        </div>

        <LogoStage className="relative -my-2 h-64 w-full max-w-md sm:h-80 lg:-my-6 lg:h-[min(36rem,56vh)] lg:max-w-none" />

        <div className="relative max-w-md text-center lg:text-left">
          <h2 className="animate-rise text-2xl font-bold sm:text-3xl lg:text-4xl leading-[1.15] tracking-tight">
            Kehadiran siswa, tercatat rapi dan <span className="text-gold-400">dapat dipercaya.</span>
          </h2>
          <p className="mt-3 animate-rise text-sm leading-relaxed sm:text-base lg:mt-4 text-brand-50/75 [animation-delay:100ms]">
            Dari gerbang sekolah sampai laporan bulanan. Satu sistem untuk petugas piket, wali kelas, dan pimpinan sekolah.
          </p>
          <ul className="mt-8 hidden space-y-3 lg:block">
            {HIGHLIGHTS.map(({ icon: Icon, text }, i) => (
              <li key={text} className="flex animate-rise items-center gap-3 text-sm text-brand-50/90" style={{ animationDelay: `${200 + i * 90}ms` }}>
                <span className="flex size-9 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/15">
                  <Icon width={18} height={18} />
                </span>
                {text}
              </li>
            ))}
          </ul>
        </div>

        <p className="relative hidden items-center gap-2 text-xs text-brand-100/60 lg:flex">
          <IconCheck width={16} height={16} /> Sentra Absensi Sekolah
        </p>
      </section>

      <section className="relative z-10 -mt-16 flex items-start justify-center px-4 pb-10 sm:px-8 lg:mt-0 lg:items-center lg:py-12">
        <div className="w-full max-w-md animate-rise rounded-3xl border border-zinc-200/70 bg-white p-6 shadow-lift sm:p-10 dark:border-zinc-800 dark:bg-zinc-900">
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
      className={`${BUTTON_BASE} w-full bg-gradient-to-b from-brand-600 to-brand-800 px-4 py-3 text-white shadow-md shadow-brand-900/20 hover:-translate-y-0.5 hover:shadow-lg hover:shadow-brand-900/25`}
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
  // `id` dapat diberikan bila dua kolom dengan `name` sama ada di satu halaman; label selalu menunjuk kolomnya sendiri.
  const id = area.id ?? name;

  return (
    <div>
      <label htmlFor={id} className="block text-sm font-semibold text-zinc-800 dark:text-zinc-200">
        {label}
      </label>
      <textarea name={name} rows={3} aria-invalid={error ? true : undefined} className={FIELD} {...area} id={id} />
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

type ButtonSize = "sm" | "md";

const SIZE: Record<ButtonSize, string> = {
  md: "px-4 py-2.5 text-sm",
  sm: "px-3 py-1.5 text-[13px]",
};

const PRIMARY =
  "bg-gradient-to-b from-brand-600 to-brand-800 text-white shadow-sm shadow-brand-900/20 hover:-translate-y-0.5 hover:shadow-md";
const SECONDARY =
  "border border-zinc-200 bg-white text-zinc-700 shadow-sm hover:border-brand-300 hover:bg-brand-50 hover:text-brand-800 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:border-brand-500 dark:hover:bg-zinc-800";
const SOFT =
  "border border-brand-200/70 bg-brand-50 text-brand-800 hover:border-brand-300 hover:bg-brand-100 dark:border-brand-800/60 dark:bg-brand-950/50 dark:text-brand-200 dark:hover:bg-brand-900/60";

export function PrimaryButton({
  pending,
  icon,
  size = "md",
  children,
  ...button
}: { pending?: boolean; icon?: ReactNode; size?: ButtonSize; children: ReactNode } & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button {...button} disabled={pending || button.disabled} className={`${BUTTON_BASE} ${SIZE[size]} ${PRIMARY}`}>
      {pending ? (
        "Memproses…"
      ) : (
        <>
          {icon}
          {children}
        </>
      )}
    </button>
  );
}

export function SecondaryButton({
  icon,
  size = "md",
  children,
  ...button
}: { icon?: ReactNode; size?: ButtonSize; children: ReactNode } & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button {...button} className={`${BUTTON_BASE} ${SIZE[size]} ${SECONDARY}`}>
      {icon}
      {children}
    </button>
  );
}

/** Tautan yang tampil sebagai tombol (navigasi), dalam tiga gaya: utama, sekunder, dan lembut. */
export function LinkButton({
  href,
  variant = "secondary",
  size = "md",
  icon,
  download,
  children,
}: {
  href: string;
  variant?: "primary" | "secondary" | "soft";
  size?: ButtonSize;
  icon?: ReactNode;
  /** Tautan unduhan (berkas dari API): memakai <a> biasa agar tidak diproses router. */
  download?: boolean;
  children: ReactNode;
}) {
  const tone = variant === "primary" ? PRIMARY : variant === "soft" ? SOFT : SECONDARY;
  const className = `${BUTTON_BASE} ${SIZE[size]} ${tone}`;
  const content = (
    <>
      {icon}
      {children}
    </>
  );
  return download ? (
    <a href={href} className={className}>
      {content}
    </a>
  ) : (
    <Link href={href} className={className}>
      {content}
    </Link>
  );
}

/** Tombol kembali ke halaman induk: panah dan label, gaya sekunder kecil. */
export function BackLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <LinkButton href={href} size="sm" icon={<IconArrowLeft width={16} height={16} />}>
      {children}
    </LinkButton>
  );
}

const ROW_TONE = {
  default:
    "border-brand-200/70 bg-brand-50 text-brand-800 hover:border-brand-300 hover:bg-brand-100 dark:border-brand-800/60 dark:bg-brand-950/50 dark:text-brand-200 dark:hover:bg-brand-900/60",
  danger:
    "border-red-200 bg-red-50 text-red-700 hover:border-red-300 hover:bg-red-100 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300 dark:hover:bg-red-950/70",
} as const;

const ROW_BASE =
  "inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-semibold transition duration-150 active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100";

/** Tombol aksi kecil di dalam baris tabel atau kartu (Ubah, Hapus, Nonaktifkan, dan sejenisnya). */
export function RowButton({
  tone = "default",
  icon,
  children,
  ...button
}: { tone?: keyof typeof ROW_TONE; icon?: ReactNode; children: ReactNode } & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button type="button" {...button} className={`${ROW_BASE} ${ROW_TONE[tone]}`}>
      {icon}
      {children}
    </button>
  );
}

/** Tautan aksi kecil di baris tabel, tampilannya sama dengan RowButton. */
export function RowLink({ href, icon, children }: { href: string; icon?: ReactNode; children: ReactNode }) {
  return (
    <Link href={href} className={`${ROW_BASE} ${ROW_TONE.default}`}>
      {icon}
      {children}
    </Link>
  );
}

/** Wadah barisan tombol aksi pada sel tabel. */
export function RowActions({ children }: { children: ReactNode }) {
  return <div className="flex flex-wrap items-center gap-1.5">{children}</div>;
}

/** Keadaan kosong: ikon, judul, keterangan, dan satu aksi opsional. */
export function EmptyState({ icon, title, description, action }: { icon?: ReactNode; title: string; description?: ReactNode; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-zinc-300 bg-white/60 px-6 py-10 text-center dark:border-zinc-700 dark:bg-zinc-900/40">
      <span className="grid size-12 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-300">
        {icon ?? <IconInbox width={24} height={24} />}
      </span>
      <div>
        <p className="font-semibold text-zinc-900 dark:text-zinc-100">{title}</p>
        {description ? <p className="mx-auto mt-1 max-w-md text-sm text-zinc-600 dark:text-zinc-400">{description}</p> : null}
      </div>
      {action}
    </div>
  );
}

export function TextLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link href={href} className="font-semibold text-brand-700 underline-offset-4 hover:underline dark:text-brand-400">
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

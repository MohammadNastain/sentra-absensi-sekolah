import type { ReactNode } from "react";
import Link from "next/link";

/** Kerangka halaman autentikasi: kartu terpusat dengan judul dan deskripsi. */
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
    <main className="flex flex-1 items-center justify-center bg-zinc-50 px-4 py-12 dark:bg-zinc-950">
      <div className="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <p className="text-sm font-semibold tracking-wide text-emerald-700 dark:text-emerald-400">
          Sentra Absensi Sekolah
        </p>
        <h1 className="mt-2 text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{title}</h1>
        {description ? <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{description}</p> : null}
        <div className="mt-6">{children}</div>
        {footer ? <div className="mt-6 text-sm text-zinc-600 dark:text-zinc-400">{footer}</div> : null}
      </div>
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
      <label htmlFor={name} className="block text-sm font-medium text-zinc-800 dark:text-zinc-200">
        {label}
      </label>
      <input
        id={name}
        name={name}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        className="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-zinc-900 shadow-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/30 aria-[invalid=true]:border-red-600 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50"
        {...input}
      />
      {error ? (
        <p id={`${name}-error`} className="mt-1 text-sm text-red-700 dark:text-red-400">
          {error}
        </p>
      ) : hint ? (
        <p id={`${name}-hint`} className="mt-1 text-sm text-zinc-500">
          {hint}
        </p>
      ) : null}
    </div>
  );
}

export function SubmitButton({ pending, children }: { pending: boolean; children: ReactNode }) {
  return (
    <button
      type="submit"
      disabled={pending}
      className="w-full rounded-lg bg-emerald-700 px-4 py-2.5 font-medium text-white transition-colors hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
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
    <div role={tone === "error" ? "alert" : "status"} className={`rounded-lg border px-3 py-2 text-sm ${styles}`}>
      {children}
    </div>
  );
}

export function TextLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link href={href} className="font-medium text-emerald-700 hover:underline dark:text-emerald-400">
      {children}
    </Link>
  );
}

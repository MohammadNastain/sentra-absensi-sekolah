"use client";

import type { Schemas } from "@sentra/api-client";
import { ROLE_LABELS } from "@/lib/school-options";

type Role = Schemas["Role"];

// Akun siswa dibuat dari data siswa, bukan dipilih sebagai peran anggota (ADR 0010).
const ROLES = (Object.keys(ROLE_LABELS) as Role[]).filter((role) => role !== "student" && role !== "guardian");

/** Pemilih satu atau lebih peran sekolah. */
export function RoleCheckboxes({
  value,
  onChange,
  error,
}: {
  value: Role[];
  onChange: (roles: Role[]) => void;
  error?: string;
}) {
  return (
    <fieldset>
      <legend className="text-sm font-medium text-zinc-800 dark:text-zinc-200">Peran</legend>
      <div className="mt-2 grid gap-2 sm:grid-cols-2">
        {ROLES.map((role) => (
          <label key={role} className="flex items-center gap-2 text-sm text-zinc-800 dark:text-zinc-200">
            <input
              type="checkbox"
              checked={value.includes(role)}
              onChange={(event) => onChange(event.target.checked ? [...value, role] : value.filter((r) => r !== role))}
            />
            {ROLE_LABELS[role]}
          </label>
        ))}
      </div>
      {error ? <p className="mt-1 text-sm text-red-700 dark:text-red-400">{error}</p> : null}
    </fieldset>
  );
}

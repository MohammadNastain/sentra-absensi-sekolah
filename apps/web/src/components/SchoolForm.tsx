"use client";

import type { Schemas } from "@sentra/api-client";
import { useState, type ReactNode } from "react";
import { FormAlert, PrimaryButton, SelectField, TextAreaField, TextField } from "@/components/ui";
import type { FormFailure } from "@/lib/errors";
import { EDUCATION_LEVELS, TIMEZONES } from "@/lib/school-options";

export interface SchoolFormValues {
  code?: string;
  name: string;
  npsn: string | null;
  education_levels: Schemas["EducationLevel"][];
  timezone: Schemas["Timezone"];
  address: string | null;
}

export interface SchoolFormPayload {
  code: string;
  name: string;
  npsn: string | null;
  education_levels: Schemas["EducationLevel"][];
  timezone: Schemas["Timezone"];
  address: string | null;
}

/**
 * Formulir profil sekolah. Mode `create` menampilkan kode (tidak bisa diubah sesudahnya);
 * mode `edit` menyembunyikannya karena API menolak `code`.
 */
export function SchoolForm({
  mode,
  initial,
  failure,
  pending,
  submitLabel,
  extra,
  onSubmit,
}: {
  mode: "create" | "edit";
  initial?: SchoolFormValues;
  failure: FormFailure | null;
  pending: boolean;
  submitLabel: string;
  /** Kolom tambahan sebelum tombol kirim; nilainya dibaca pemanggil dari FormData (argumen kedua onSubmit). */
  extra?: ReactNode;
  onSubmit: (payload: SchoolFormPayload, form: FormData) => void;
}) {
  const [levels, setLevels] = useState<string[]>(initial?.education_levels ?? []);

  return (
    <form
      noValidate
      className="space-y-4"
      onSubmit={(event) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const text = (key: string) => String(form.get(key) ?? "").trim();
        onSubmit(
          {
          code: text("code"),
          name: text("name"),
          npsn: text("npsn") || null,
          education_levels: levels as Schemas["EducationLevel"][],
          timezone: text("timezone") as Schemas["Timezone"],
          address: text("address") || null,
          },
          form,
        );
      }}
    >
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}

      {mode === "create" ? (
        <TextField
          label="Kode sekolah"
          name="code"
          defaultValue={initial?.code}
          error={failure?.fields.code}
          hint="Huruf kecil, angka, dan tanda hubung. Dipakai di alamat URL dan tidak bisa diubah."
          required
        />
      ) : null}
      <TextField label="Nama sekolah" name="name" defaultValue={initial?.name} error={failure?.fields.name} required />
      <TextField
        label="NPSN"
        name="npsn"
        defaultValue={initial?.npsn ?? ""}
        inputMode="numeric"
        error={failure?.fields.npsn}
        hint="8 digit, boleh dikosongkan."
      />

      <fieldset>
        <legend className="text-sm font-medium text-zinc-800 dark:text-zinc-200">Jenjang</legend>
        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-2">
          {EDUCATION_LEVELS.map((level) => (
            <label key={level} className="flex items-center gap-2 text-sm text-zinc-800 dark:text-zinc-200">
              <input
                type="checkbox"
                checked={levels.includes(level)}
                onChange={(event) =>
                  setLevels((current) =>
                    event.target.checked ? [...current, level] : current.filter((l) => l !== level),
                  )
                }
              />
              {level}
            </label>
          ))}
        </div>
        {failure?.fields.education_levels ? (
          <p className="mt-1 text-sm text-red-700 dark:text-red-400">{failure.fields.education_levels}</p>
        ) : null}
      </fieldset>

      <SelectField
        label="Zona waktu"
        name="timezone"
        defaultValue={initial?.timezone ?? "Asia/Jakarta"}
        error={failure?.fields.timezone}
      >
        {TIMEZONES.map((zone) => (
          <option key={zone.value} value={zone.value}>
            {zone.label}
          </option>
        ))}
      </SelectField>
      <TextAreaField label="Alamat" name="address" defaultValue={initial?.address ?? ""} error={failure?.fields.address} />

      {extra}

      <PrimaryButton type="submit" pending={pending}>
        {submitLabel}
      </PrimaryButton>
    </form>
  );
}

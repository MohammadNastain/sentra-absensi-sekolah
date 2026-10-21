"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { IconEdit } from "@/components/icons";
import { FormAlert, PrimaryButton, RowButton, SecondaryButton, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { fetchAll } from "@/lib/fetch-all";
import { formatDate } from "@/lib/format";
import { useSchool } from "../school-context";

type AcademicYear = Schemas["AcademicYear"];

type PendingAction = { kind: "create" } | { kind: "edit"; year: AcademicYear } | { kind: "activate"; year: AcademicYear };

const TITLES: Record<PendingAction["kind"], string> = {
  create: "Tahun ajaran baru",
  edit: "Ubah tahun ajaran",
  activate: "Aktifkan tahun ajaran",
};

export default function AcademicYearsPage() {
  const { code, can } = useSchool();
  const canManage = can("school.academic.manage");
  const [years, setYears] = useState<AcademicYear[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [action, setAction] = useState<PendingAction | null>(null);

  const load = useCallback(async () => {
    try {
      const items = await fetchAll(async (page) => {
        const { data, error: problem } = await api.GET("/schools/{school}/academic-years", {
          params: { path: { school: code }, query: { page } },
        });
        if (problem !== undefined) throw problem;
        return data;
      });
      setError(null);
      setYears(items);
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat data daftar
    void load();
  }, [load]);

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Tahun ajaran</h1>
        {canManage ? <PrimaryButton onClick={() => setAction({ kind: "create" })}>Tahun ajaran baru</PrimaryButton> : null}
      </div>

      {error ? <FormAlert>{error}</FormAlert> : null}
      {years === null && !error ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}
      {years && years.length === 0 ? (
        <p className="text-zinc-600 dark:text-zinc-400">Belum ada tahun ajaran. Buat satu untuk mulai menyiapkan kelas.</p>
      ) : null}

      {years && years.length > 0 ? (
        <div className="overflow-x-auto rounded-2xl border border-zinc-200/70 bg-white shadow-card dark:border-zinc-800 dark:bg-zinc-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-zinc-200 bg-zinc-50/70 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400">
              <tr>
                <th scope="col" className="px-4 py-3 font-semibold">Tahun ajaran</th>
                <th scope="col" className="px-4 py-3 font-semibold">Mulai</th>
                <th scope="col" className="px-4 py-3 font-semibold">Selesai</th>
                <th scope="col" className="px-4 py-3 font-semibold">Status</th>
                {canManage ? <th scope="col" className="px-4 py-3 font-semibold">Aksi</th> : null}
              </tr>
            </thead>
            <tbody>
              {years.map((year) => (
                <tr key={year.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3 font-semibold text-zinc-900 dark:text-zinc-100">{year.name}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{formatDate(year.start_date)}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{formatDate(year.end_date)}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{year.is_active ? "Aktif" : "Tidak aktif"}</td>
                  {canManage ? (
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap items-center gap-1.5">
                        <RowButton icon={<IconEdit width={14} height={14} />}
                          onClick={() => setAction({ kind: "edit", year })}>
                          Ubah
                        </RowButton>
                        {!year.is_active ? (
                          <RowButton
                            onClick={() => setAction({ kind: "activate", year })}>
                            Aktifkan
                          </RowButton>
                        ) : null}
                      </div>
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      <Dialog open={action !== null} title={action ? TITLES[action.kind] : ""} onClose={() => setAction(null)}>
        {action ? (
          <YearForm
            key={action.kind === "create" ? "create" : `${action.kind}:${action.year.id}`}
            action={action}
            code={code}
            onCancel={() => setAction(null)}
            onDone={() => {
              setAction(null);
              void load();
            }}
          />
        ) : null}
      </Dialog>
    </>
  );
}

function YearForm({
  action,
  code,
  onCancel,
  onDone,
}: {
  action: PendingAction;
  code: string;
  onCancel: () => void;
  onDone: () => void;
}) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const body = {
      name: String(form.get("name") ?? "").trim(),
      start_date: String(form.get("start_date") ?? ""),
      end_date: String(form.get("end_date") ?? ""),
    };
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      switch (action.kind) {
        case "create":
          return api.POST("/schools/{school}/academic-years", { params: { path: { school: code } }, body });
        case "edit":
          return api.PATCH("/schools/{school}/academic-years/{academicYear}", {
            params: { path: { school: code, academicYear: action.year.id } },
            body,
          });
        case "activate":
          return api.POST("/schools/{school}/academic-years/{academicYear}/activate", {
            params: { path: { school: code, academicYear: action.year.id } },
          });
      }
    });
    setPending(false);

    if (!outcome.ok) setFailure(outcome.failure);
    else onDone();
  }

  if (action.kind === "activate") {
    return (
      <form onSubmit={submit} noValidate className="space-y-4">
        {failure ? <FormAlert>{failure.message}</FormAlert> : null}
        <p className="text-sm text-zinc-700 dark:text-zinc-300">
          Jadikan <strong>{action.year.name}</strong> sebagai tahun ajaran aktif? Tahun ajaran yang aktif sekarang akan
          dinonaktifkan. Riwayat kelas dan absensinya tidak berubah.
        </p>
        <div className="flex justify-end gap-2">
          <SecondaryButton type="button" onClick={onCancel}>
            Batal
          </SecondaryButton>
          <PrimaryButton type="submit" pending={pending}>
            Aktifkan
          </PrimaryButton>
        </div>
      </form>
    );
  }

  const year = action.kind === "edit" ? action.year : null;
  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <TextField label="Nama" name="name" defaultValue={year?.name} placeholder="2026/2027" error={failure?.fields.name} required />
      <TextField label="Tanggal mulai" name="start_date" type="date" defaultValue={year?.start_date} error={failure?.fields.start_date} required />
      <TextField label="Tanggal selesai" name="end_date" type="date" defaultValue={year?.end_date} error={failure?.fields.end_date} required />
      <div className="flex justify-end gap-2">
        <SecondaryButton type="button" onClick={onCancel}>
          Batal
        </SecondaryButton>
        <PrimaryButton type="submit" pending={pending}>
          Simpan
        </PrimaryButton>
      </div>
    </form>
  );
}

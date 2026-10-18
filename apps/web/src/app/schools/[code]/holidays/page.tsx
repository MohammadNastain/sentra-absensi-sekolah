"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { Pager } from "@/components/Pager";
import { FormAlert, PrimaryButton, SecondaryButton, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { formatDate } from "@/lib/format";
import { useSchool } from "../school-context";

type Holiday = Schemas["Holiday"];

type PendingAction = { kind: "create" } | { kind: "edit"; holiday: Holiday } | { kind: "delete"; holiday: Holiday };

function rangeLabel(h: Holiday): string {
  return h.start_date === h.end_date ? formatDate(h.start_date) : `${formatDate(h.start_date)} – ${formatDate(h.end_date)}`;
}

export default function HolidaysPage() {
  const { code, can } = useSchool();
  const canManage = can("school.schedule.manage");
  const [holidays, setHolidays] = useState<Holiday[] | null>(null);
  const [meta, setMeta] = useState<Schemas["PageMeta"] | null>(null);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [action, setAction] = useState<PendingAction | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/holidays", {
        params: { path: { school: code }, query: { page } },
      });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setHolidays(data.data);
        setMeta(data.meta);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, page]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat data daftar
    void load();
  }, [load]);

  const close = () => setAction(null);
  const done = () => {
    setAction(null);
    void load();
  };

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">Hari libur</h1>
        {canManage ? <PrimaryButton onClick={() => setAction({ kind: "create" })}>Tambah hari libur</PrimaryButton> : null}
      </div>

      {error ? <FormAlert>{error}</FormAlert> : null}
      {holidays === null && !error ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}
      {holidays && holidays.length === 0 ? <p className="text-zinc-600 dark:text-zinc-400">Belum ada hari libur.</p> : null}

      {holidays && holidays.length > 0 ? (
        <div className="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-zinc-200 text-zinc-600 dark:border-zinc-800 dark:text-zinc-400">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">Nama</th>
                <th scope="col" className="px-4 py-3 font-medium">Tanggal</th>
                {canManage ? <th scope="col" className="px-4 py-3 font-medium">Aksi</th> : null}
              </tr>
            </thead>
            <tbody>
              {holidays.map((holiday) => (
                <tr key={holiday.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{holiday.name}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{rangeLabel(holiday)}</td>
                  {canManage ? (
                    <td className="px-4 py-3">
                      <div className="flex gap-3">
                        <button
                          type="button"
                          className="font-medium text-emerald-700 hover:underline dark:text-emerald-400"
                          onClick={() => setAction({ kind: "edit", holiday })}
                        >
                          Ubah
                        </button>
                        <button
                          type="button"
                          className="font-medium text-red-700 hover:underline dark:text-red-400"
                          onClick={() => setAction({ kind: "delete", holiday })}
                        >
                          Hapus
                        </button>
                      </div>
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {meta ? <Pager meta={meta} onPage={setPage} /> : null}

      <Dialog
        open={action !== null && action.kind !== "delete"}
        title={action?.kind === "edit" ? "Ubah hari libur" : "Tambah hari libur"}
        onClose={close}
      >
        {action && action.kind !== "delete" ? (
          <HolidayForm
            key={action.kind === "create" ? "create" : action.holiday.id}
            holiday={action.kind === "edit" ? action.holiday : null}
            code={code}
            onCancel={close}
            onDone={done}
          />
        ) : null}
      </Dialog>

      <Dialog open={action?.kind === "delete"} title="Hapus hari libur" onClose={close}>
        {action?.kind === "delete" ? <DeleteHoliday holiday={action.holiday} code={code} onCancel={close} onDone={done} /> : null}
      </Dialog>
    </>
  );
}

function HolidayForm({
  holiday,
  code,
  onCancel,
  onDone,
}: {
  holiday: Holiday | null;
  code: string;
  onCancel: () => void;
  onDone: () => void;
}) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const text = (name: string) => String(form.get(name) ?? "").trim();
    const body = { name: text("name"), start_date: text("start_date"), end_date: text("end_date") };
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return holiday
        ? api.PATCH("/schools/{school}/holidays/{holiday}", { params: { path: { school: code, holiday: holiday.id } }, body })
        : api.POST("/schools/{school}/holidays", { params: { path: { school: code } }, body });
    });
    setPending(false);

    if (!outcome.ok) setFailure(outcome.failure);
    else onDone();
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <TextField label="Nama" name="name" defaultValue={holiday?.name} error={failure?.fields.name} required />
      <TextField label="Tanggal mulai" name="start_date" type="date" defaultValue={holiday?.start_date} error={failure?.fields.start_date} required />
      <TextField label="Tanggal selesai" name="end_date" type="date" defaultValue={holiday?.end_date} error={failure?.fields.end_date} required />
      <p className="text-sm text-zinc-600 dark:text-zinc-400">Untuk satu hari, isi tanggal selesai sama dengan tanggal mulai. Pada hari libur tidak ada sesi absensi.</p>
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

function DeleteHoliday({ holiday, code, onCancel, onDone }: { holiday: Holiday; code: string; onCancel: () => void; onDone: () => void }) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function confirm() {
    setPending(true);
    setFailure(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.DELETE("/schools/{school}/holidays/{holiday}", { params: { path: { school: code, holiday: holiday.id } } });
    });
    setPending(false);

    if (!outcome.ok) setFailure(outcome.failure);
    else onDone();
  }

  return (
    <div className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <p className="text-zinc-700 dark:text-zinc-300">
        Hapus <strong>{holiday.name}</strong> ({rangeLabel(holiday)})? Tanggal tersebut akan kembali mengikuti jadwal biasa.
      </p>
      <div className="flex justify-end gap-2">
        <SecondaryButton type="button" onClick={onCancel}>
          Batal
        </SecondaryButton>
        <PrimaryButton type="button" pending={pending} onClick={confirm}>
          Hapus
        </PrimaryButton>
      </div>
    </div>
  );
}

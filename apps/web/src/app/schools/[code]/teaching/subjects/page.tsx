"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { IconBook, IconEdit, IconPlus, IconTrash } from "@/components/icons";
import { BackLink, EmptyState, FormAlert, PageHeader, PrimaryButton, RowActions, RowButton, SecondaryButton, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { useSchool } from "../../school-context";

type Subject = Schemas["Subject"];

type PendingAction = { kind: "create" } | { kind: "rename"; subject: Subject };

/** Daftar mata pelajaran sekolah. Mapel yang masih dipakai jadwal mengajar tidak bisa dihapus. */
export default function SubjectsPage() {
  const { code, can } = useSchool();
  const canManage = can("school.academic.manage");
  const [subjects, setSubjects] = useState<Subject[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [rowError, setRowError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [action, setAction] = useState<PendingAction | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/subjects", { params: { path: { school: code } } });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setSubjects(data.data);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat daftar mapel
    void load();
  }, [load]);

  async function remove(subject: Subject) {
    setBusyId(subject.id);
    setRowError(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.DELETE("/schools/{school}/subjects/{subject}", { params: { path: { school: code, subject: subject.id } } });
    });
    setBusyId(null);

    if (!outcome.ok) setRowError(outcome.failure.message);
    else void load();
  }

  return (
    <>
      <PageHeader
        title="Mata pelajaran"
        description="Daftar mapel yang dipakai di jadwal mengajar dan data guru."
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <BackLink href={`/schools/${code}/teaching`}>Kembali ke jadwal mengajar</BackLink>
            {canManage ? (
              <PrimaryButton icon={<IconPlus width={18} height={18} />} onClick={() => setAction({ kind: "create" })}>
                Tambah mapel
              </PrimaryButton>
            ) : null}
          </div>
        }
      />

      {error ? <FormAlert>{error}</FormAlert> : null}
      {rowError ? <FormAlert>{rowError}</FormAlert> : null}
      {subjects === null && !error ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}
      {subjects && subjects.length === 0 ? (
        <EmptyState
          icon={<IconBook width={24} height={24} />}
          title="Belum ada mata pelajaran"
          description="Tambahkan di sini, atau biarkan terbuat otomatis saat impor guru atau jadwal."
        />
      ) : null}

      {subjects && subjects.length > 0 ? (
        <div className="overflow-x-auto rounded-2xl border border-zinc-200/70 bg-white shadow-card dark:border-zinc-800 dark:bg-zinc-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-zinc-200 bg-zinc-50/70 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400">
              <tr>
                <th scope="col" className="px-4 py-3 font-semibold">Mata pelajaran</th>
                <th scope="col" className="px-4 py-3 font-semibold">Dipakai di jadwal</th>
                {canManage ? <th scope="col" className="px-4 py-3 font-semibold">Aksi</th> : null}
              </tr>
            </thead>
            <tbody>
              {subjects.map((subject) => (
                <tr key={subject.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3 font-semibold text-zinc-900 dark:text-zinc-100">{subject.name}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{subject.slot_count} jam</td>
                  {canManage ? (
                    <td className="px-4 py-3">
                      <RowActions>
                        <RowButton icon={<IconEdit width={14} height={14} />} onClick={() => setAction({ kind: "rename", subject })}>
                          Ganti nama
                        </RowButton>
                        <RowButton
                          tone="danger"
                          icon={<IconTrash width={14} height={14} />}
                          disabled={busyId === subject.id || subject.slot_count > 0}
                          title={subject.slot_count > 0 ? "Masih dipakai di jadwal mengajar" : undefined}
                          onClick={() => void remove(subject)}
                        >
                          Hapus
                        </RowButton>
                      </RowActions>
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      <Dialog open={action !== null} title={action?.kind === "rename" ? "Ganti nama mapel" : "Tambah mapel"} onClose={() => setAction(null)}>
        {action ? (
          <SubjectForm
            key={action.kind === "create" ? "create" : action.subject.id}
            subject={action.kind === "rename" ? action.subject : null}
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

function SubjectForm({ subject, code, onCancel, onDone }: { subject: Subject | null; code: string; onCancel: () => void; onDone: () => void }) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const body = { name: String(new FormData(event.currentTarget).get("name") ?? "").trim() };
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return subject
        ? api.PATCH("/schools/{school}/subjects/{subject}", { params: { path: { school: code, subject: subject.id } }, body })
        : api.POST("/schools/{school}/subjects", { params: { path: { school: code } }, body });
    });
    setPending(false);

    if (!outcome.ok) setFailure(outcome.failure);
    else onDone();
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <TextField label="Nama mata pelajaran" name="name" defaultValue={subject?.name} error={failure?.fields.name} required />
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

"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { Pager } from "@/components/Pager";
import { IconPlus } from "@/components/icons";
import { EmptyState, FormAlert, PageHeader, PrimaryButton, RowActions, RowButton, RowLink, SecondaryButton, SelectField, TextAreaField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { formatDateTime } from "@/lib/format";
import { fromLocalInput, subjectsFor, toLocalInput, useLmsOptions, type LmsOptions } from "@/lib/lms";
import { useSchool } from "../../school-context";
import { AttachmentsPanel } from "../attachments-panel";
import { StudentAssignments } from "../student-views";

type Assignment = Schemas["LmsAssignment"];
type AssignmentDetail = Schemas["LmsAssignmentDetail"];
type Status = Schemas["LmsAssignmentStatus"];

const STATUS_LABEL: Record<Status, string> = { draft: "Draf", published: "Terbit", closed: "Ditutup" };

/** Pengelola melihat layar kelola; siswa dan peran lihat-saja melihat tugas beserta status pengumpulannya. */
export default function AssignmentsPage() {
  const { can } = useSchool();
  return can("school.lms.manage") ? <ManageAssignments /> : <StudentAssignments />;
}

function ManageAssignments() {
  const { code, profile, can } = useSchool();
  const { options, error: optionsError } = useLmsOptions(code);
  const [items, setItems] = useState<Assignment[] | null>(null);
  const [meta, setMeta] = useState<Schemas["PageMeta"] | null>(null);
  const [classId, setClassId] = useState("");
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<{ id: string | null } | null>(null);
  const [deleting, setDeleting] = useState<Assignment | null>(null);
  const [busy, setBusy] = useState(false);
  const canManage = can("school.lms.manage");

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/lms/assignments", {
        params: { path: { school: code }, query: { page, ...(classId ? { class_id: classId } : {}) } },
      });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setItems(data.data);
        setMeta(data.meta);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, page, classId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat data daftar
    void load();
  }, [load]);

  async function setStatus(assignment: Assignment, status: Status) {
    setBusy(true);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.PATCH("/schools/{school}/lms/assignments/{assignment}", { params: { path: { school: code, assignment: assignment.id } }, body: { status } });
    });
    setBusy(false);
    if (!outcome.ok) setError(outcome.failure.message);
    else {
      setError(null);
      void load();
    }
  }

  async function remove(assignment: Assignment) {
    setBusy(true);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.DELETE("/schools/{school}/lms/assignments/{assignment}", { params: { path: { school: code, assignment: assignment.id } } });
    });
    setBusy(false);
    setDeleting(null);
    if (!outcome.ok) setError(outcome.failure.message);
    else {
      setError(null);
      void load();
    }
  }

  return (
    <>
      <PageHeader
        title="Tugas"
        description="Tugas per kelas dan mata pelajaran. Siswa mengumpulkan jawaban lewat akunnya; Anda menilai atau meminta revisi dari halaman penilaian."
        actions={
          canManage ? (
            <PrimaryButton icon={<IconPlus width={18} height={18} />} onClick={() => setEditing({ id: null })} disabled={!options}>
              Tambah tugas
            </PrimaryButton>
          ) : undefined
        }
      />

      {options && options.classes.length > 1 ? (
        <div className="w-56">
          <SelectField label="Kelas" name="class_filter" value={classId} onChange={(event) => { setClassId(event.target.value); setPage(1); }}>
            <option value="">Semua</option>
            {options.classes.map((c) => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </SelectField>
        </div>
      ) : null}

      {error ? <FormAlert>{error}</FormAlert> : null}
      {optionsError ? <FormAlert>{optionsError}</FormAlert> : null}
      {items === null && !error ? <p role="status" className="text-zinc-600 dark:text-zinc-400">Memuat…</p> : null}
      {items && items.length === 0 ? (
        <EmptyState
          title="Belum ada tugas"
          description={options && options.pairs !== null && options.pairs.length === 0 ? "Anda belum memiliki jadwal mengajar, sehingga belum dapat membuat tugas. Hubungi admin sekolah." : "Buat tugas pertama untuk kelas Anda."}
        />
      ) : null}

      {items && items.length > 0 ? (
        <div className="overflow-x-auto rounded-2xl border border-zinc-200/70 bg-white shadow-card dark:border-zinc-800 dark:bg-zinc-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-zinc-200 bg-zinc-50/70 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400">
              <tr>
                <th scope="col" className="px-4 py-3 font-semibold">Judul</th>
                <th scope="col" className="px-4 py-3 font-semibold">Kelas</th>
                <th scope="col" className="px-4 py-3 font-semibold">Mata pelajaran</th>
                <th scope="col" className="px-4 py-3 font-semibold">Tenggat</th>
                <th scope="col" className="px-4 py-3 font-semibold">Status</th>
                <th scope="col" className="px-4 py-3 font-semibold">Aksi</th>
              </tr>
            </thead>
            <tbody>
              {items.map((a) => (
                <tr key={a.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3 font-semibold text-zinc-900 dark:text-zinc-100">{a.title}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{a.class.name}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{a.subject.name}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {a.due_at ? formatDateTime(a.due_at, profile.timezone) : "Tanpa tenggat"}
                    {a.accept_late ? <span className="ml-1 text-xs text-zinc-500">(terlambat diterima)</span> : null}
                  </td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{STATUS_LABEL[a.status]}</td>
                  <td className="px-4 py-3">
                    <RowActions>
                      {a.can_manage ? (
                        <>
                          <RowButton onClick={() => setEditing({ id: a.id })}>Ubah</RowButton>
                          {a.status === "draft" ? <RowButton disabled={busy} onClick={() => void setStatus(a, "published")}>Terbitkan</RowButton> : null}
                          {a.status === "published" ? <RowButton disabled={busy} onClick={() => void setStatus(a, "closed")}>Tutup</RowButton> : null}
                          {a.status === "closed" ? <RowButton disabled={busy} onClick={() => void setStatus(a, "published")}>Buka lagi</RowButton> : null}
                          {a.status !== "draft" && can("school.lms.grade") ? <RowLink href={`/schools/${code}/lms/assignments/${a.id}`}>Penilaian</RowLink> : null}
                          <RowButton tone="danger" disabled={busy} onClick={() => setDeleting(a)}>Hapus</RowButton>
                        </>
                      ) : (
                        <RowButton onClick={() => setEditing({ id: a.id })}>Lihat</RowButton>
                      )}
                    </RowActions>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {meta ? <Pager meta={meta} onPage={setPage} /> : null}

      <Dialog open={editing !== null} title={editing?.id ? "Tugas" : "Tambah tugas"} onClose={() => setEditing(null)}>
        {editing && options ? (
          <AssignmentForm
            key={editing.id ?? "new"}
            code={code}
            timeZone={profile.timezone}
            id={editing.id}
            options={options}
            defaultClassId={classId}
            onCancel={() => setEditing(null)}
            onSaved={() => { setEditing(null); void load(); }}
            onChanged={() => void load()}
          />
        ) : null}
      </Dialog>

      <Dialog open={deleting !== null} title="Hapus tugas?" onClose={() => setDeleting(null)}>
        {deleting ? (
          <div className="space-y-4">
            <p className="text-sm text-zinc-700 dark:text-zinc-300">
              “{deleting.title}” akan dihapus permanen. Tugas yang sudah dikumpulkan siswa tidak dapat dihapus; tutup saja bila tidak ingin menerima jawaban lagi.
            </p>
            <div className="flex justify-end gap-3">
              <SecondaryButton type="button" onClick={() => setDeleting(null)} disabled={busy}>Batal</SecondaryButton>
              <PrimaryButton type="button" pending={busy} onClick={() => void remove(deleting)}>Hapus</PrimaryButton>
            </div>
          </div>
        ) : null}
      </Dialog>
    </>
  );
}

function AssignmentForm({
  code,
  timeZone,
  id,
  options,
  defaultClassId,
  onCancel,
  onSaved,
  onChanged,
}: {
  code: string;
  timeZone: string;
  id: string | null;
  options: LmsOptions;
  defaultClassId: string;
  onCancel: () => void;
  onSaved: () => void;
  onChanged: () => void;
}) {
  const [detail, setDetail] = useState<AssignmentDetail | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [classId, setClassId] = useState(defaultClassId || options.classes[0]?.id || "");
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  const reload = useCallback(async () => {
    if (!id) return;
    try {
      const { data, error } = await api.GET("/schools/{school}/lms/assignments/{assignment}", { params: { path: { school: code, assignment: id } } });
      if (error !== undefined) setLoadError(toFormFailure(error).message);
      else if (data) setDetail(data.data);
    } catch (e) {
      setLoadError(toFormFailure(e).message);
    }
  }, [code, id]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat rincian tugas
    void reload();
  }, [reload]);

  if (id && !detail) return loadError ? <FormAlert>{loadError}</FormAlert> : <p role="status" className="text-zinc-600 dark:text-zinc-400">Memuat…</p>;

  const current = detail;
  const readOnly = current !== null && !current.can_manage;
  const subjects = subjectsFor(options, classId);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const text = (name: string) => String(form.get(name) ?? "");
    const common = {
      title: text("title"),
      instructions: text("instructions"),
      due_at: fromLocalInput(text("due_at"), timeZone),
      max_score: Number(text("max_score")),
      accept_late: form.get("accept_late") === "on",
    };
    setPending(true);
    setFailure(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return id
        ? api.PATCH("/schools/{school}/lms/assignments/{assignment}", { params: { path: { school: code, assignment: id } }, body: common })
        : api.POST("/schools/{school}/lms/assignments", { params: { path: { school: code } }, body: { ...common, class_id: text("class_id"), subject_id: text("subject_id") } });
    });
    setPending(false);
    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    // Tugas baru dibuka lagi agar lampiran (soal) dapat ditambahkan; yang sudah ada langsung ditutup.
    if (!id && outcome.result.data) {
      onChanged();
      setDetail(outcome.result.data.data);
      return;
    }
    onSaved();
  }

  return (
    <div className="space-y-4">
      <form onSubmit={submit} className="space-y-4" noValidate>
        {failure ? <FormAlert>{failure.message}</FormAlert> : null}
        {current ? (
          <p className="text-sm text-zinc-600 dark:text-zinc-400">
            {current.class.name} · {current.subject.name} · {STATUS_LABEL[current.status]}
          </p>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            <SelectField label="Kelas" name="class_id" value={classId} onChange={(event) => setClassId(event.target.value)} error={failure?.fields.class_id}>
              {options.classes.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </SelectField>
            <SelectField label="Mata pelajaran" name="subject_id" key={classId} error={failure?.fields.subject_id}>
              {subjects.map((s) => (
                <option key={s.id} value={s.id}>{s.name}</option>
              ))}
            </SelectField>
          </div>
        )}
        <TextField label="Judul" name="title" defaultValue={current?.title ?? ""} error={failure?.fields.title} required readOnly={readOnly} autoFocus />
        <TextAreaField label="Instruksi" name="instructions" rows={6} defaultValue={current?.instructions ?? ""} error={failure?.fields.instructions} readOnly={readOnly} />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField
            label={`Tenggat (waktu ${timeZone.split("/")[1] ?? timeZone})`}
            name="due_at"
            type="datetime-local"
            defaultValue={toLocalInput(current?.due_at ?? null, timeZone)}
            error={failure?.fields.due_at}
            hint="Kosongkan bila tanpa tenggat."
            readOnly={readOnly}
          />
          <TextField label="Nilai maksimum" name="max_score" type="number" min={0.01} step="any" defaultValue={current?.max_score ?? 100} error={failure?.fields.max_score} readOnly={readOnly} />
        </div>
        <label className="flex items-center gap-2 text-sm text-zinc-800 dark:text-zinc-200">
          <input type="checkbox" name="accept_late" defaultChecked={current?.accept_late ?? false} disabled={readOnly} className="size-4 rounded border-zinc-300" />
          Tetap terima jawaban setelah tenggat (ditandai terlambat)
        </label>
        <div className="flex justify-end gap-3">
          <SecondaryButton type="button" onClick={onCancel}>{readOnly || current ? "Tutup" : "Batal"}</SecondaryButton>
          {!readOnly ? <PrimaryButton type="submit" pending={pending}>{current ? "Simpan" : "Buat tugas"}</PrimaryButton> : null}
        </div>
      </form>
      {current ? (
        <AttachmentsPanel code={code} kind="assignments" ownerId={current.id} attachments={current.attachments} canManage={current.can_manage} onChanged={() => { void reload(); onChanged(); }} />
      ) : (
        <p className="text-sm text-zinc-500">Lampiran (mis. soal) dapat ditambahkan setelah tugas dibuat.</p>
      )}
    </div>
  );
}

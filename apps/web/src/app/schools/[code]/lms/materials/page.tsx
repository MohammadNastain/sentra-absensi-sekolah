"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { Pager } from "@/components/Pager";
import { IconPlus } from "@/components/icons";
import { EmptyState, FormAlert, PageHeader, PrimaryButton, RowActions, RowButton, SecondaryButton, SelectField, TextAreaField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { formatDateTime } from "@/lib/format";
import { subjectsFor, useLmsOptions, type LmsOptions } from "@/lib/lms";
import { useSchool } from "../../school-context";
import { AttachmentsPanel } from "../attachments-panel";
import { StudentMaterials } from "../student-views";

type Material = Schemas["LmsMaterial"];
type MaterialDetail = Schemas["LmsMaterialDetail"];

const STATUS_LABEL: Record<Schemas["LmsMaterialStatus"], string> = { draft: "Draf", published: "Terbit" };

/** Pengelola (guru/admin) melihat layar kelola; siswa dan peran lihat-saja melihat daftar materi terbit. */
export default function MaterialsPage() {
  const { can } = useSchool();
  return can("school.lms.manage") ? <ManageMaterials /> : <StudentMaterials />;
}

function ManageMaterials() {
  const { code, profile, can } = useSchool();
  const { options, error: optionsError } = useLmsOptions(code);
  const [items, setItems] = useState<Material[] | null>(null);
  const [meta, setMeta] = useState<Schemas["PageMeta"] | null>(null);
  const [classId, setClassId] = useState("");
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<{ id: string | null } | null>(null);
  const [deleting, setDeleting] = useState<Material | null>(null);
  const [busy, setBusy] = useState(false);
  const canManage = can("school.lms.manage");

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/lms/materials", {
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

  async function setStatus(material: Material, status: Schemas["LmsMaterialStatus"]) {
    setBusy(true);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.PATCH("/schools/{school}/lms/materials/{material}", { params: { path: { school: code, material: material.id } }, body: { status } });
    });
    setBusy(false);
    if (!outcome.ok) setError(outcome.failure.message);
    else void load();
  }

  async function remove(material: Material) {
    setBusy(true);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.DELETE("/schools/{school}/lms/materials/{material}", { params: { path: { school: code, material: material.id } } });
    });
    setBusy(false);
    setDeleting(null);
    if (!outcome.ok) setError(outcome.failure.message);
    else void load();
  }

  return (
    <>
      <PageHeader
        title="Materi"
        description="Materi pembelajaran per kelas dan mata pelajaran. Draf hanya terlihat oleh Anda; siswa melihatnya setelah diterbitkan."
        actions={
          canManage ? (
            <PrimaryButton icon={<IconPlus width={18} height={18} />} onClick={() => setEditing({ id: null })} disabled={!options}>
              Tambah materi
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
          title="Belum ada materi"
          description={options && options.pairs !== null && options.pairs.length === 0 ? "Anda belum memiliki jadwal mengajar, sehingga belum dapat membuat materi. Hubungi admin sekolah." : "Tambahkan materi pertama untuk kelas Anda."}
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
                <th scope="col" className="px-4 py-3 font-semibold">Status</th>
                <th scope="col" className="px-4 py-3 font-semibold">Dibuat</th>
                <th scope="col" className="px-4 py-3 font-semibold">Aksi</th>
              </tr>
            </thead>
            <tbody>
              {items.map((m) => (
                <tr key={m.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3 font-semibold text-zinc-900 dark:text-zinc-100">
                    {m.title}
                    {m.attachment_count > 0 ? <span className="ml-2 text-xs font-normal text-zinc-500">{m.attachment_count} lampiran</span> : null}
                  </td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{m.class.name}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{m.subject.name}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{STATUS_LABEL[m.status]}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{formatDateTime(m.created_at, profile.timezone)}</td>
                  <td className="px-4 py-3">
                    <RowActions>
                      {m.can_manage ? (
                        <>
                          <RowButton onClick={() => setEditing({ id: m.id })}>Ubah</RowButton>
                          <RowButton disabled={busy} onClick={() => void setStatus(m, m.status === "draft" ? "published" : "draft")}>
                            {m.status === "draft" ? "Terbitkan" : "Tarik"}
                          </RowButton>
                          <RowButton tone="danger" disabled={busy} onClick={() => setDeleting(m)}>Hapus</RowButton>
                        </>
                      ) : (
                        <RowButton onClick={() => setEditing({ id: m.id })}>Lihat</RowButton>
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

      <Dialog open={editing !== null} title={editing?.id ? "Materi" : "Tambah materi"} onClose={() => setEditing(null)}>
        {editing && options ? (
          <MaterialForm
            key={editing.id ?? "new"}
            code={code}
            id={editing.id}
            options={options}
            defaultClassId={classId}
            onCancel={() => setEditing(null)}
            onSaved={() => { setEditing(null); void load(); }}
            onChanged={() => void load()}
          />
        ) : null}
      </Dialog>

      <Dialog open={deleting !== null} title="Hapus materi?" onClose={() => setDeleting(null)}>
        {deleting ? (
          <div className="space-y-4">
            <p className="text-sm text-zinc-700 dark:text-zinc-300">
              “{deleting.title}” beserta seluruh lampirannya akan dihapus permanen dan tidak dapat dikembalikan.
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

function MaterialForm({
  code,
  id,
  options,
  defaultClassId,
  onCancel,
  onSaved,
  onChanged,
}: {
  code: string;
  id: string | null;
  options: LmsOptions;
  defaultClassId: string;
  onCancel: () => void;
  onSaved: () => void;
  onChanged: () => void;
}) {
  const [detail, setDetail] = useState<MaterialDetail | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [classId, setClassId] = useState(defaultClassId || options.classes[0]?.id || "");
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  const reload = useCallback(async () => {
    if (!id) return;
    try {
      const { data, error } = await api.GET("/schools/{school}/lms/materials/{material}", { params: { path: { school: code, material: id } } });
      if (error !== undefined) setLoadError(toFormFailure(error).message);
      else if (data) setDetail(data.data);
    } catch (e) {
      setLoadError(toFormFailure(e).message);
    }
  }, [code, id]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat rincian materi
    void reload();
  }, [reload]);

  if (id && !detail) return loadError ? <FormAlert>{loadError}</FormAlert> : <p role="status" className="text-zinc-600 dark:text-zinc-400">Memuat…</p>;

  const readOnly = detail !== null && !detail.can_manage;
  const subjects = subjectsFor(options, classId);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const text = (name: string) => String(form.get(name) ?? "");
    setPending(true);
    setFailure(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return id
        ? api.PATCH("/schools/{school}/lms/materials/{material}", { params: { path: { school: code, material: id } }, body: { title: text("title"), body: text("body") } })
        : api.POST("/schools/{school}/lms/materials", { params: { path: { school: code } }, body: { class_id: text("class_id"), subject_id: text("subject_id"), title: text("title"), body: text("body") } });
    });
    setPending(false);
    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    // Materi baru dibuka lagi agar lampiran dapat ditambahkan; yang sudah ada langsung ditutup.
    if (!id && outcome.result.data) {
      onChanged();
      setDetail(outcome.result.data.data);
      return;
    }
    onSaved();
  }

  const current = detail;

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
        <TextAreaField label="Isi materi" name="body" rows={8} defaultValue={current?.body ?? ""} error={failure?.fields.body} hint="Teks biasa; tautan dan ringkasan boleh ditulis langsung." readOnly={readOnly} />
        <div className="flex justify-end gap-3">
          <SecondaryButton type="button" onClick={onCancel}>{readOnly || current ? "Tutup" : "Batal"}</SecondaryButton>
          {!readOnly ? <PrimaryButton type="submit" pending={pending}>{current ? "Simpan" : "Buat materi"}</PrimaryButton> : null}
        </div>
      </form>
      {current ? (
        <AttachmentsPanel code={code} kind="materials" ownerId={current.id} attachments={current.attachments} canManage={current.can_manage} onChanged={() => { void reload(); onChanged(); }} />
      ) : (
        <p className="text-sm text-zinc-500">Lampiran dapat ditambahkan setelah materi dibuat.</p>
      )}
    </div>
  );
}

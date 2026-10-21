"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { Pager } from "@/components/Pager";
import { IconEdit, IconPlus, IconUpload } from "@/components/icons";
import { EmptyState, FormAlert, LinkButton, PageHeader, PrimaryButton, RowButton, SecondaryButton, SelectField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { fetchAll } from "@/lib/fetch-all";
import { useSchool } from "../school-context";

type Teacher = Schemas["Teacher"];
type Member = Schemas["Member"];
type Subject = Schemas["Subject"];
type StatusFilter = Schemas["TeacherStatus"] | "";

type PendingAction = { kind: "create" } | { kind: "edit"; teacher: Teacher };

export default function TeachersPage() {
  const { code, can } = useSchool();
  const canManage = can("school.academic.manage");
  const [teachers, setTeachers] = useState<Teacher[] | null>(null);
  const [meta, setMeta] = useState<Schemas["PageMeta"] | null>(null);
  const [status, setStatus] = useState<StatusFilter>("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [rowError, setRowError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [action, setAction] = useState<PendingAction | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/teachers", {
        params: {
          path: { school: code },
          query: { page, ...(status ? { status } : {}), ...(query ? { q: query } : {}) },
        },
      });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setTeachers(data.data);
        setMeta(data.meta);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, page, status, query]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat data daftar
    void load();
  }, [load]);

  async function toggleStatus(teacher: Teacher) {
    setBusyId(teacher.id);
    setRowError(null);
    const next = teacher.status === "active" ? "inactive" : "active";
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.PATCH("/schools/{school}/teachers/{teacher}", {
        params: { path: { school: code, teacher: teacher.id } },
        body: { status: next },
      });
    });
    setBusyId(null);

    if (!outcome.ok) {
      setRowError(outcome.failure.message);
      return;
    }
    const updated = outcome.result.data?.data;
    if (updated) setTeachers((current) => (current ?? []).map((t) => (t.id === updated.id ? updated : t)));
  }

  return (
    <>
      <PageHeader
        title="Guru"
        description="Data guru beserta mapel yang diampu. Impor dari file untuk memasukkan banyak guru sekaligus."
        actions={
          canManage ? (
            <div className="flex flex-wrap items-center gap-2">
              <LinkButton href={`/schools/${code}/teachers/import`} icon={<IconUpload width={18} height={18} />}>
                Impor guru
              </LinkButton>
              <PrimaryButton icon={<IconPlus width={18} height={18} />} onClick={() => setAction({ kind: "create" })}>
                Tambah guru
              </PrimaryButton>
            </div>
          ) : undefined
        }
      />

      <form
        noValidate
        className="flex flex-wrap items-end gap-3"
        onSubmit={(event) => {
          event.preventDefault();
          setQuery(String(new FormData(event.currentTarget).get("q") ?? "").trim());
          setPage(1);
        }}
      >
        <div className="w-64">
          <TextField label="Cari" name="q" placeholder="Nama atau nomor pegawai" />
        </div>
        <div className="w-44">
          <SelectField
            label="Status"
            name="status"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as StatusFilter);
              setPage(1);
            }}
          >
            <option value="">Semua</option>
            <option value="active">Aktif</option>
            <option value="inactive">Nonaktif</option>
          </SelectField>
        </div>
        <SecondaryButton type="submit">Cari</SecondaryButton>
      </form>

      {error ? <FormAlert>{error}</FormAlert> : null}
      {rowError ? <FormAlert>{rowError}</FormAlert> : null}
      {teachers === null && !error ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}
      {teachers && teachers.length === 0 ? (
        <EmptyState
          title="Tidak ada guru"
          description="Belum ada guru yang cocok. Tambahkan guru, atau impor dari file CSV/XLSX."
        />
      ) : null}

      {teachers && teachers.length > 0 ? (
        <div className="overflow-x-auto rounded-2xl border border-zinc-200/70 bg-white shadow-card dark:border-zinc-800 dark:bg-zinc-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-zinc-200 bg-zinc-50/70 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400">
              <tr>
                <th scope="col" className="px-4 py-3 font-semibold">Nama</th>
                <th scope="col" className="px-4 py-3 font-semibold">Nomor pegawai</th>
                <th scope="col" className="px-4 py-3 font-semibold">Mapel</th>
                <th scope="col" className="px-4 py-3 font-semibold">Akun login</th>
                <th scope="col" className="px-4 py-3 font-semibold">Status</th>
                {canManage ? <th scope="col" className="px-4 py-3 font-semibold">Aksi</th> : null}
              </tr>
            </thead>
            <tbody>
              {teachers.map((teacher) => (
                <tr key={teacher.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3 font-semibold text-zinc-900 dark:text-zinc-100">{teacher.full_name}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{teacher.employee_number ?? "-"}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {teacher.subjects.length > 0 ? teacher.subjects.map((s) => s.name).join(", ") : "-"}
                  </td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{teacher.account?.email ?? "Tanpa akun"}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {teacher.status === "active" ? "Aktif" : "Nonaktif"}
                  </td>
                  {canManage ? (
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap items-center gap-1.5">
                        <RowButton icon={<IconEdit width={14} height={14} />}
                          onClick={() => setAction({ kind: "edit", teacher })}>
                          Ubah
                        </RowButton>
                        <RowButton
                          disabled={busyId === teacher.id}
                          onClick={() => void toggleStatus(teacher)}>
                          {teacher.status === "active" ? "Nonaktifkan" : "Aktifkan"}
                        </RowButton>
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
        open={action !== null}
        title={action?.kind === "edit" ? "Ubah guru" : "Tambah guru"}
        onClose={() => setAction(null)}
      >
        {action ? (
          <TeacherForm
            key={action.kind === "create" ? "create" : action.teacher.id}
            action={action}
            code={code}
            canListMembers={can("school.members.view")}
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

function TeacherForm({
  action,
  code,
  canListMembers,
  onCancel,
  onDone,
}: {
  action: PendingAction;
  code: string;
  canListMembers: boolean;
  onCancel: () => void;
  onDone: () => void;
}) {
  const teacher = action.kind === "edit" ? action.teacher : null;
  const [members, setMembers] = useState<Member[]>([]);
  const [membershipId, setMembershipId] = useState(teacher?.account?.membership_id ?? "");
  const [subjects, setSubjects] = useState<Subject[] | null>(null);
  const [subjectIds, setSubjectIds] = useState<string[]>(teacher?.subjects.map((s) => s.id) ?? []);
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  useEffect(() => {
    if (!canListMembers) return;
    let cancelled = false;
    fetchAll(async (page) => {
      const { data, error } = await api.GET("/schools/{school}/members", {
        params: { path: { school: code }, query: { page } },
      });
      if (error !== undefined) throw error;
      return data;
    })
      .then((all) => {
        if (!cancelled) setMembers(all.filter((m) => m.status !== "ended"));
      })
      .catch(() => {
        // Daftar akun hanya pelengkap: tanpa itu guru tetap bisa disimpan tanpa tautan akun.
      });
    return () => {
      cancelled = true;
    };
  }, [canListMembers, code]);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/schools/{school}/subjects", { params: { path: { school: code } } })
      .then(({ data }) => {
        if (!cancelled && data) setSubjects(data.data);
      })
      .catch(() => {
        // Tanpa daftar mapel, guru tetap bisa disimpan; mapelnya tidak diubah.
      });
    return () => {
      cancelled = true;
    };
  }, [code]);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const body = {
      full_name: String(form.get("full_name") ?? "").trim(),
      employee_number: String(form.get("employee_number") ?? "").trim() || null,
      email: String(form.get("email") ?? "").trim() || null,
      // Hanya dikirim bila daftar mapel berhasil dimuat, agar mapel guru tidak terhapus tanpa sengaja.
      ...(subjects !== null ? { subject_ids: subjectIds } : {}),
      ...(canListMembers ? { membership_id: membershipId || null } : {}),
    };
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return teacher
        ? api.PATCH("/schools/{school}/teachers/{teacher}", {
            params: { path: { school: code, teacher: teacher.id } },
            body,
          })
        : api.POST("/schools/{school}/teachers", { params: { path: { school: code } }, body });
    });
    setPending(false);

    if (!outcome.ok) setFailure(outcome.failure);
    else onDone();
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <TextField label="Nama lengkap" name="full_name" defaultValue={teacher?.full_name} error={failure?.fields.full_name} required />
      <TextField
        label="Nomor pegawai (NIP/NUPTK)"
        name="employee_number"
        defaultValue={teacher?.employee_number ?? ""}
        error={failure?.fields.employee_number}
      />
      <TextField label="Email kontak (opsional)" name="email" type="email" defaultValue={teacher?.email ?? ""} error={failure?.fields.email} />
      {subjects !== null && subjects.length > 0 ? (
        <fieldset>
          <legend className="block text-sm font-semibold text-zinc-800 dark:text-zinc-200">Mapel yang diampu</legend>
          <div className="mt-2 grid max-h-40 grid-cols-2 gap-x-3 gap-y-1.5 overflow-y-auto">
            {subjects.map((subject) => (
              <label key={subject.id} className="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                <input
                  type="checkbox"
                  checked={subjectIds.includes(subject.id)}
                  onChange={(event) =>
                    setSubjectIds((current) => (event.target.checked ? [...current, subject.id] : current.filter((id) => id !== subject.id)))
                  }
                />
                {subject.name}
              </label>
            ))}
          </div>
          {failure?.fields.subject_ids ? <p className="mt-1.5 text-sm text-red-700 dark:text-red-400">{failure.fields.subject_ids}</p> : null}
        </fieldset>
      ) : null}
      {canListMembers ? (
        <SelectField
          label="Akun login (opsional)"
          name="membership_id"
          value={membershipId}
          onChange={(event) => setMembershipId(event.target.value)}
          error={failure?.fields.membership_id}
        >
          <option value="">Tanpa akun</option>
          {/* Akun yang sudah tertaut selalu ada sebagai opsi, bahkan sebelum daftar anggota selesai dimuat. */}
          {teacher?.account && !members.some((m) => m.id === teacher.account?.membership_id) ? (
            <option value={teacher.account.membership_id}>
              {teacher.account.name} ({teacher.account.email})
            </option>
          ) : null}
          {members.map((member) => (
            <option key={member.id} value={member.id}>
              {member.user.name} ({member.user.email})
            </option>
          ))}
        </SelectField>
      ) : null}
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

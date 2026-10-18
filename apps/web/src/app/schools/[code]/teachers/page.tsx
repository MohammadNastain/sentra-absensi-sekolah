"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { Pager } from "@/components/Pager";
import { FormAlert, PrimaryButton, SecondaryButton, SelectField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { fetchAll } from "@/lib/fetch-all";
import { useSchool } from "../school-context";

type Teacher = Schemas["Teacher"];
type Member = Schemas["Member"];
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
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">Guru</h1>
        {canManage ? <PrimaryButton onClick={() => setAction({ kind: "create" })}>Tambah guru</PrimaryButton> : null}
      </div>

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
      {teachers && teachers.length === 0 ? <p className="text-zinc-600 dark:text-zinc-400">Tidak ada guru.</p> : null}

      {teachers && teachers.length > 0 ? (
        <div className="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-zinc-200 text-zinc-600 dark:border-zinc-800 dark:text-zinc-400">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">Nama</th>
                <th scope="col" className="px-4 py-3 font-medium">Nomor pegawai</th>
                <th scope="col" className="px-4 py-3 font-medium">Akun login</th>
                <th scope="col" className="px-4 py-3 font-medium">Status</th>
                {canManage ? <th scope="col" className="px-4 py-3 font-medium">Aksi</th> : null}
              </tr>
            </thead>
            <tbody>
              {teachers.map((teacher) => (
                <tr key={teacher.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{teacher.full_name}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{teacher.employee_number ?? "-"}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{teacher.account?.email ?? "Tanpa akun"}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {teacher.status === "active" ? "Aktif" : "Nonaktif"}
                  </td>
                  {canManage ? (
                    <td className="px-4 py-3">
                      <div className="flex gap-3">
                        <button
                          type="button"
                          className="font-medium text-emerald-700 hover:underline dark:text-emerald-400"
                          onClick={() => setAction({ kind: "edit", teacher })}
                        >
                          Ubah
                        </button>
                        <button
                          type="button"
                          disabled={busyId === teacher.id}
                          className="font-medium text-emerald-700 hover:underline disabled:opacity-60 dark:text-emerald-400"
                          onClick={() => void toggleStatus(teacher)}
                        >
                          {teacher.status === "active" ? "Nonaktifkan" : "Aktifkan"}
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

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const body = {
      full_name: String(form.get("full_name") ?? "").trim(),
      employee_number: String(form.get("employee_number") ?? "").trim() || null,
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

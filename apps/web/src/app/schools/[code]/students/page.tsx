"use client";

import type { Schemas } from "@sentra/api-client";
import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { Pager } from "@/components/Pager";
import { FormAlert, PrimaryButton, SecondaryButton, SelectField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { fetchAll } from "@/lib/fetch-all";
import { formatDate } from "@/lib/format";
import { useSchool } from "../school-context";

type Student = Schemas["Student"];
type StudentDetail = Schemas["StudentDetail"];
type SchoolClass = Schemas["SchoolClass"];
type StatusFilter = Schemas["StudentStatus"] | "";

type PendingAction =
  | { kind: "create" }
  | { kind: "edit"; student: Student }
  | { kind: "class"; student: Student };

const STATUS_LABEL: Record<Schemas["StudentStatus"], string> = {
  active: "Aktif",
  inactive: "Nonaktif",
  graduated: "Lulus",
  transferred: "Pindah sekolah",
};

function todayLocal(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

export default function StudentsPage() {
  const { code, can } = useSchool();
  const canManage = can("school.students.manage");
  const [students, setStudents] = useState<Student[] | null>(null);
  const [meta, setMeta] = useState<Schemas["PageMeta"] | null>(null);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [status, setStatus] = useState<StatusFilter>("");
  const [classId, setClassId] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [action, setAction] = useState<PendingAction | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/students", {
        params: {
          path: { school: code },
          query: {
            page,
            ...(status ? { status } : {}),
            ...(classId ? { class_id: classId } : {}),
            ...(query ? { q: query } : {}),
          },
        },
      });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setStudents(data.data);
        setMeta(data.meta);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, page, status, classId, query]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat data daftar
    void load();
  }, [load]);

  // Kelas aktif dipakai untuk filter dan untuk penempatan. Boleh gagal: daftar siswa tetap tampil.
  // Peran tanpa izin melihat data akademik tidak memuatnya (filter kelas disembunyikan).
  const canListClasses = can("school.academic.view");
  useEffect(() => {
    if (!canListClasses) return;
    let cancelled = false;
    fetchAll(async (p) => {
      const { data, error: problem } = await api.GET("/schools/{school}/classes", {
        params: { path: { school: code }, query: { page: p, status: "active" } },
      });
      if (problem !== undefined) throw problem;
      return data;
    })
      .then((all) => {
        if (!cancelled) setClasses(all);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [canListClasses, code]);

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">Siswa</h1>
        {canManage ? (
          <div className="flex items-center gap-3">
            <Link
              href={`/schools/${code}/students/import`}
              className="text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-400"
            >
              Impor dari file
            </Link>
            <PrimaryButton onClick={() => setAction({ kind: "create" })}>Tambah siswa</PrimaryButton>
          </div>
        ) : null}
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
          <TextField label="Cari" name="q" placeholder="Nama, nomor induk, atau NISN" />
        </div>
        {canListClasses ? (
          <div className="w-44">
            <SelectField
              label="Kelas"
              name="class_filter"
              value={classId}
              onChange={(event) => {
                setClassId(event.target.value);
                setPage(1);
              }}
            >
              <option value="">Semua</option>
              {classes.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} ({c.academic_year.name})
                </option>
              ))}
            </SelectField>
          </div>
        ) : null}
        <div className="w-44">
          <SelectField
            label="Status"
            name="status_filter"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as StatusFilter);
              setPage(1);
            }}
          >
            <option value="">Semua</option>
            {(Object.keys(STATUS_LABEL) as Schemas["StudentStatus"][]).map((s) => (
              <option key={s} value={s}>
                {STATUS_LABEL[s]}
              </option>
            ))}
          </SelectField>
        </div>
        <SecondaryButton type="submit">Cari</SecondaryButton>
      </form>

      {error ? <FormAlert>{error}</FormAlert> : null}
      {students === null && !error ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}
      {students && students.length === 0 ? <p className="text-zinc-600 dark:text-zinc-400">Tidak ada siswa.</p> : null}

      {students && students.length > 0 ? (
        <div className="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-zinc-200 text-zinc-600 dark:border-zinc-800 dark:text-zinc-400">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">Nama</th>
                <th scope="col" className="px-4 py-3 font-medium">Nomor induk</th>
                <th scope="col" className="px-4 py-3 font-medium">NISN</th>
                <th scope="col" className="px-4 py-3 font-medium">Kelas</th>
                <th scope="col" className="px-4 py-3 font-medium">Status</th>
                <th scope="col" className="px-4 py-3 font-medium">Aksi</th>
              </tr>
            </thead>
            <tbody>
              {students.map((student) => (
                <tr key={student.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{student.full_name}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{student.student_number}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{student.nisn ?? "-"}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{student.current_class?.name ?? "Belum ada kelas"}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{STATUS_LABEL[student.status]}</td>
                  <td className="px-4 py-3">
                    <div className="flex gap-3">
                      {canManage ? (
                        <button
                          type="button"
                          className="font-medium text-emerald-700 hover:underline dark:text-emerald-400"
                          onClick={() => setAction({ kind: "edit", student })}
                        >
                          Ubah
                        </button>
                      ) : null}
                      <button
                        type="button"
                        className="font-medium text-emerald-700 hover:underline dark:text-emerald-400"
                        onClick={() => setAction({ kind: "class", student })}
                      >
                        {canManage ? "Kelas" : "Riwayat kelas"}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {meta ? <Pager meta={meta} onPage={setPage} /> : null}

      <Dialog
        open={action !== null && action.kind !== "class"}
        title={action?.kind === "edit" ? "Ubah siswa" : "Tambah siswa"}
        onClose={() => setAction(null)}
      >
        {action && action.kind !== "class" ? (
          <StudentForm
            key={action.kind === "create" ? "create" : action.student.id}
            student={action.kind === "edit" ? action.student : null}
            code={code}
            onCancel={() => setAction(null)}
            onDone={() => {
              setAction(null);
              void load();
            }}
          />
        ) : null}
      </Dialog>

      <Dialog
        open={action?.kind === "class"}
        title={action?.kind === "class" ? `Kelas — ${action.student.full_name}` : "Kelas"}
        onClose={() => {
          setAction(null);
          void load();
        }}
      >
        {action?.kind === "class" ? (
          <ClassHistory
            key={action.student.id}
            studentId={action.student.id}
            code={code}
            classes={classes}
            canManage={canManage}
            onClose={() => {
              setAction(null);
              void load();
            }}
          />
        ) : null}
      </Dialog>
    </>
  );
}

function StudentForm({
  student,
  code,
  onCancel,
  onDone,
}: {
  student: Student | null;
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
    const common = {
      student_number: text("student_number"),
      full_name: text("full_name"),
      nisn: text("nisn") || null,
      date_of_birth: text("date_of_birth") || null,
    };
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return student
        ? api.PATCH("/schools/{school}/students/{student}", {
            params: { path: { school: code, student: student.id } },
            body: { ...common, status: text("status") as Schemas["StudentStatus"] },
          })
        : api.POST("/schools/{school}/students", { params: { path: { school: code } }, body: common });
    });
    setPending(false);

    if (!outcome.ok) setFailure(outcome.failure);
    else onDone();
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <TextField label="Nama lengkap" name="full_name" defaultValue={student?.full_name} error={failure?.fields.full_name} required />
      <TextField
        label="Nomor induk (NIS)"
        name="student_number"
        defaultValue={student?.student_number}
        error={failure?.fields.student_number}
        required
      />
      <TextField label="NISN (10 digit, opsional)" name="nisn" defaultValue={student?.nisn ?? ""} error={failure?.fields.nisn} inputMode="numeric" />
      <TextField
        label="Tanggal lahir (opsional)"
        name="date_of_birth"
        type="date"
        defaultValue={student?.date_of_birth ?? ""}
        error={failure?.fields.date_of_birth}
      />
      {student ? (
        <SelectField label="Status" name="status" defaultValue={student.status} error={failure?.fields.status}>
          {(Object.keys(STATUS_LABEL) as Schemas["StudentStatus"][]).map((s) => (
            <option key={s} value={s}>
              {STATUS_LABEL[s]}
            </option>
          ))}
        </SelectField>
      ) : null}
      {student ? (
        <p className="text-sm text-zinc-600 dark:text-zinc-400">
          Siswa yang diubah menjadi nonaktif, lulus, atau pindah sekolah otomatis dikeluarkan dari kelasnya. Datanya tetap tersimpan.
        </p>
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

function ClassHistory({
  studentId,
  code,
  classes,
  canManage,
  onClose,
}: {
  studentId: string;
  code: string;
  classes: SchoolClass[];
  canManage: boolean;
  onClose: () => void;
}) {
  const [detail, setDetail] = useState<StudentDetail | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/schools/{school}/students/{student}", { params: { path: { school: code, student: studentId } } })
      .then(({ data, error }) => {
        if (cancelled) return;
        if (error !== undefined) setLoadError(toFormFailure(error).message);
        else if (data) setDetail(data.data);
      })
      .catch((e) => {
        if (!cancelled) setLoadError(toFormFailure(e).message);
      });
    return () => {
      cancelled = true;
    };
  }, [code, studentId]);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/schools/{school}/students/{student}/enrollments", {
        params: { path: { school: code, student: studentId } },
        body: { class_id: String(form.get("class_id") ?? ""), start_date: String(form.get("start_date") ?? "") },
      });
    });
    setPending(false);

    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    const updated = outcome.result.data?.data;
    if (updated) setDetail(updated);
  }

  const canEnroll = canManage && detail?.status === "active";

  return (
    <div className="space-y-4">
      {loadError ? <FormAlert>{loadError}</FormAlert> : null}
      {detail === null && !loadError ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}

      {detail ? (
        detail.enrollments.length === 0 ? (
          <p className="text-zinc-600 dark:text-zinc-400">Siswa ini belum pernah ditempatkan di kelas.</p>
        ) : (
          <ul className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-800 dark:border-zinc-800">
            {detail.enrollments.map((e) => (
              <li key={e.id} className="flex flex-wrap justify-between gap-2 px-3 py-2">
                <span className="font-medium text-zinc-900 dark:text-zinc-100">
                  {e.class.name} <span className="font-normal text-zinc-600 dark:text-zinc-400">({e.class.academic_year})</span>
                </span>
                <span className="text-zinc-700 dark:text-zinc-300">
                  {formatDate(e.start_date)} – {e.end_date ? formatDate(e.end_date) : "sekarang"}
                </span>
              </li>
            ))}
          </ul>
        )
      ) : null}

      {canEnroll ? (
        <form onSubmit={submit} noValidate className="space-y-4 border-t border-zinc-200 pt-4 dark:border-zinc-800">
          <h3 className="font-medium text-zinc-900 dark:text-zinc-100">
            {detail?.current_class ? "Pindahkan ke kelas lain" : "Tempatkan di kelas"}
          </h3>
          {failure ? <FormAlert>{failure.message}</FormAlert> : null}
          <SelectField label="Kelas tujuan" name="class_id" defaultValue="" error={failure?.fields.class_id} required>
            <option value="" disabled>
              Pilih kelas
            </option>
            {classes.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name} ({c.academic_year.name})
              </option>
            ))}
          </SelectField>
          <TextField label="Mulai tanggal" name="start_date" type="date" defaultValue={todayLocal()} error={failure?.fields.start_date} required />
          <div className="flex justify-end gap-2">
            <SecondaryButton type="button" onClick={onClose}>
              Tutup
            </SecondaryButton>
            <PrimaryButton type="submit" pending={pending}>
              Simpan
            </PrimaryButton>
          </div>
        </form>
      ) : (
        <div className="flex justify-end">
          <SecondaryButton type="button" onClick={onClose}>
            Tutup
          </SecondaryButton>
        </div>
      )}
    </div>
  );
}

"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { Pager } from "@/components/Pager";
import { IconEdit } from "@/components/icons";
import { FormAlert, PrimaryButton, RowButton, SecondaryButton, SelectField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { fetchAll } from "@/lib/fetch-all";
import { useSchool } from "../school-context";
import { ClassCardsPanel } from "./cards-dialog";

type SchoolClass = Schemas["SchoolClass"];
type AcademicYear = Schemas["AcademicYear"];
type Teacher = Schemas["Teacher"];
type StatusFilter = Schemas["ClassStatus"] | "";

type PendingAction = { kind: "create" } | { kind: "edit"; schoolClass: SchoolClass } | { kind: "cards"; schoolClass: SchoolClass };

const GRADES = Array.from({ length: 13 }, (_, i) => i + 1);

export default function ClassesPage() {
  const { code, can } = useSchool();
  const canManage = can("school.academic.manage");
  const canCards = can("school.credentials.manage");
  const [classes, setClasses] = useState<SchoolClass[] | null>(null);
  const [meta, setMeta] = useState<Schemas["PageMeta"] | null>(null);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [yearId, setYearId] = useState("");
  const [grade, setGrade] = useState("");
  const [status, setStatus] = useState<StatusFilter>("");
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [rowError, setRowError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [action, setAction] = useState<PendingAction | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/classes", {
        params: {
          path: { school: code },
          query: {
            page,
            ...(yearId ? { academic_year_id: yearId } : {}),
            ...(grade ? { grade_level: Number(grade) } : {}),
            ...(status ? { status } : {}),
          },
        },
      });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setClasses(data.data);
        setMeta(data.meta);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, page, yearId, grade, status]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat data daftar
    void load();
  }, [load]);

  useEffect(() => {
    let cancelled = false;
    fetchAll(async (p) => {
      const { data, error: problem } = await api.GET("/schools/{school}/academic-years", {
        params: { path: { school: code }, query: { page: p } },
      });
      if (problem !== undefined) throw problem;
      return data;
    })
      .then((all) => {
        if (!cancelled) setYears(all);
      })
      .catch(() => {
        // Filter tahun ajaran hanya pelengkap; daftar kelas tetap tampil tanpanya.
      });
    return () => {
      cancelled = true;
    };
  }, [code]);

  async function toggleArchive(schoolClass: SchoolClass) {
    setBusyId(schoolClass.id);
    setRowError(null);
    const next = schoolClass.status === "active" ? "archived" : "active";
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.PATCH("/schools/{school}/classes/{schoolClass}", {
        params: { path: { school: code, schoolClass: schoolClass.id } },
        body: { status: next },
      });
    });
    setBusyId(null);

    if (!outcome.ok) {
      setRowError(outcome.failure.message);
      return;
    }
    const updated = outcome.result.data?.data;
    if (updated) setClasses((current) => (current ?? []).map((c) => (c.id === updated.id ? updated : c)));
  }

  const resetPage = <T,>(set: (value: T) => void) => (value: T) => {
    set(value);
    setPage(1);
  };

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Kelas</h1>
        {canManage ? <PrimaryButton onClick={() => setAction({ kind: "create" })}>Tambah kelas</PrimaryButton> : null}
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <div className="w-48">
          <SelectField label="Tahun ajaran" name="year" value={yearId} onChange={(e) => resetPage(setYearId)(e.target.value)}>
            <option value="">Semua</option>
            {years.map((year) => (
              <option key={year.id} value={year.id}>
                {year.name}
                {year.is_active ? " (aktif)" : ""}
              </option>
            ))}
          </SelectField>
        </div>
        <div className="w-36">
          <SelectField label="Tingkat" name="grade" value={grade} onChange={(e) => resetPage(setGrade)(e.target.value)}>
            <option value="">Semua</option>
            {GRADES.map((g) => (
              <option key={g} value={g}>
                {g}
              </option>
            ))}
          </SelectField>
        </div>
        <div className="w-40">
          <SelectField
            label="Status"
            name="status"
            value={status}
            onChange={(e) => resetPage(setStatus)(e.target.value as StatusFilter)}
          >
            <option value="">Semua</option>
            <option value="active">Aktif</option>
            <option value="archived">Diarsipkan</option>
          </SelectField>
        </div>
      </div>

      {error ? <FormAlert>{error}</FormAlert> : null}
      {rowError ? <FormAlert>{rowError}</FormAlert> : null}
      {classes === null && !error ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}
      {classes && classes.length === 0 ? <p className="text-zinc-600 dark:text-zinc-400">Belum ada kelas.</p> : null}

      {classes && classes.length > 0 ? (
        <div className="overflow-x-auto rounded-2xl border border-zinc-200/70 bg-white shadow-card dark:border-zinc-800 dark:bg-zinc-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-zinc-200 bg-zinc-50/70 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400">
              <tr>
                <th scope="col" className="px-4 py-3 font-semibold">Kelas</th>
                <th scope="col" className="px-4 py-3 font-semibold">Tingkat</th>
                <th scope="col" className="px-4 py-3 font-semibold">Tahun ajaran</th>
                <th scope="col" className="px-4 py-3 font-semibold">Wali kelas</th>
                <th scope="col" className="px-4 py-3 font-semibold">Status</th>
                {canManage ? <th scope="col" className="px-4 py-3 font-semibold">Aksi</th> : null}
              </tr>
            </thead>
            <tbody>
              {classes.map((schoolClass) => (
                <tr key={schoolClass.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3 font-semibold text-zinc-900 dark:text-zinc-100">{schoolClass.name}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{schoolClass.grade_level}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{schoolClass.academic_year.name}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {schoolClass.homeroom_teacher?.full_name ?? "Belum ditentukan"}
                  </td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {schoolClass.status === "active" ? "Aktif" : "Diarsipkan"}
                  </td>
                  {canManage ? (
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap items-center gap-1.5">
                        <RowButton icon={<IconEdit width={14} height={14} />}
                          onClick={() => setAction({ kind: "edit", schoolClass })}>
                          Ubah
                        </RowButton>
                        <RowButton
                          disabled={busyId === schoolClass.id}
                          onClick={() => void toggleArchive(schoolClass)}>
                          {schoolClass.status === "active" ? "Arsipkan" : "Aktifkan"}
                        </RowButton>
                        {canCards && schoolClass.status === "active" ? (
                          <RowButton
                            onClick={() => setAction({ kind: "cards", schoolClass })}>
                            Cetak kartu QR
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

      {meta ? <Pager meta={meta} onPage={setPage} /> : null}

      <Dialog open={action?.kind === "cards"} title="Cetak kartu QR kelas" onClose={() => setAction(null)}>
        {action?.kind === "cards" ? (
          <ClassCardsPanel key={action.schoolClass.id} classId={action.schoolClass.id} className={action.schoolClass.name} onClose={() => setAction(null)} />
        ) : null}
      </Dialog>

      <Dialog
        open={action !== null && action.kind !== "cards"}
        title={action?.kind === "edit" ? "Ubah kelas" : "Tambah kelas"}
        onClose={() => setAction(null)}
      >
        {action && action.kind !== "cards" ? (
          <ClassForm
            key={action.kind === "create" ? "create" : action.schoolClass.id}
            action={action}
            code={code}
            years={years}
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

function ClassForm({
  action,
  code,
  years,
  onCancel,
  onDone,
}: {
  action: Exclude<PendingAction, { kind: "cards" }>;
  code: string;
  years: AcademicYear[];
  onCancel: () => void;
  onDone: () => void;
}) {
  const current = action.kind === "edit" ? action.schoolClass : null;
  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [homeroomId, setHomeroomId] = useState(current?.homeroom_teacher?.id ?? "");
  const [chosenYearId, setChosenYearId] = useState("");
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetchAll(async (page) => {
      const { data, error } = await api.GET("/schools/{school}/teachers", {
        params: { path: { school: code }, query: { page, status: "active" } },
      });
      if (error !== undefined) throw error;
      return data;
    })
      .then((all) => {
        if (!cancelled) setTeachers(all);
      })
      .catch(() => {
        // Wali kelas bersifat opsional; tanpa daftar guru kelas tetap bisa disimpan.
      });
    return () => {
      cancelled = true;
    };
  }, [code]);

  // Tahun ajaran yang dipilih user; bila belum memilih, tahun aktif (daftar tahun bisa baru selesai dimuat setelah dialog terbuka).
  const selectedYearId = chosenYearId || years.find((y) => y.is_active)?.id || "";

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const common = {
      name: String(form.get("name") ?? "").trim(),
      grade_level: Number(form.get("grade_level")),
      homeroom_teacher_id: homeroomId || null,
    };
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return current
        ? api.PATCH("/schools/{school}/classes/{schoolClass}", {
            params: { path: { school: code, schoolClass: current.id } },
            body: common,
          })
        : api.POST("/schools/{school}/classes", {
            params: { path: { school: code } },
            body: { ...common, academic_year_id: selectedYearId },
          });
    });
    setPending(false);

    if (!outcome.ok) setFailure(outcome.failure);
    else onDone();
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      {current ? (
        <p className="text-sm text-zinc-600 dark:text-zinc-400">Tahun ajaran: {current.academic_year.name}</p>
      ) : (
        <SelectField
          label="Tahun ajaran"
          name="academic_year_id"
          value={selectedYearId}
          onChange={(event) => setChosenYearId(event.target.value)}
          error={failure?.fields.academic_year_id}
          required
        >
          <option value="" disabled>
            {years.length === 0 ? "Memuat tahun ajaran…" : "Pilih tahun ajaran"}
          </option>
          {years.map((year) => (
            <option key={year.id} value={year.id}>
              {year.name}
              {year.is_active ? " (aktif)" : ""}
            </option>
          ))}
        </SelectField>
      )}
      <TextField label="Nama kelas" name="name" defaultValue={current?.name} placeholder="7A" error={failure?.fields.name} required />
      <SelectField
        label="Tingkat"
        name="grade_level"
        defaultValue={current?.grade_level ?? ""}
        error={failure?.fields.grade_level}
        required
      >
        <option value="" disabled>
          Pilih tingkat
        </option>
        {GRADES.map((g) => (
          <option key={g} value={g}>
            {g}
          </option>
        ))}
      </SelectField>
      <SelectField
        label="Wali kelas (opsional)"
        name="homeroom_teacher_id"
        value={homeroomId}
        onChange={(event) => setHomeroomId(event.target.value)}
        error={failure?.fields.homeroom_teacher_id}
      >
        <option value="">Belum ditentukan</option>
        {/* Wali kelas saat ini selalu ada sebagai opsi, bahkan sebelum daftar guru selesai dimuat. */}
        {current?.homeroom_teacher && !teachers.some((t) => t.id === current.homeroom_teacher?.id) ? (
          <option value={current.homeroom_teacher.id}>{current.homeroom_teacher.full_name}</option>
        ) : null}
        {teachers.map((teacher) => (
          <option key={teacher.id} value={teacher.id}>
            {teacher.full_name}
          </option>
        ))}
      </SelectField>
      <div className="flex justify-end gap-2">
        <SecondaryButton type="button" onClick={onCancel}>
          Batal
        </SecondaryButton>
        <PrimaryButton type="submit" pending={pending} disabled={!current && years.length === 0}>
          Simpan
        </PrimaryButton>
      </div>
    </form>
  );
}

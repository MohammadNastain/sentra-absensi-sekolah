"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { IconBook, IconCalendar, IconEdit, IconPlus, IconTrash, IconUpload } from "@/components/icons";
import { EmptyState, FormAlert, LinkButton, PageHeader, PrimaryButton, RowActions, RowButton, SecondaryButton, SelectField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { fetchAll } from "@/lib/fetch-all";
import { useSchool } from "../school-context";

type Slot = Schemas["TeachingSlot"];
type SchoolClass = Schemas["SchoolClass"];
type Teacher = Schemas["Teacher"];
type Subject = Schemas["Subject"];
type ViewMode = "class" | "teacher";

const DAYS: { value: number; label: string }[] = [
  { value: 1, label: "Senin" },
  { value: 2, label: "Selasa" },
  { value: 3, label: "Rabu" },
  { value: 4, label: "Kamis" },
  { value: 5, label: "Jumat" },
  { value: 6, label: "Sabtu" },
  { value: 7, label: "Minggu" },
];

type PendingAction = { kind: "create" } | { kind: "edit"; slot: Slot } | { kind: "delete"; slot: Slot };

/**
 * Jadwal mengajar: papan mingguan per kelas atau per guru. Admin menambah, mengubah, dan menghapus jam;
 * bentrok (guru atau kelas dobel pada jam beririsan) ditolak server dan pesannya ditampilkan di formulir.
 */
export default function TeachingPage() {
  const { code, can } = useSchool();
  const canManage = can("school.academic.manage");
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [mode, setMode] = useState<ViewMode>("class");
  const [classId, setClassId] = useState("");
  const [teacherId, setTeacherId] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [action, setAction] = useState<PendingAction | null>(null);

  // Daftar rujukan (kelas aktif, guru aktif, mapel): jumlahnya kecil sehingga dimuat sekaligus.
  const loadReferences = useCallback(async () => {
    try {
      const [classList, teacherList, subjectList] = await Promise.all([
        fetchAll(async (page) => {
          const { data, error: problem } = await api.GET("/schools/{school}/classes", {
            params: { path: { school: code }, query: { page, status: "active" } },
          });
          if (problem !== undefined) throw problem;
          return data;
        }),
        fetchAll(async (page) => {
          const { data, error: problem } = await api.GET("/schools/{school}/teachers", {
            params: { path: { school: code }, query: { page, status: "active" } },
          });
          if (problem !== undefined) throw problem;
          return data;
        }),
        api.GET("/schools/{school}/subjects", { params: { path: { school: code } } }).then(({ data, error: problem }) => {
          if (problem !== undefined) throw problem;
          return data?.data ?? [];
        }),
      ]);
      setClasses(classList);
      setTeachers(teacherList);
      setSubjects(subjectList);
      setClassId((current) => current || classList[0]?.id || "");
      setTeacherId((current) => current || teacherList[0]?.id || "");
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat daftar rujukan
    void loadReferences();
  }, [loadReferences]);

  const selectedId = mode === "class" ? classId : teacherId;

  const loadSlots = useCallback(async () => {
    if (!selectedId) {
      setSlots([]);
      return;
    }
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/teaching-slots", {
        params: {
          path: { school: code },
          query: mode === "class" ? { class_id: selectedId } : { teacher_id: selectedId },
        },
      });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setSlots(data.data);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, mode, selectedId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat jadwal sesuai pilihan
    void loadSlots();
  }, [loadSlots]);

  const visibleDays = DAYS.filter((day) => day.value <= 6 || (slots ?? []).some((s) => s.day_of_week === day.value));

  return (
    <>
      <PageHeader
        title="Jadwal mengajar"
        description="Siapa mengajar apa, di kelas mana, hari dan jam berapa. Guru atau kelas tidak boleh dobel pada jam yang beririsan."
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <LinkButton href={`/schools/${code}/teaching/subjects`} variant="soft" icon={<IconBook width={18} height={18} />}>
              Mata pelajaran
            </LinkButton>
            {canManage ? (
              <>
                <LinkButton href={`/schools/${code}/teaching/import`} icon={<IconUpload width={18} height={18} />}>
                  Impor jadwal
                </LinkButton>
                <PrimaryButton icon={<IconPlus width={18} height={18} />} onClick={() => setAction({ kind: "create" })}>
                  Tambah jam
                </PrimaryButton>
              </>
            ) : null}
          </div>
        }
      />

      <div className="flex flex-wrap items-end gap-3">
        <div className="w-44">
          <SelectField label="Lihat per" name="mode" value={mode} onChange={(event) => setMode(event.target.value as ViewMode)}>
            <option value="class">Kelas</option>
            <option value="teacher">Guru</option>
          </SelectField>
        </div>
        <div className="w-64">
          {mode === "class" ? (
            <SelectField label="Kelas" name="class_id" value={classId} onChange={(event) => setClassId(event.target.value)}>
              {classes.length === 0 ? <option value="">Belum ada kelas aktif</option> : null}
              {classes.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </SelectField>
          ) : (
            <SelectField label="Guru" name="teacher_id" value={teacherId} onChange={(event) => setTeacherId(event.target.value)}>
              {teachers.length === 0 ? <option value="">Belum ada guru aktif</option> : null}
              {teachers.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.full_name}
                </option>
              ))}
            </SelectField>
          )}
        </div>
      </div>

      {error ? <FormAlert>{error}</FormAlert> : null}
      {slots === null && !error ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}
      {slots && slots.length === 0 && selectedId ? (
        <EmptyState
          icon={<IconCalendar width={24} height={24} />}
          title={`Belum ada jam mengajar${mode === "class" ? " untuk kelas ini" : " untuk guru ini"}`}
          description="Tambahkan jam satu per satu, atau impor seluruh jadwal dari file CSV/XLSX."
          action={
            canManage ? (
              <div className="flex flex-wrap justify-center gap-2">
                <PrimaryButton icon={<IconPlus width={18} height={18} />} onClick={() => setAction({ kind: "create" })}>
                  Tambah jam
                </PrimaryButton>
                <LinkButton href={`/schools/${code}/teaching/import`} icon={<IconUpload width={18} height={18} />}>
                  Impor jadwal
                </LinkButton>
              </div>
            ) : undefined
          }
        />
      ) : null}

      {slots && slots.length > 0 ? (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {visibleDays.map((day) => {
            const daySlots = slots.filter((s) => s.day_of_week === day.value);
            return (
              <section
                key={day.value}
                aria-label={day.label}
                className="rounded-2xl border border-zinc-200/70 bg-white p-3 shadow-card dark:border-zinc-800 dark:bg-zinc-900"
              >
                <h2 className="mb-2 flex items-center justify-between text-sm font-bold uppercase tracking-wide text-brand-800 dark:text-brand-300">
                  {day.label}
                  <span className="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold normal-case tracking-normal text-brand-700 dark:bg-brand-950/60 dark:text-brand-300">
                    {daySlots.length} jam
                  </span>
                </h2>
                {daySlots.length === 0 ? <p className="text-sm text-zinc-500">Tidak ada jam.</p> : null}
                <ul className="space-y-2">
                  {daySlots.map((slot) => (
                    <li key={slot.id} className="rounded-xl border border-zinc-200/60 border-l-4 border-l-brand-500 bg-zinc-50 p-2.5 dark:border-zinc-800 dark:border-l-brand-400 dark:bg-zinc-950/40">
                      <p className="text-xs font-semibold tabular-nums text-brand-700 dark:text-brand-400">
                        {slot.start_time} – {slot.end_time}
                      </p>
                      <p className="font-semibold text-zinc-900 dark:text-zinc-100">{slot.subject.name}</p>
                      <p className="text-sm text-zinc-600 dark:text-zinc-400">
                        {mode === "class" ? slot.teacher.full_name : `Kelas ${slot.class.name}`}
                      </p>
                      {canManage ? (
                        <div className="mt-2">
                          <RowActions>
                            <RowButton icon={<IconEdit width={14} height={14} />} onClick={() => setAction({ kind: "edit", slot })}>
                              Ubah
                            </RowButton>
                            <RowButton tone="danger" icon={<IconTrash width={14} height={14} />} onClick={() => setAction({ kind: "delete", slot })}>
                              Hapus
                            </RowButton>
                          </RowActions>
                        </div>
                      ) : null}
                    </li>
                  ))}
                </ul>
              </section>
            );
          })}
        </div>
      ) : null}

      <Dialog
        open={action !== null}
        title={action?.kind === "edit" ? "Ubah jam mengajar" : action?.kind === "delete" ? "Hapus jam mengajar" : "Tambah jam mengajar"}
        onClose={() => setAction(null)}
      >
        {action?.kind === "delete" ? (
          <DeleteSlot
            slot={action.slot}
            code={code}
            onCancel={() => setAction(null)}
            onDone={() => {
              setAction(null);
              void loadSlots();
              void loadReferences();
            }}
          />
        ) : action ? (
          <SlotForm
            key={action.kind === "create" ? "create" : action.slot.id}
            slot={action.kind === "edit" ? action.slot : null}
            code={code}
            classes={classes}
            teachers={teachers}
            subjects={subjects}
            defaults={{ classId: mode === "class" ? classId : "", teacherId: mode === "teacher" ? teacherId : "" }}
            onCancel={() => setAction(null)}
            onDone={() => {
              setAction(null);
              void loadSlots();
              void loadReferences();
            }}
          />
        ) : null}
      </Dialog>
    </>
  );
}

function SlotForm({
  slot,
  code,
  classes,
  teachers,
  subjects,
  defaults,
  onCancel,
  onDone,
}: {
  slot: Slot | null;
  code: string;
  classes: SchoolClass[];
  teachers: Teacher[];
  subjects: Subject[];
  defaults: { classId: string; teacherId: string };
  onCancel: () => void;
  onDone: () => void;
}) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const body = {
      class_id: String(form.get("class_id") ?? ""),
      subject_id: String(form.get("subject_id") ?? ""),
      teacher_id: String(form.get("teacher_id") ?? ""),
      day_of_week: Number(form.get("day_of_week")),
      start_time: String(form.get("start_time") ?? ""),
      end_time: String(form.get("end_time") ?? ""),
    };
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return slot
        ? api.PATCH("/schools/{school}/teaching-slots/{slot}", {
            params: { path: { school: code, slot: slot.id } },
            body,
          })
        : api.POST("/schools/{school}/teaching-slots", { params: { path: { school: code } }, body });
    });
    setPending(false);

    if (!outcome.ok) setFailure(outcome.failure);
    else onDone();
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <SelectField label="Kelas" name="class_id" defaultValue={slot?.class.id ?? defaults.classId} error={failure?.fields.class_id} required>
        <option value="">Pilih kelas</option>
        {classes.map((c) => (
          <option key={c.id} value={c.id}>
            {c.name}
          </option>
        ))}
      </SelectField>
      <SelectField
        label="Mata pelajaran"
        name="subject_id"
        defaultValue={slot?.subject.id ?? ""}
        error={failure?.fields.subject_id}
        required
      >
        <option value="">Pilih mapel</option>
        {subjects.map((s) => (
          <option key={s.id} value={s.id}>
            {s.name}
          </option>
        ))}
      </SelectField>
      <SelectField label="Guru" name="teacher_id" defaultValue={slot?.teacher.id ?? defaults.teacherId} error={failure?.fields.teacher_id} required>
        <option value="">Pilih guru</option>
        {teachers.map((t) => (
          <option key={t.id} value={t.id}>
            {t.full_name}
          </option>
        ))}
      </SelectField>
      <SelectField label="Hari" name="day_of_week" defaultValue={String(slot?.day_of_week ?? 1)} error={failure?.fields.day_of_week}>
        {DAYS.map((d) => (
          <option key={d.value} value={d.value}>
            {d.label}
          </option>
        ))}
      </SelectField>
      <div className="grid grid-cols-2 gap-3">
        <TextField label="Jam mulai" name="start_time" type="time" defaultValue={slot?.start_time ?? "07:00"} error={failure?.fields.start_time} required />
        <TextField label="Jam selesai" name="end_time" type="time" defaultValue={slot?.end_time ?? "08:20"} error={failure?.fields.end_time} required />
      </div>
      {subjects.length === 0 ? (
        <p className="text-sm text-zinc-600 dark:text-zinc-400">Belum ada mata pelajaran. Tambahkan dulu di halaman Mata pelajaran.</p>
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

function DeleteSlot({ slot, code, onCancel, onDone }: { slot: Slot; code: string; onCancel: () => void; onDone: () => void }) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function confirm() {
    setPending(true);
    setFailure(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.DELETE("/schools/{school}/teaching-slots/{slot}", { params: { path: { school: code, slot: slot.id } } });
    });
    setPending(false);

    if (!outcome.ok) setFailure(outcome.failure);
    else onDone();
  }

  const day = DAYS.find((d) => d.value === slot.day_of_week)?.label ?? "";
  return (
    <div className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <p className="text-zinc-700 dark:text-zinc-300">
        Hapus {slot.subject.name} kelas {slot.class.name} ({slot.teacher.full_name}), {day} {slot.start_time}–{slot.end_time}?
      </p>
      <div className="flex justify-end gap-2">
        <SecondaryButton type="button" onClick={onCancel}>
          Batal
        </SecondaryButton>
        <PrimaryButton type="button" pending={pending} onClick={() => void confirm()}>
          Hapus
        </PrimaryButton>
      </div>
    </div>
  );
}
